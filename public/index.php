<?php

declare(strict_types=1);

/**
 * Front controller — point d'entrée UNIQUE de l'application web.
 *
 * Toute requête HTTP arrive ici (via la réécriture d'URL de public/.htaccess).
 * On amorce le conteneur, on en tire le Kernel, on construit la Request depuis
 * les superglobales, on obtient la Response et on l'émet. Rien d'autre ne vit
 * dans public/.
 */

use Keepnew\Core\Container;
use Keepnew\Core\Kernel;
use Keepnew\Core\Request;

/** @var Container $container */
$container = require dirname(__DIR__) . '/config/bootstrap.php';

$container->get(Kernel::class)->handle(Request::fromGlobals())->send();
