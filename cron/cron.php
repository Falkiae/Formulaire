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
 *
 * Le conteneur vient de config/bootstrap.php : il est déjà entièrement câblé.
 * Ce script en reconstruisait un « léger » et ré-exécutait config/routes.php,
 * qui résout Session dès son chargement — le cron mourait donc sur « Service
 * non enregistré : Session » avant d'avoir rien expédié.
 *
 * Toute erreur est signalée sur stderr avec un code de sortie non nul, pour que
 * le cron de l'hébergeur envoie un e-mail au lieu d'échouer en silence.
 */

use Keepnew\Booking\HoldService;
use Keepnew\Core\Container;
use Keepnew\Core\Database;
use Keepnew\Invoice\InvoiceService;
use Keepnew\Notification\NotificationService;
use Keepnew\Support\Clock;

/** @var Container $container */
$container = require dirname(__DIR__) . '/config/bootstrap.php';

$log = static function (string $msg): void {
    echo '[' . gmdate('c') . '] ' . $msg . PHP_EOL;
};

try {
    // 1. Notifications dues (rappels 48 h / 2 h programmés à la réservation).
    $sent = $container->get(NotificationService::class)->dispatchDue();
    $log("Notifications expédiées : {$sent}");

    // 2. Purge des holds expirés. Hygiène seulement : le moteur de
    //    disponibilité filtre déjà `expires_at > now`, un hold périmé n'a
    //    jamais bloqué un créneau.
    $purged = $container->get(HoldService::class)->purgeExpired();
    $log("Holds purgés : {$purged}");

    // 3. Facturation des commandes terminées non facturées.
    //
    //    Après un DÉLAI DE GRÂCE (24 h par défaut, réglage
    //    `invoice.auto_delay_hours`) : une commande reste retouchable tant
    //    qu'elle n'est pas facturée (BookingEditService), et facturer dans les
    //    minutes qui suivent la fin de l'intervention priverait l'équipe de
    //    toute correction de prix. Le repère est l'entrée d'historique posée au
    //    passage en « terminée ».
    $db = $container->get(Database::class);
    $delayHours = (int) ($db->scalar(
        "SELECT `value` FROM settings WHERE `key` = 'invoice.auto_delay_hours'",
    ) ?? 24);

    // Coupure calculée en PHP plutôt qu'avec « INTERVAL :delay HOUR » : un
    // paramètre lié dans une expression INTERVAL est un terrain glissant selon
    // le mode de préparation, et une date passée en paramètre reste lisible
    // par n'importe quel moteur (donc testable hors MySQL).
    $cutoff = Clock::nowUtc()->modify('-' . max(0, $delayHours) . ' hours')->format('Y-m-d H:i:s');

    $toInvoice = $db->select(
        "SELECT b.id
           FROM bookings b
           LEFT JOIN invoices i ON i.booking_id = b.id
          WHERE i.id IS NULL
            AND b.status = 'completed'
            AND (
                SELECT MAX(h.created_at) FROM booking_status_history h
                 WHERE h.booking_id = b.id AND h.new_status = 'completed'
            ) <= :cutoff
          LIMIT 100",
        ['cutoff' => $cutoff],
    );

    $invoiced = 0;
    foreach ($toInvoice as $row) {
        $container->get(InvoiceService::class)->createFromBooking((int) $row['id']);
        $invoiced++;
    }
    $log("Factures générées : {$invoiced} (délai de grâce {$delayHours} h)");
} catch (\Throwable $e) {
    fwrite(STDERR, sprintf(
        "[%s] ÉCHEC du cron : %s (%s:%d)\n",
        gmdate('c'),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
    ));
    exit(1);
}
