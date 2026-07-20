<?php

declare(strict_types=1);

/**
 * Déclaration des routes de l'application.
 *
 * Renvoie une closure recevant le Router (et le Container si un middleware doit
 * être construit avec des dépendances). Les phases suivantes ajouteront ici les
 * routes du back-office (/admin/*), de l'API (/api/*) et du widget (/widget/*).
 *
 * Exemple d'ajout d'une route protégée :
 *   $router->group('/admin', [AuthMiddleware::class], function (Router $r) {
 *       $r->get('/catalogue', [CatalogController::class, 'index']);
 *   });
 */

use Keepnew\Core\Container;
use Keepnew\Core\Csrf;
use Keepnew\Core\Router;
use Keepnew\Core\Session;
use Keepnew\Http\Controller\HealthController;
use Keepnew\Http\Middleware\CsrfMiddleware;

return static function (Router $router, Container $container): void {
    // Enregistre les middlewares résolubles par nom de classe.
    $container->singleton(
        CsrfMiddleware::class,
        static fn (Container $c): CsrfMiddleware => new CsrfMiddleware($c->get(Csrf::class)),
    );
    $container->singleton(HealthController::class, static fn (): HealthController => new HealthController());

    // --- Santé / démonstration du noyau ------------------------------------
    $router->get('/health', [HealthController::class, 'index']);
    $router->get('/health/echo/{value}', [HealthController::class, 'echo']);

    // --- Emplacements réservés aux phases suivantes ------------------------
    // $router->group('/api', [/* RateLimit */], function (Router $r) { ... });   // Phase 5
    // $router->group('/admin', [AuthMiddleware::class, CsrfMiddleware::class], function (Router $r) { ... }); // Phase 2+
    // $router->group('/widget', [], function (Router $r) { ... });               // Phase 6
};
