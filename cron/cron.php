<?php

declare(strict_types=1);

/**
 * Tâches planifiées Keepnew (à lancer par cron CLI, ex. toutes les 5 min) :
 *   php cron/cron.php
 *
 * - Expédie les notifications dues (rappels programmés).
 * - Purge les réservations temporaires de créneaux (holds) expirées.
 * - Facture les commandes terminées non encore facturées.
 *
 * Fallback pseudo-cron : appeler ce script via une requête HTTP protégée si le
 * mutualisé ne fournit pas de cron CLI.
 */

use Keepnew\Booking\HoldService;
use Keepnew\Core\Container;
use Keepnew\Core\Database;
use Keepnew\Invoice\InvoiceService;
use Keepnew\Notification\NotificationService;

/** @var \Keepnew\Core\Kernel $kernel (amorçage : autoload + conteneur) */
$kernel = require dirname(__DIR__) . '/config/bootstrap.php';
/** @var Container $container */
$container = (function () {
    // Le bootstrap ne renvoie que le Kernel ; on reconstruit un conteneur léger.
    return null;
})();

// Réutilise le conteneur global via le bootstrap : on ré-inclut la config.
$root = dirname(__DIR__);
$config = new \Keepnew\Core\Config(require $root . '/config/config.php');
$container = new Container();
$container->instance(\Keepnew\Core\Config::class, $config);
$container->singleton(Database::class, static fn (): Database => Database::fromConfig($config));
(require $root . '/config/routes.php')(new \Keepnew\Core\Router($container), $container);

$log = static function (string $msg): void {
    echo '[' . gmdate('c') . '] ' . $msg . PHP_EOL;
};

// 1. Notifications dues.
$sent = $container->get(NotificationService::class)->dispatchDue();
$log("Notifications expédiées : {$sent}");

// 2. Purge des holds expirés.
$purged = $container->get(HoldService::class)->purgeExpired();
$log("Holds purgés : {$purged}");

// 3. Facturation des commandes terminées non facturées.
$db = $container->get(Database::class);
$invoiceService = $container->get(InvoiceService::class);
$toInvoice = $db->select(
    "SELECT b.id FROM bookings b LEFT JOIN invoices i ON i.booking_id = b.id
     WHERE i.id IS NULL AND b.status = 'completed' LIMIT 100",
);
$invoiced = 0;
foreach ($toInvoice as $row) {
    $invoiceService->createFromBooking((int) $row['id']);
    $invoiced++;
}
$log("Factures générées : {$invoiced}");
