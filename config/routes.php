<?php

declare(strict_types=1);

/**
 * Déclaration des routes de l'application + enregistrement des services requis.
 *
 * Renvoie une closure recevant le Router et le Container. Les phases suivantes
 * ajouteront ici les routes de l'API (/api/*) et du widget (/widget/*).
 */

use Keepnew\Admin\AuthController;
use Keepnew\Admin\CalendarController;
use Keepnew\Admin\CatalogController;
use Keepnew\Admin\CategoryController;
use Keepnew\Admin\CustomerController;
use Keepnew\Admin\DispatchController;
use Keepnew\Admin\DispatchService;
use Keepnew\Admin\ExtraController;
use Keepnew\Admin\JobController;
use Keepnew\Admin\LocationController;
use Keepnew\Admin\RescheduleAvailabilityService;
use Keepnew\Admin\SimulatorController;
use Keepnew\Admin\TechnicianController;
use Keepnew\Admin\UserController;
use Keepnew\Admin\ZoneController;
use Keepnew\Availability\EngineConfig;
use Keepnew\Auth\UserRepository;
use Keepnew\Catalog\CatalogRepository;
use Keepnew\Catalog\ExtraRepository;
use Keepnew\Customer\CustomerRepository;
use Keepnew\Catalog\SimulatorService;
use Keepnew\Core\Container;
use Keepnew\Core\Csrf;
use Keepnew\Core\Response;
use Keepnew\Core\Database;
use Keepnew\Core\Router;
use Keepnew\Core\Session;
use Keepnew\Core\View;
use Keepnew\Http\Controller\HealthController;
use Keepnew\Http\Middleware\AuthMiddleware;
use Keepnew\Http\Middleware\CsrfMiddleware;
use Keepnew\Http\Middleware\RateLimitMiddleware;
use Keepnew\Http\Middleware\RoleMiddleware;
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
use Keepnew\Geo\NominatimGeocoder;
use Keepnew\Geo\OpenRouteServiceProvider;
use Keepnew\Geo\PostalMatrixGeoProvider;
use Keepnew\Http\Api\AvailabilityApiController;
use Keepnew\Http\Api\ZoneCheckApiController;
use Keepnew\Http\Api\BookingApiController;
use Keepnew\Http\Api\CartApiController;
use Keepnew\Http\Api\CatalogApiController;
use Keepnew\Http\Api\FormApiController;
use Keepnew\Admin\FormBuilderController;
use Keepnew\Form\FormRepository;
use Keepnew\Form\FormValidator;
use Keepnew\Admin\InvoiceController;
use Keepnew\Invoice\InvoiceService;
use Keepnew\Invoice\JournalExporter;
use Keepnew\Invoice\UblGenerator;
use Keepnew\Notification\BrevoSmsProvider;
use Keepnew\Notification\LogMailer;
use Keepnew\Notification\MailerInterface;
use Keepnew\Notification\NotificationService;
use Keepnew\Notification\NullSmsProvider;
use Keepnew\Notification\SmsProviderInterface;
use Keepnew\Notification\SmtpMailer;
use Keepnew\Notification\TemplateRenderer;
use Keepnew\Notification\TwilioSmsProvider;
use Keepnew\Tech\TechController;
use Keepnew\Tech\TimeEntryService;
use Keepnew\Technician\TechnicianRepository;
use Keepnew\Zone\ZoneRepository;
use Keepnew\Support\ImageUpload;
use Keepnew\Admin\ReportController;
use Keepnew\Tracking\MetaCapiClient;
use Keepnew\Tracking\TrackingService;
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
    $container->singleton(NominatimGeocoder::class, static fn (): NominatimGeocoder => new NominatimGeocoder());
    $container->singleton(BookingService::class, static fn (Container $c): BookingService => new BookingService(
        $c->get(Database::class),
        $c->get(CartService::class),
        $c->get(CatalogRepository::class),
        $c->get(\Keepnew\Catalog\CartPricingService::class),
        $c->get(HoldService::class),
        $c->get(FormRepository::class),
        $c->get(NominatimGeocoder::class),
    ));

    // --- Contrôleurs API ---------------------------------------------------
    $container->singleton(CatalogApiController::class, static fn (Container $c): CatalogApiController => new CatalogApiController(
        $c->get(CatalogRepository::class),
        $c->get(Database::class),
    ));
    $container->singleton(CartApiController::class, static fn (Container $c): CartApiController => new CartApiController(
        $c->get(CartService::class),
        $c->get(\Keepnew\Catalog\CartPricingService::class),
    ));
    $container->singleton(AvailabilityApiController::class, static fn (Container $c): AvailabilityApiController => new AvailabilityApiController(
        $c->get(CartService::class),
        $c->get(AvailabilityService::class),
        $c->get(AvailabilityRepository::class),
    ));
    $container->singleton(ZoneCheckApiController::class, static fn (Container $c): ZoneCheckApiController => new ZoneCheckApiController(
        $c->get(AvailabilityRepository::class),
        $c->get(ZoneResolver::class),
    ));
    $container->singleton(FormRepository::class, static fn (Container $c): FormRepository => new FormRepository($c->get(Database::class)));
    $container->singleton(FormValidator::class, static fn (): FormValidator => new FormValidator());

    // --- Notifications (Phase 9) -------------------------------------------
    $container->singleton(TemplateRenderer::class, static fn (): TemplateRenderer => new TemplateRenderer());
    $container->singleton(MailerInterface::class, static function (Container $c): MailerInterface {
        $cfg = $c->get(Config::class);
        // SMTP si configuré ET PHPMailer présent, sinon LogMailer.
        if ((string) $cfg->get('mail.host', '') !== '' && class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            return new SmtpMailer([
                'host' => (string) $cfg->get('mail.host'),
                'port' => (int) $cfg->get('mail.port', 587),
                'user' => (string) $cfg->get('mail.user', ''),
                'password' => (string) $cfg->get('mail.password', ''),
                'encryption' => (string) $cfg->get('mail.encryption', 'tls'),
                'from_address' => (string) $cfg->get('mail.from_address', 'hello@keepnew.be'),
                'from_name' => (string) $cfg->get('mail.from_name', 'Keepnew'),
            ]);
        }

        return new LogMailer(dirname(__DIR__) . '/storage/logs');
    });
    $container->singleton(SmsProviderInterface::class, static function (Container $c): SmsProviderInterface {
        $cfg = $c->get(Config::class);
        return match ((string) $cfg->get('sms.provider', 'none')) {
            'twilio' => new TwilioSmsProvider((string) $cfg->get('sms.twilio_sid', ''), (string) $cfg->get('sms.twilio_token', ''), (string) $cfg->get('sms.twilio_from', '')),
            'brevo' => new BrevoSmsProvider((string) $cfg->get('sms.brevo_key', ''), (string) $cfg->get('sms.brevo_sender', 'Keepnew')),
            default => new NullSmsProvider(),
        };
    });
    $container->singleton(NotificationService::class, static fn (Container $c): NotificationService => new NotificationService(
        $c->get(Database::class),
        $c->get(TemplateRenderer::class),
        $c->get(MailerInterface::class),
        $c->get(SmsProviderInterface::class),
    ));

    // --- Facturation & Peppol ----------------------------------------------
    $container->singleton(InvoiceService::class, static fn (Container $c): InvoiceService => new InvoiceService($c->get(Database::class)));
    $container->singleton(UblGenerator::class, static fn (): UblGenerator => new UblGenerator());
    $container->singleton(JournalExporter::class, static fn (Container $c): JournalExporter => new JournalExporter($c->get(Database::class)));
    $container->singleton(TimeEntryService::class, static fn (Container $c): TimeEntryService => new TimeEntryService($c->get(Database::class)));
    $container->singleton(ImageUpload::class, static fn (): ImageUpload => new ImageUpload(dirname(__DIR__) . '/storage/uploads'));
    $container->singleton(InvoiceController::class, static fn (Container $c): InvoiceController => new InvoiceController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(Database::class),
        $c->get(InvoiceService::class),
        $c->get(UblGenerator::class),
        $c->get(JournalExporter::class),
        $c->get(TimeEntryService::class),
    ));
    $container->singleton(TechController::class, static fn (Container $c): TechController => new TechController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(Database::class),
        $c->get(TimeEntryService::class),
        $c->get(NotificationService::class),
        $c->get(ImageUpload::class),
    ));
    $container->singleton(BookingApiController::class, static fn (Container $c): BookingApiController => new BookingApiController(
        $c->get(BookingService::class),
        $c->get(CartService::class),
        $c->get(FormRepository::class),
        $c->get(FormValidator::class),
        $c->get(NotificationService::class),
        $c->get(TrackingService::class),
    ));
    $container->singleton(FormApiController::class, static fn (Container $c): FormApiController => new FormApiController(
        $c->get(FormRepository::class),
        $c->get(CartService::class),
    ));

    // --- Tracking (Phase 11) -----------------------------------------------
    $container->singleton(MetaCapiClient::class, static function (Container $c): MetaCapiClient {
        $cfg = $c->get(Config::class);
        return new MetaCapiClient((string) $cfg->get('tracking.meta_pixel_id', ''), (string) $cfg->get('tracking.meta_token', ''));
    });
    $container->singleton(TrackingService::class, static fn (Container $c): TrackingService => new TrackingService($c->get(Database::class), $c->get(MetaCapiClient::class)));
    $container->singleton(ReportController::class, static fn (Container $c): ReportController => new ReportController($c->get(View::class), $c->get(Session::class), $c->get(Database::class)));
    $container->singleton(FormBuilderController::class, static fn (Container $c): FormBuilderController => new FormBuilderController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(FormRepository::class),
        $c->get(CatalogRepository::class),
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
    // Uploader d'images du catalogue : cible le webroot (public/uploads) car
    // ces images sont publiques (affichées dans le widget), contrairement aux
    // photos privées de l'app technicien (ImageUpload::class → storage/uploads).
    $catalogImages = new ImageUpload(dirname(__DIR__) . '/public/uploads');
    $container->singleton(CatalogController::class, static fn (Container $c): CatalogController => new CatalogController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(CatalogRepository::class),
        $c->get(ExtraRepository::class),
        $catalogImages,
    ));
    $container->singleton(CategoryController::class, static fn (Container $c): CategoryController => new CategoryController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(CatalogRepository::class),
        $catalogImages,
    ));
    $container->singleton(ExtraController::class, static fn (Container $c): ExtraController => new ExtraController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(ExtraRepository::class),
        $catalogImages,
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
    $container->singleton(RescheduleAvailabilityService::class, static fn (Container $c): RescheduleAvailabilityService => new RescheduleAvailabilityService(
        $c->get(Database::class),
        $c->get(AvailabilityRepository::class),
        $c->get(CatalogRepository::class),
        $c->get(ZoneResolver::class),
        $c->get(GeoProviderInterface::class),
    ));
    $container->singleton(JobController::class, static fn (Container $c): JobController => new JobController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(Database::class),
        $c->get(DispatchService::class),
        $c->get(RescheduleAvailabilityService::class),
        $c->get(NominatimGeocoder::class),
    ));
    $container->singleton(CalendarController::class, static fn (Container $c): CalendarController => new CalendarController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(DispatchService::class),
        $c->get(ZoneRepository::class),
        $c->get(TechnicianRepository::class),
    ));
    $container->singleton(CustomerRepository::class, static fn (Container $c): CustomerRepository => new CustomerRepository($c->get(Database::class), $c->get(NominatimGeocoder::class)));
    $container->singleton(CustomerController::class, static fn (Container $c): CustomerController => new CustomerController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(CustomerRepository::class),
    ));
    $container->singleton(LocationController::class, static fn (Container $c): LocationController => new LocationController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(Database::class),
    ));
    $container->singleton(UserController::class, static fn (Container $c): UserController => new UserController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(UserRepository::class),
    ));
    $container->singleton(TechnicianRepository::class, static fn (Container $c): TechnicianRepository => new TechnicianRepository($c->get(Database::class)));
    $container->singleton(TechnicianController::class, static fn (Container $c): TechnicianController => new TechnicianController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(TechnicianRepository::class),
    ));
    $container->singleton(ZoneRepository::class, static fn (Container $c): ZoneRepository => new ZoneRepository($c->get(Database::class)));
    $container->singleton(ZoneController::class, static fn (Container $c): ZoneController => new ZoneController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(ZoneRepository::class),
    ));
    $container->singleton(SimulatorController::class, static fn (Container $c): SimulatorController => new SimulatorController(
        $c->get(View::class),
        $c->get(Session::class),
        $c->get(Csrf::class),
        $c->get(CatalogRepository::class),
        $c->get(SimulatorService::class),
        $c->get(\Keepnew\Catalog\CartPricingService::class),
    ));

    // --- Racine → redirige vers le back-office ----------------------------
    $router->get('/', static fn (): Response => Response::redirect('/admin/connexion'));

    // --- Santé / démonstration du noyau ------------------------------------
    $router->get('/health', [HealthController::class, 'index']);
    $router->get('/health/echo/{value}', [HealthController::class, 'echo']);

    // --- Authentification (public, CSRF sur le POST) -----------------------
    $router->get('/admin/connexion', [AuthController::class, 'showLogin']);
    $router->post('/admin/connexion', [AuthController::class, 'login'], [CsrfMiddleware::class]);
    $router->get('/admin/deconnexion', [AuthController::class, 'logout']);

    // --- Contrôle d'accès par rôle (RBAC) ----------------------------------
    // Instancié une fois puis appliqué en middleware additionnel aux sous-groupes.
    $session = $container->get(Session::class);
    $adminOnly = new RoleMiddleware($session, ['admin']);
    $ops = new RoleMiddleware($session, ['admin', 'dispatcher']);
    $accounting = new RoleMiddleware($session, ['admin', 'accountant']);
    $opsAccounting = new RoleMiddleware($session, ['admin', 'dispatcher', 'accountant']);

    // --- Back-office (authentifié) -----------------------------------------
    $router->group('/admin', [AuthMiddleware::class], static function (Router $r) use ($adminOnly, $ops, $accounting, $opsAccounting): void {

        // ===== Sections réservées à l'administrateur =====
        $r->group('', [$adminOnly], static function (Router $r): void {
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

            // Form builder
            $r->get('/formulaire', [FormBuilderController::class, 'index']);
            $r->post('/formulaire', [FormBuilderController::class, 'createVersion'], [CsrfMiddleware::class]);
            $r->get('/formulaire/{id}', [FormBuilderController::class, 'edit']);
            $r->post('/formulaire/{id}/publier', [FormBuilderController::class, 'publish'], [CsrfMiddleware::class]);
            $r->post('/formulaire/{id}/champ', [FormBuilderController::class, 'addField'], [CsrfMiddleware::class]);
            $r->post('/formulaire/{id}/champ/{fieldId}/supprimer', [FormBuilderController::class, 'deleteField'], [CsrfMiddleware::class]);
            $r->post('/formulaire/{id}/champs/ordre', [FormBuilderController::class, 'reorderFields'], [CsrfMiddleware::class]);
            $r->post('/formulaire/{id}/champ/{fieldId}/services', [FormBuilderController::class, 'updateFieldServices'], [CsrfMiddleware::class]);
            $r->post('/formulaire/{id}/champ/{fieldId}/option', [FormBuilderController::class, 'addOption'], [CsrfMiddleware::class]);
            $r->get('/formulaire/{id}/option/{optionId}/supprimer', [FormBuilderController::class, 'deleteOption']);
            $r->post('/formulaire/{id}/condition', [FormBuilderController::class, 'addCondition'], [CsrfMiddleware::class]);
            $r->get('/formulaire/{id}/condition/{conditionId}/supprimer', [FormBuilderController::class, 'deleteCondition']);

            // Utilisateurs & rôles
            $r->get('/utilisateurs', [UserController::class, 'index']);
            $r->get('/utilisateurs/nouveau', [UserController::class, 'createForm']);
            $r->post('/utilisateurs', [UserController::class, 'create'], [CsrfMiddleware::class]);
            $r->get('/utilisateurs/{id}', [UserController::class, 'edit']);
            $r->post('/utilisateurs/{id}', [UserController::class, 'update'], [CsrfMiddleware::class]);
            $r->post('/utilisateurs/{id}/actif', [UserController::class, 'toggleActive'], [CsrfMiddleware::class]);

            // Catalogue des compétences (skills)
            $r->get('/competences', [TechnicianController::class, 'skillsIndex']);
            $r->post('/competences', [TechnicianController::class, 'createSkill'], [CsrfMiddleware::class]);
            $r->post('/competences/{id}', [TechnicianController::class, 'updateSkill'], [CsrfMiddleware::class]);

            // Zones de service (chalandise) : couverture, règles tarifaires, techniciens
            $r->get('/zones', [ZoneController::class, 'index']);
            $r->get('/zones/nouvelle', [ZoneController::class, 'createForm']);
            $r->post('/zones', [ZoneController::class, 'create'], [CsrfMiddleware::class]);
            $r->get('/zones/{id}', [ZoneController::class, 'edit']);
            $r->post('/zones/{id}', [ZoneController::class, 'update'], [CsrfMiddleware::class]);
            $r->post('/zones/{id}/supprimer', [ZoneController::class, 'delete'], [CsrfMiddleware::class]);
            $r->post('/zones/{id}/regle', [ZoneController::class, 'addModifier'], [CsrfMiddleware::class]);
            $r->post('/zones/{id}/regle/{modifierId}/supprimer', [ZoneController::class, 'deleteModifier'], [CsrfMiddleware::class]);
            $r->post('/zones/{id}/techniciens', [ZoneController::class, 'syncTechnicians'], [CsrfMiddleware::class]);
        });

        // ===== Opérationnel (admin + dispatcher) =====
        $r->group('', [$ops], static function (Router $r): void {
            // Dispatch
            $r->get('/dispatch', [DispatchController::class, 'index']);
            $r->post('/dispatch/reassign', [DispatchController::class, 'reassign'], [CsrfMiddleware::class]);
            // Jobs — mutations
            $r->post('/job/{id}/statut', [JobController::class, 'updateStatus'], [CsrfMiddleware::class]);
            $r->post('/job/{id}/note', [JobController::class, 'addNote'], [CsrfMiddleware::class]);
            $r->post('/job/{id}/planifier', [JobController::class, 'updateSchedule'], [CsrfMiddleware::class]);
            $r->get('/job/{id}/creneaux/mois', [JobController::class, 'slotDates']);
            $r->get('/job/{id}/creneaux', [JobController::class, 'slotsForDate']);

            // Ateliers
            $r->get('/ateliers', [LocationController::class, 'index']);
            $r->post('/ateliers/{id}/poste', [LocationController::class, 'addBay'], [CsrfMiddleware::class]);
            $r->post('/ateliers/{id}/fermeture', [LocationController::class, 'addClosure'], [CsrfMiddleware::class]);

            // Clients — édition (lecture partagée plus bas)
            $r->get('/clients/nouveau', [CustomerController::class, 'createForm']);
            $r->post('/clients', [CustomerController::class, 'create'], [CsrfMiddleware::class]);
            $r->get('/client/{id}/editer', [CustomerController::class, 'edit']);
            $r->post('/client/{id}', [CustomerController::class, 'update'], [CsrfMiddleware::class]);
            $r->post('/client/{id}/adresse', [CustomerController::class, 'addAddress'], [CsrfMiddleware::class]);
            $r->post('/client/{id}/adresse/{addrId}', [CustomerController::class, 'updateAddress'], [CsrfMiddleware::class]);
            $r->post('/client/{id}/adresse/{addrId}/supprimer', [CustomerController::class, 'deleteAddress'], [CsrfMiddleware::class]);
            $r->post('/client/{id}/note', [CustomerController::class, 'addNote'], [CsrfMiddleware::class]);

            // Techniciens (fiches, compétences, disponibilités, absences)
            $r->get('/techniciens', [TechnicianController::class, 'index']);
            $r->get('/techniciens/nouveau', [TechnicianController::class, 'createForm']);
            $r->post('/techniciens', [TechnicianController::class, 'create'], [CsrfMiddleware::class]);
            $r->get('/techniciens/{id}', [TechnicianController::class, 'edit']);
            $r->post('/techniciens/{id}', [TechnicianController::class, 'update'], [CsrfMiddleware::class]);
            $r->post('/techniciens/{id}/competences', [TechnicianController::class, 'syncSkills'], [CsrfMiddleware::class]);
            $r->post('/techniciens/{id}/zones', [TechnicianController::class, 'syncZones'], [CsrfMiddleware::class]);
            $r->post('/techniciens/{id}/disponibilite', [TechnicianController::class, 'addAvailability'], [CsrfMiddleware::class]);
            $r->post('/techniciens/{id}/disponibilite/{availId}/supprimer', [TechnicianController::class, 'deleteAvailability'], [CsrfMiddleware::class]);
            $r->post('/techniciens/{id}/absence', [TechnicianController::class, 'addTimeOff'], [CsrfMiddleware::class]);
            $r->post('/techniciens/{id}/absence/{offId}/supprimer', [TechnicianController::class, 'deleteTimeOff'], [CsrfMiddleware::class]);

            // Simulateur
            $r->get('/simulateur', [SimulatorController::class, 'index']);
            $r->get('/simulateur/service/{id}', [SimulatorController::class, 'serviceConfig']);
            $r->post('/simulateur/calcul', [SimulatorController::class, 'calculate'], [CsrfMiddleware::class]);
            $r->post('/simulateur/panier', [SimulatorController::class, 'cart'], [CsrfMiddleware::class]);
        });

        // ===== Comptabilité (admin + comptable) =====
        $r->group('', [$accounting], static function (Router $r): void {
            // Rapports
            $r->get('/rapports', [ReportController::class, 'index']);

            // Factures & Peppol
            $r->get('/factures', [InvoiceController::class, 'index']);
            $r->get('/factures/journal', [InvoiceController::class, 'journal']);
            $r->get('/mobilite', [InvoiceController::class, 'mobility']);
            $r->post('/factures/commande/{bookingId}', [InvoiceController::class, 'generate'], [CsrfMiddleware::class]);
            $r->get('/factures/{id}/ubl', [InvoiceController::class, 'ubl']);
        });

        // ===== Lecture partagée (admin + dispatcher + comptable) =====
        $r->group('', [$opsAccounting], static function (Router $r): void {
            // Clients (lecture ; l'édition est ajoutée en Phase D)
            $r->get('/clients', [CustomerController::class, 'index']);
            $r->get('/client/{id}', [CustomerController::class, 'show']);
            // Fiche job (lecture)
            $r->get('/job/{id}', [JobController::class, 'show']);
            // Calendrier (lecture, mensuel / hebdomadaire, avec filtres)
            $r->get('/calendrier', [CalendarController::class, 'month']);
            $r->get('/calendrier/semaine', [CalendarController::class, 'week']);
        });
    });

    // --- App technicien (PWA, authentifiée) --------------------------------
    $router->group('/tech', [AuthMiddleware::class], static function (Router $r): void {
        $r->get('', [TechController::class, 'planning']);
        $r->get('/job/{id}', [TechController::class, 'job']);
        $r->get('/photo/{id}', [TechController::class, 'servePhoto']);
        $r->post('/job/{id}/pointer', [TechController::class, 'punch'], [CsrfMiddleware::class]);
        $r->post('/job/{id}/statut', [TechController::class, 'status'], [CsrfMiddleware::class]);
        $r->post('/job/{id}/photo', [TechController::class, 'photo'], [CsrfMiddleware::class]);
        $r->post('/job/{id}/signature', [TechController::class, 'signature'], [CsrfMiddleware::class]);
        $r->post('/job/{id}/encaisser', [TechController::class, 'collect'], [CsrfMiddleware::class]);
    });

    // --- API REST publique (widget) ----------------------------------------
    // Cross-origin par nature : pas de CSRF cookie ; endpoints sensibles
    // protégés par rate limiting. Le panier est authentifié par son token.
    $db = $container->get(Database::class);
    $availabilityLimit = new RateLimitMiddleware($db, 'api_availability', 40, 60);
    $bookingLimit = new RateLimitMiddleware($db, 'api_booking', 15, 60);
    $zoneCheckLimit = new RateLimitMiddleware($db, 'api_zone_check', 40, 60);

    $router->group('/api', [], static function (Router $r) use ($availabilityLimit, $bookingLimit, $zoneCheckLimit): void {
        // Catalogue (lecture)
        $r->get('/catalog', [CatalogApiController::class, 'index']);
        $r->get('/services/{id}', [CatalogApiController::class, 'service']);

        // Formulaire dynamique publié (intake)
        $r->get('/form', [FormApiController::class, 'published']);

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

        // Vérification de zone légère (étape 1 du tunnel, avant tout panier)
        $r->get('/zones/check', [ZoneCheckApiController::class, 'check'], [$zoneCheckLimit]);

        // Réservation (rate-limité)
        $r->post('/bookings', [BookingApiController::class, 'create'], [$bookingLimit]);
        $r->get('/bookings/{token}', [BookingApiController::class, 'show']);
        $r->post('/bookings/{token}/schedule', [BookingApiController::class, 'schedule'], [$bookingLimit]);
        $r->post('/bookings/{token}/cancel', [BookingApiController::class, 'cancel']);
    });

    // --- Emplacement réservé à la Phase 6 ----------------------------------
    // $router->group('/widget', [], function (Router $r) { ... });               // Widget public
};
