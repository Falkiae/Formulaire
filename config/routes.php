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
use Keepnew\Admin\CategoryController;
use Keepnew\Admin\CustomerController;
use Keepnew\Admin\DispatchController;
use Keepnew\Admin\DispatchService;
use Keepnew\Admin\ExtraController;
use Keepnew\Admin\JobController;
use Keepnew\Admin\LocationController;
use Keepnew\Admin\SimulatorController;
use Keepnew\Availability\EngineConfig;
use Keepnew\Auth\UserRepository;
use Keepnew\Catalog\CatalogRepository;
use Keepnew\Catalog\ExtraRepository;
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
use Keepnew\Http\Middleware\RateLimitMiddleware;
use Keepnew\Availability\AvailabilityRepository;
use Keepnew\Availability\AvailabilityService;
use Keepnew\Availability\ZoneResolver;
use Keepnew\Booking\BookingService;
use Keepnew\Booking\CartService;
use Keepnew\Booking\HoldService;
use Keepnew\Geo\CachingGeoProvider;
use Keepnew\Geo\ChainGeoProvider;
use Keepnew\Geo\GeoProviderInterface;
use Keepnew\Geo\HaversineGeoProvider;
use Keepnew\Geo\OpenRouteServiceProvider;
use Keepnew\Geo\PostalMatrixGeoProvider;
use Keepnew\Http\Api\AvailabilityApiController;
use Keepnew\Http\Api\BookingApiController;
use Keepnew\Http\Api\CartApiController;
use Keepnew\Http\Api\CatalogApiController;
use Keepnew\Core\Config;

