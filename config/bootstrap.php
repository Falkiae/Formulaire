<?php

declare(strict_types=1);

/**
 * Amorçage de l'application.
 *
 * Renvoie un Kernel prêt à traiter une Request. Utilisé par public/index.php
 * (web) et par les scripts cron/CLI. Fonctionne AVEC ou SANS Composer :
 *  - si vendor/autoload.php existe, on l'utilise (PHPMailer, PHPUnit…) ;
 *  - un autoloader PSR-4 maison prend en charge le namespace Keepnew\ afin que
 *    le projet démarre sur un mutualisé même sans `composer install`.
 */

use Keepnew\Core\Config;
use Keepnew\Core\Container;
use Keepnew\Core\Csrf;
use Keepnew\Core\Database;
use Keepnew\Core\Kernel;
use Keepnew\Core\Router;
use Keepnew\Core\Session;
use Keepnew\Core\View;

$root = dirname(__DIR__);

// --- Autoloading ------------------------------------------------------------
$composerAutoload = $root . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
} else {
    // Autoloader PSR-4 minimal pour Keepnew\ → src/
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'Keepnew\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = $root . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

// --- Configuration ----------------------------------------------------------
/** @var array<string, mixed> $config */
$config = new Config(require $root . '/config/config.php');

// --- Conteneur de services --------------------------------------------------
$container = new Container();
$container->instance(Config::class, $config);

$container->singleton(Session::class, static fn (): Session => new Session(
    secureCookie: $config->bool('app.secure_cookie', true),
));

$container->singleton(Csrf::class, static fn (Container $c): Csrf => new Csrf(
    $c->get(Session::class),
));

// La base n'est ouverte que lorsqu'un service la demande réellement (lazy).
$container->singleton(Database::class, static fn (): Database => Database::fromConfig($config));

$container->singleton(View::class, static fn (): View => new View($root . '/views'));

// --- Routeur ----------------------------------------------------------------
$router = new Router($container);
// Exposé dans le conteneur pour que la page de diagnostic puisse interroger la
// table de routage réellement chargée (sans la reconstruire).
$container->instance(Router::class, $router);
(require $root . '/config/routes.php')($router, $container);

// --- Kernel -----------------------------------------------------------------
return new Kernel($container, $router, $config);
