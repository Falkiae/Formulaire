<?php

declare(strict_types=1);

namespace Keepnew\Booking;

use Keepnew\Core\Database;
use Keepnew\Support\Clock;

/**
 * Réservation temporaire de créneau (hold) pendant le tunnel — anti double-
 * réservation. Un hold vit 10 min (configurable) puis est purgé par cron.
 *
 * La vérification de conflit se fait sous verrou pessimiste (SELECT … FOR
 * UPDATE) au sein d'une transaction, contre les jobs planifiés ET les holds
 * actifs des autres tunnels.
 */
final class HoldService
{
    private const HOLD_MINUTES = 10;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Vérifie qu'un technicien est libre sur [start, end] (jobs + holds), sous
     * verrou. À appeler DANS une transaction déjà ouverte.
     *
     * @param int $excludeJobId  Job à exclure du contrôle (replanification :
     *                           son propre ancien créneau ne doit pas se
     *                           bloquer lui-même).
     */
    public function technicianFree(Database $db, int $technicianId, string $startUtc, string $endUtc, int $excludeJobId = 0): bool
    {
        $now = Clock::nowUtc()->format('Y-m-d H:i:s');

        $excludeSql = '';
        $params = ['t' => $technicianId, 'start' => $startUtc, 'end' => $endUtc];
        if ($excludeJobId > 0) {
            $excludeSql = ' AND id <> :excludeJobId';
            $params['excludeJobId'] = $excludeJobId;
        }
        $jobConflict = (int) $db->scalar(
            "SELECT COUNT(*) FROM jobs
             WHERE technician_id = :t
               AND status IN ('scheduled','en_route','in_progress')
               AND scheduled_start < :end AND scheduled_end > :start
               {$excludeSql}
             FOR UPDATE",
            $params,
        );

        $holdConflict = (int) $db->scalar(
            'SELECT COUNT(*) FROM slot_holds
             WHERE technician_id = :t AND expires_at > :now
               AND starts_at < :end AND ends_at > :start
             FOR UPDATE',
            ['t' => $technicianId, 'now' => $now, 'start' => $startUtc, 'end' => $endUtc],
        );

        return $jobConflict === 0 && $holdConflict === 0;
    }

    /**
     * Idem pour un poste d'atelier.
     *
     * @param int $excludeJobId  Voir technicianFree().
     */
    public function bayFree(Database $db, int $bayId, string $startUtc, string $endUtc, int $excludeJobId = 0): bool
    {
        $now = Clock::nowUtc()->format('Y-m-d H:i:s');

        $excludeSql = '';
        $params = ['b' => $bayId, 'start' => $startUtc, 'end' => $endUtc];
        if ($excludeJobId > 0) {
            $excludeSql = ' AND id <> :excludeJobId';
            $params['excludeJobId'] = $excludeJobId;
        }
        $jobConflict = (int) $db->scalar(
            "SELECT COUNT(*) FROM jobs
             WHERE bay_id = :b AND status IN ('scheduled','en_route','in_progress')
               AND scheduled_start < :end AND scheduled_end > :start
               {$excludeSql}
             FOR UPDATE",
            $params,
        );
        $holdConflict = (int) $db->scalar(
            'SELECT COUNT(*) FROM slot_holds
             WHERE bay_id = :b AND expires_at > :now AND starts_at < :end AND ends_at > :start
             FOR UPDATE',
            ['b' => $bayId, 'now' => $now, 'start' => $startUtc, 'end' => $endUtc],
        );

        return $jobConflict === 0 && $holdConflict === 0;
    }

    /**
     * Pose un hold sur un créneau. Renvoie l'id du hold, ou null en cas de
     * conflit (créneau déjà pris).
     */
    public function place(?int $cartId, string $mode, ?int $technicianId, ?int $bayId, string $startUtc, string $endUtc): ?int
    {
        return $this->db->transaction(function (Database $db) use ($cartId, $mode, $technicianId, $bayId, $startUtc, $endUtc): ?int {
            if ($technicianId !== null && !$this->technicianFree($db, $technicianId, $startUtc, $endUtc)) {
                return null;
            }
            if ($bayId !== null && !$this->bayFree($db, $bayId, $startUtc, $endUtc)) {
                return null;
            }

            $expires = Clock::nowUtc()->modify('+' . self::HOLD_MINUTES . ' minutes')->format('Y-m-d H:i:s');

            return $db->insert('slot_holds', [
                'cart_id' => $cartId,
                'technician_id' => $technicianId,
                'bay_id' => $bayId,
                'mode' => $mode,
                'starts_at' => $startUtc,
                'ends_at' => $endUtc,
                'expires_at' => $expires,
            ]);
        });
    }

    public function releaseForCart(Database $db, int $cartId): void
    {
        $db->run('DELETE FROM slot_holds WHERE cart_id = :c', ['c' => $cartId]);
    }

    /**
     * Purge des holds expirés (appelée par cron).
     */
    public function purgeExpired(): int
    {
        $now = Clock::nowUtc()->format('Y-m-d H:i:s');
        $stmt = $this->db->run('DELETE FROM slot_holds WHERE expires_at <= :now', ['now' => $now]);

        return $stmt->rowCount();
    }
}