return static function (Router $router, Container $container): void {
    // --- Services partagés -------------------------------------------------
    $container->singleton(CatalogRepository::class, static fn (Container $c): CatalogRepository => new CatalogRepository($c->get(Database::class)));
    $container->singleton(ExtraRepository::class, static fn (Container $c): ExtraRepository => new ExtraRepository($c->get(Database::class)));
    $container->singleton(UserRepository::class, static fn (Container $c): UserRepository => new UserRepository($c->get(Database::class)));
    $container->singleton(\Keepnew\Pricing\PriceCalculator::class, static fn (): \Keepnew\Pricing\PriceCalculator => new \Keepnew\Pricing\PriceCalculator());
    $container->singleton(\Keepnew\Pricing\CartPricer::class, static fn (Container $c): \Keepnew\Pricing\CartPricer => new \Keepnew\Pricing\CartPricer($c->get(\Keepnew\Pricing\PriceCalculator::class)));
    $container->singleton(\Keepnew\Catalog\LineResolver::class, static fn (Container $c): \Keepnew\Catalog\LineResolver => new \Keepnew\Catalog\LineResolver($c->get(CatalogRepository::class)));
    $container->singleton(SimulatorService::class, static fn (Container $c): SimulatorService => new SimulatorService(
        $c->get(CatalogRepository::class),
        $c->get(\Keepnew\Pricing\PriceCalculator::class),
        $c->get(Database::class),
        $c->get(\Keepnew\Catalog\LineResolver::class),
    ));
    $container->singleton(\Keepnew\Catalog\CartPricingService::class, static fn (Container $c): \Keepnew\Catalog\CartPricingService => new \Keepnew\Catalog\CartPricingService(
        $c->get(Database::class),
        $c->get(\Keepnew\Catalog\LineResolver::class),
        $c->get(\Keepnew\Pricing\CartPricer::class),
    ));

    // --- Geo (fournisseur de trajet : cache(ORS) → matrice CP → haversine) --
    $container->singleton(GeoProviderInterface::class, static function (Container $c): GeoProviderInterface {
        $config = $c->get(Config::class);
        $db = $c->get(Database::class);
        $ors = new CachingGeoProvider($db, new OpenRouteServiceProvider((string) $config->get('geo.ors_api_key', '')));

        return new ChainGeoProvider($ors, new PostalMatrixGeoProvider($db), new HaversineGeoProvider());
    });

    // --- Disponibilité -----------------------------------------------------
    $container->singleton(AvailabilityRepository::class, static fn (Container $c): AvailabilityRepository => new AvailabilityRepository($c->get(Database::class)));
    $container->singleton(ZoneResolver::class, static fn (): ZoneResolver => new ZoneResolver());
    $container->singleton(AvailabilityService::class, static fn (Container $c): AvailabilityService => new AvailabilityService(
        $c->get(AvailabilityRepository::class),
        $c->get(CatalogRepository::class),
        $c->get(\Keepnew\Catalog\LineResolver::class),
        $c->get(\Keepnew\Pricing\PriceCalculator::class),
        $c->get(ZoneResolver::class),
        $c->get(GeoProviderInterface::class),
        $c->get(Database::class),
    ));

    // --- Panier / commande -------------------------------------------------
    $container->singleton(CartService::class, static fn (Container $c): CartService => new CartService(
        $c->get(Database::class),
        $c->get(CatalogRepository::class),
        $c->get(\Keepnew\Catalog\LineResolver::class),
        $c->get(\Keepnew\Pricing\PriceCalculator::class),
        $c->get(\Keepnew\Catalog\CartPricingService::class),
    ));
    $container->singleton(HoldService::class, static fn (Container $c): HoldService => new HoldService($c->get(Database::class)));
    $container->singleton(BookingService::class, static fn (Container $c): BookingService => new BookingService(
        $c->get(Database::class),
        $c->get(CartService::class),
        $c->get(CatalogRepository::class),
        $c->get(\Keepnew\Catalog\CartPricingService::class),
        $c->get(HoldService::class),
    ));

    // --- Contrôleurs API ---------------------------------------------------
    $container->singleton(CatalogApiController::class, static fn (Container $c): CatalogApiController => new CatalogApiController($c->get(CatalogRepository::class)));
    $container->singleton(CartApiController::class, static fn (Container $c): CartApiController => new CartApiController(
        $c->get(CartService::class),
        $c->get(\Keepnew\Catalog\CartPricingService::class),
    ));
    $container->singleton(AvailabilityApiController::class, static fn (Container $c): AvailabilityApiController => new AvailabilityApiController(
        $c->get(CartService::class),
        $c->get(AvailabilityService::class),
        $c->get(AvailabilityRepository::class),
    ));
    $container->singleton(BookingApiController::class, static fn (Container $c): BookingApiController => new BookingApiController($c->get(BookingService::class)));

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
        $c->get(ExtraRepository::class),
    ));
    $container->singleton(CategoryController::class, static fn (Container $c): CategoryController => new CategoryController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(CatalogRepository::class),
    ));
    $container->singleton(ExtraController::class, static fn (Container $c): ExtraController => new ExtraController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(ExtraRepository::class),
    ));

    // --- Dispatch / opérationnel (Phase 7) ---------------------------------
    $container->singleton(EngineConfig::class, static fn (): EngineConfig => new EngineConfig());
    $container->singleton(DispatchService::class, static fn (Container $c): DispatchService => new DispatchService(
        $c->get(Database::class),
        $c->get(GeoProviderInterface::class),
        $c->get(EngineConfig::class),
    ));
    $container->singleton(DispatchController::class, static fn (Container $c): DispatchController => new DispatchController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(DispatchService::class),
    ));
    $container->singleton(JobController::class, static fn (Container $c): JobController => new JobController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(Database::class),
    ));
    $container->singleton(CustomerController::class, static fn (Container $c): CustomerController => new CustomerController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Database::class),
    ));
    $container->singleton(LocationController::class, static fn (Container $c): LocationController => new LocationController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(Database::class),
    ));
    $container->singleton(SimulatorController::class, static fn (Container $c): SimulatorController => new SimulatorController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(CatalogRepository::class),
        $c->get(SimulatorService::class),
        $c->get(\Keepnew\Catalog\CartPricingService::class),
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
        // Catalogue — vue d'ensemble
        $r->get('/catalogue', [CatalogController::class, 'index']);

        // Catégories (CRUD + réordonnancement)
        $r->post('/catalogue/categorie', [CategoryController::class, 'create'], [CsrfMiddleware::class]);
        $r->get('/catalogue/categorie/{id}', [CategoryController::class, 'edit']);
        $r->post('/catalogue/categorie/{id}', [CategoryController::class, 'update'], [CsrfMiddleware::class]);
        $r->post('/catalogue/categorie/{id}/supprimer', [CategoryController::class, 'delete'], [CsrfMiddleware::class]);
        $r->post('/catalogue/categories/ordre', [CategoryController::class, 'reorder'], [CsrfMiddleware::class]);

        // Services (CRUD + duplication + réordonnancement)
        $r->post('/catalogue/service', [CatalogController::class, 'createService'], [CsrfMiddleware::class]);
        $r->get('/catalogue/service/{id}', [CatalogController::class, 'editService']);
        $r->post('/catalogue/service/{id}', [CatalogController::class, 'saveService'], [CsrfMiddleware::class]);
        $r->post('/catalogue/service/{id}/dupliquer', [CatalogController::class, 'duplicateService'], [CsrfMiddleware::class]);
        $r->post('/catalogue/service/{id}/supprimer', [CatalogController::class, 'deleteService'], [CsrfMiddleware::class]);
        $r->post('/catalogue/services/ordre', [CatalogController::class, 'reorderServices'], [CsrfMiddleware::class]);

        // Variantes (CRUD + réordonnancement)
        $r->post('/catalogue/service/{id}/variante', [CatalogController::class, 'createVariant'], [CsrfMiddleware::class]);
        $r->post('/catalogue/service/{id}/variante/{variantId}', [CatalogController::class, 'updateVariant'], [CsrfMiddleware::class]);
        $r->post('/catalogue/service/{id}/variante/{variantId}/supprimer', [CatalogController::class, 'deleteVariant'], [CsrfMiddleware::class]);
        $r->post('/catalogue/service/{id}/variantes/ordre', [CatalogController::class, 'reorderVariants'], [CsrfMiddleware::class]);

        // Extras — catalogue central (CRUD)
        $r->get('/extras', [ExtraController::class, 'index']);
        $r->post('/extras', [ExtraController::class, 'create'], [CsrfMiddleware::class]);
        $r->post('/extras/{id}', [ExtraController::class, 'update'], [CsrfMiddleware::class]);
        $r->post('/extras/{id}/supprimer', [ExtraController::class, 'delete'], [CsrfMiddleware::class]);
        // Extras — rattachement à un service (pivot)
        $r->post('/catalogue/service/{id}/extras', [ExtraController::class, 'attach'], [CsrfMiddleware::class]);
        $r->post('/catalogue/service/{id}/extras/{extraId}/detacher', [ExtraController::class, 'detach'], [CsrfMiddleware::class]);

        // Dispatch & opérationnel
        $r->get('/dispatch', [DispatchController::class, 'index']);
        $r->post('/dispatch/reassign', [DispatchController::class, 'reassign'], [CsrfMiddleware::class]);
        $r->get('/job/{id}', [JobController::class, 'show']);
        $r->post('/job/{id}/statut', [JobController::class, 'updateStatus'], [CsrfMiddleware::class]);
        $r->post('/job/{id}/note', [JobController::class, 'addNote'], [CsrfMiddleware::class]);

        // Clients
        $r->get('/clients', [CustomerController::class, 'index']);
        $r->get('/client/{id}', [CustomerController::class, 'show']);

        // Ateliers
        $r->get('/ateliers', [LocationController::class, 'index']);
        $r->post('/ateliers/{id}/poste', [LocationController::class, 'addBay'], [CsrfMiddleware::class]);
        $r->post('/ateliers/{id}/fermeture', [LocationController::class, 'addClosure'], [CsrfMiddleware::class]);

        // Simulateur
        $r->get('/simulateur', [SimulatorController::class, 'index']);
        $r->get('/simulateur/service/{id}', [SimulatorController::class, 'serviceConfig']);
        $r->post('/simulateur/calcul', [SimulatorController::class, 'calculate'], [CsrfMiddleware::class]);
        $r->post('/simulateur/panier', [SimulatorController::class, 'cart'], [CsrfMiddleware::class]);
    });

    // --- API REST publique (widget) ----------------------------------------
    // Cross-origin par nature : pas de CSRF cookie ; endpoints sensibles
    // protégés par rate limiting. Le panier est authentifié par son token.
    $db = $container->get(Database::class);
    $availabilityLimit = new RateLimitMiddleware($db, 'api_availability', 40, 60);
    $bookingLimit = new RateLimitMiddleware($db, 'api_booking', 15, 60);

    $router->group('/api', [], static function (Router $r) use ($availabilityLimit, $bookingLimit): void {
        // Catalogue (lecture)
        $r->get('/catalog', [CatalogApiController::class, 'index']);
        $r->get('/services/{id}', [CatalogApiController::class, 'service']);

        // Devis live (sans panier)
        $r->post('/quote', [CartApiController::class, 'quote']);

        // Panier
        $r->post('/cart', [CartApiController::class, 'create']);
        $r->get('/cart/{token}', [CartApiController::class, 'show']);
        $r->post('/cart/{token}/items', [CartApiController::class, 'addItem']);
        $r->patch('/cart/{token}/items/{itemId}', [CartApiController::class, 'updateItem']);
        $r->delete('/cart/{token}/items/{itemId}', [CartApiController::class, 'removeItem']);
        $r->post('/cart/{token}/coupon', [CartApiController::class, 'setCoupon']);

        // Disponibilité (rate-limité : anti-énumération de créneaux)
        $r->post('/availability', [AvailabilityApiController::class, 'search'], [$availabilityLimit]);

        // Réservation (rate-limité)
        $r->post('/bookings', [BookingApiController::class, 'create'], [$bookingLimit]);
        $r->get('/bookings/{token}', [BookingApiController::class, 'show']);
        $r->post('/bookings/{token}/schedule', [BookingApiController::class, 'schedule'], [$bookingLimit]);
        $r->post('/bookings/{token}/cancel', [BookingApiController::class, 'cancel']);
    });

    // --- Emplacement réservé à la Phase 6 ----------------------------------
    // $router->group('/widget', [], function (Router $r) { ... });               // Widget public
};
