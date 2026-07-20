<?php

declare(strict_types=1);

/**
 * Déclaration des routes de l'application + enregistrement des services requis.
 *
 * Renvoie une closure recevant le Router et le Container. Les phases suivantes
 * ajouteront ici les routes de l'API (/api/*) et du widget (/widget/*).
 */

use Keepnew\Admin\AuthController;
use Keepnew\Admin\CatalogController;
use Keepnew\Admin\SimulatorController;
use Keepnew\Auth\UserRepository;
use Keepnew\Catalog\CatalogRepository;
use Keepnew\Catalog\SimulatorService;
use Keepnew\Core\Container;
use Keepnew\Core\Csrf;
use Keepnew\Core\Database;
use Keepnew\Core\Router;
use Keepnew\Core\Session;
use Keepnew\Core\View;
use Keepnew\Http\Controller\HealthController;
use Keepnew\Http\Middleware\AuthMiddleware;
use Keepnew\Http\Middleware\CsrfMiddleware;

return static function (Router $router, Container $container): void {
    // --- Services partagés -------------------------------------------------
    $container->singleton(CatalogRepository::class, static fn (Container $c): CatalogRepository => new CatalogRepository($c->get(Database::class)));
    $container->singleton(UserRepository::class, static fn (Container $c): UserRepository => new UserRepository($c->get(Database::class)));
    $container->singleton(\Keepnew\Pricing\PriceCalculator::class, static fn (): \Keepnew\Pricing\PriceCalculator => new \Keepnew\Pricing\PriceCalculator());
    $container->singleton(SimulatorService::class, static fn (Container $c): SimulatorService => new SimulatorService(
        $c->get(CatalogRepository::class),
        $c->get(\Keepnew\Pricing\PriceCalculator::class),
        $c->get(Database::class),
    ));

    // --- Middlewares -------------------------------------------------------
    $container->singleton(CsrfMiddleware::class, static fn (Container $c): CsrfMiddleware => new CsrfMiddleware($c->get(Csrf::class)));
    $container->singleton(AuthMiddleware::class, static fn (Container $c): AuthMiddleware => new AuthMiddleware($c->get(Session::class)));

    // --- Contrôleurs -------------------------------------------------------
    $container->singleton(HealthController::class, static fn (): HealthController => new HealthController());
    $container->singleton(AuthController::class, static fn (Container $c): AuthController => new AuthController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(UserRepository::class),
        $c->get(Database::class),
    ));
    $container->singleton(CatalogController::class, static fn (Container $c): CatalogController => new CatalogController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(CatalogRepository::class),
    ));
    $container->singleton(SimulatorController::class, static fn (Container $c): SimulatorController => new SimulatorController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(CatalogRepository::class),
        $c->get(SimulatorService::class),
    ));

    // --- Santé / démonstration du noyau ------------------------------------
    $router->get('/health', [HealthController::class, 'index']);
    $router->get('/health/echo/{value}', [HealthController::class, 'echo']);

    // --- Authentification (public, CSRF sur le POST) -----------------------
    $router->get('/admin/connexion', [AuthController::class, 'showLogin']);
    $router->post('/admin/connexion', [AuthController::class, 'login'], [CsrfMiddleware::class]);
    $router->get('/admin/deconnexion', [AuthController::class, 'logout']);

    // --- Back-office (authentifié) -----------------------------------------
    $router->group('/admin', [AuthMiddleware::class], static function (Router $r): void {
        $r->get('/catalogue', [CatalogController::class, 'index']);
        $r->get('/catalogue/service/{id}', [CatalogController::class, 'editService']);
        $r->post('/catalogue/service/{id}', [CatalogController::class, 'saveService'], [CsrfMiddleware::class]);

        $r->get('/simulateur', [SimulatorController::class, 'index']);
        $r->get('/simulateur/service/{id}', [SimulatorController::class, 'serviceConfig']);
        $r->post('/simulateur/calcul', [SimulatorController::class, 'calculate'], [CsrfMiddleware::class]);
    });

    // --- Emplacements réservés aux phases suivantes ------------------------
    // $router->group('/api', [/* RateLimit */], function (Router $r) { ... });   // Phase 5
    // $router->group('/widget', [], function (Router $r) { ... });               // Phase 6
};
