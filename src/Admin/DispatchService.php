<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Availability\EngineConfig;
use Keepnew\Core\Database;
use Keepnew\Core\Exception\HttpException;
use Keepnew\Geo\GeoPoint;
use Keepnew\Geo\GeoProviderInterface;
use Keepnew\Support\Clock;

/**
 * Logique du dispatch : agenda du jour par technicien et réassignation d'un job
 * avec RECALCUL DU TRAJET en temps réel et détection de conflit.
 *
 * Un conflit dur (chevauchement avec un autre job du technicien) est refusé.
 * Un trajet qui ne « tient » pas dans le créneau est autorisé mais SIGNALÉ
 * (le dispatcher garde la main).
 */
final class DispatchService
{
    public function __construct(
        private readonly Database $db,
        private readonly GeoProviderInterface $geo,
        private readonly EngineConfig $config,
    ) {
    }

    /**
     * Jobs planifiés d'une journée (date locale), avec client, adresse et lignes.
     *
     * @return array{technicians:list<array<string,mixed>>, jobs:list<array<string,mixed>>, unassigned:list<array<string,mixed>>}
     */
    public function day(string $date): array
    {
        $window = \Keepnew\Availability\ScheduleBuilder::windowForDate($date, '00:00', '23:59');
        $from = $window->start->modify('-3 hours')->format('Y-m-d H:i:s');
        $to = $window->start->modify('+30 hours')->format('Y-m-d H:i:s');

        $rows = $this->db->select(
            "SELECT j.id, j.mode, j.status, j.technician_id, j.scheduled_start, j.scheduled_end,
                    j.active_duration_min, j.travel_in_min, j.travel_out_min, j.bay_id,
                    b.reference, c.first_name, c.last_name, c.phone,
                    a.street, a.number, a.postal_code, a.city, a.lat, a.lng,
                    GROUP_CONCAT(bi.label_snapshot SEPARATOR ' + ') AS services
             FROM jobs j
             JOIN bookings b ON b.id = j.booking_id
             JOIN customers c ON c.id = b.customer_id
             LEFT JOIN addresses a ON a.id = j.address_id
             LEFT JOIN booking_items bi ON bi.job_id = j.id
             WHERE j.scheduled_start BETWEEN :from AND :to
               AND j.status NOT IN ('cancelled')
             GROUP BY j.id
             ORDER BY j.scheduled_start",
            ['from' => $from, 'to' => $to],
        );

        $jobs = array_map([$this, 'formatJob'], $rows);

        $technicians = $this->db->select('SELECT id, first_name, last_name FROM technicians WHERE is_active = 1 ORDER BY first_name');

        // Jobs sans date (prestations préconfigurées) — colonne « à planifier ».
        $unassigned = array_map([$this, 'formatJob'], $this->db->select(
            "SELECT j.id, j.mode, j.status, j.technician_id, j.scheduled_start, j.scheduled_end,
                    j.active_duration_min, j.travel_in_min, j.travel_out_min, j.bay_id,
                    b.reference, c.first_name, c.last_name, c.phone,
                    a.postal_code, a.city, a.lat, a.lng, a.street, a.number,
                    GROUP_CONCAT(bi.label_snapshot SEPARATOR ' + ') AS services
             FROM jobs j
             JOIN bookings b ON b.id = j.booking_id
             JOIN customers c ON c.id = b.customer_id
             LEFT JOIN addresses a ON a.id = j.address_id
             LEFT JOIN booking_items bi ON bi.job_id = j.id
             WHERE j.status = 'unscheduled'
             GROUP BY j.id",
        ));

        return ['technicians' => $technicians, 'jobs' => $jobs, 'unassigned' => $unassigned];
    }

    /**
     * Réassigne un job à un technicien (et éventuellement une nouvelle heure),
     * recalcule le trajet et vérifie les conflits.
     *
     * @return array{ok:bool, conflict:bool, travel_fits:bool, travel_in_min:int, travel_out_min:int}
     */
    public function reassign(int $jobId, int $technicianId, ?string $startUtc, ?int $userId): array
    {
        $job = $this->db->selectOne(
            'SELECT j.*, a.lat AS a_lat, a.lng AS a_lng, a.postal_code AS a_postal
             FROM jobs j LEFT JOIN addresses a ON a.id = j.address_id WHERE j.id = :id',
            ['id' => $jobId],
        );
        if ($job === null) {
            throw new HttpException(404, 'Job introuvable.');
        }

        $start = $startUtc !== null
            ? (new \DateTimeImmutable($startUtc))->setTimezone(new \DateTimeZone('UTC'))
            : new \DateTimeImmutable((string) $job['scheduled_start'], new \DateTimeZone('UTC'));
        $active = (int) $job['active_duration_min'];
        $end = $start->modify("+{$active} minutes");
        $startStr = $start->format('Y-m-d H:i:s');
        $endStr = $end->format('Y-m-d H:i:s');

        return $this->db->transaction(function (Database $db) use ($job, $jobId, $technicianId, $start, $end, $startStr, $endStr, $active, $userId): array {
            // 1. Conflit dur : chevauchement avec un AUTRE job du technicien.
            $overlap = (int) $db->scalar(
                "SELECT COUNT(*) FROM jobs
                 WHERE technician_id = :t AND id <> :self
                   AND status IN ('scheduled','en_route','in_progress')
                   AND scheduled_start < :end AND scheduled_end > :start
                 FOR UPDATE",
                ['t' => $technicianId, 'self' => $jobId, 'start' => $startStr, 'end' => $endStr],
            );
            if ($overlap > 0) {
                return ['ok' => false, 'conflict' => true, 'travel_fits' => false, 'travel_in_min' => 0, 'travel_out_min' => 0];
            }

            // 2. Recalcul du trajet contre les jobs voisins du technicien.
            $jobPoint = new GeoPoint(
                $job['a_lat'] !== null ? (float) $job['a_lat'] : null,
                $job['a_lng'] !== null ? (float) $job['a_lng'] : null,
                $job['a_postal'],
            );
            $prev = $db->selectOne(
                "SELECT j.scheduled_end, a.lat, a.lng, a.postal_code FROM jobs j LEFT JOIN addresses a ON a.id=j.address_id
                 WHERE j.technician_id = :t AND j.id <> :self AND j.status IN ('scheduled','en_route','in_progress')
                   AND j.scheduled_end <= :start ORDER BY j.scheduled_end DESC LIMIT 1",
                ['t' => $technicianId, 'self' => $jobId, 'start' => $startStr],
            );
            $next = $db->selectOne(
                "SELECT j.scheduled_start, a.lat, a.lng, a.postal_code FROM jobs j LEFT JOIN addresses a ON a.id=j.address_id
                 WHERE j.technician_id = :t AND j.id <> :self AND j.status IN ('scheduled','en_route','in_progress')
                   AND j.scheduled_start >= :end ORDER BY j.scheduled_start ASC LIMIT 1",
                ['t' => $technicianId, 'self' => $jobId, 'end' => $endStr],
            );

            $travelIn = $prev !== null ? $this->travelMin($this->pointOf($prev), $jobPoint) : 0;
            $travelOut = $next !== null ? $this->travelMin($jobPoint, $this->pointOf($next)) : 0;

            $fits = true;
            if ($prev !== null) {
                $prevEnd = new \DateTimeImmutable((string) $prev['scheduled_end'], new \DateTimeZone('UTC'));
                if ($prevEnd->modify("+{$travelIn} minutes") > $start) {
                    $fits = false;
                }
            }
            if ($next !== null) {
                $nextStart = new \DateTimeImmutable((string) $next['scheduled_start'], new \DateTimeZone('UTC'));
                if ($end->modify("+{$travelOut} minutes") > $nextStart) {
                    $fits = false;
                }
            }

            // 3. Application.
            $db->run(
                "UPDATE jobs SET technician_id = :t, scheduled_start = :s, scheduled_end = :e,
                        status = CASE WHEN status = 'unscheduled' THEN 'scheduled' ELSE status END,
                        travel_in_min = :ti, travel_out_min = :tout,
                        arrival_from = :af_start, arrival_to = :af_end
                 WHERE id = :id",
                [
                    't' => $technicianId, 's' => $startStr, 'e' => $endStr,
                    'ti' => $travelIn, 'tout' => $travelOut,
                    'af_start' => $startStr,
                    'af_end' => $start->modify('+120 minutes')->format('Y-m-d H:i:s'),
                    'id' => $jobId,
                ],
            );

            $db->insert('audit_log', [
                'user_id' => $userId,
                'action' => 'dispatch.reassign',
                'entity_type' => 'job',
                'entity_id' => $jobId,
                'new_values' => json_encode(['technician_id' => $technicianId, 'start' => $startStr, 'travel_fits' => $fits], JSON_UNESCAPED_UNICODE),
            ]);

            return ['ok' => true, 'conflict' => false, 'travel_fits' => $fits, 'travel_in_min' => $travelIn, 'travel_out_min' => $travelOut];
        });
    }

    /**
     * @param array<string,mixed> $row
     */
    private function pointOf(array $row): GeoPoint
    {
        return new GeoPoint(
            $row['lat'] !== null ? (float) $row['lat'] : null,
            $row['lng'] !== null ? (float) $row['lng'] : null,
            $row['postal_code'] ?? null,
        );
    }

    private function travelMin(GeoPoint $a, GeoPoint $b): int
    {
        $sec = $this->geo->travelSeconds($a, $b);

        return $sec === null ? 0 : (int) ceil($sec / 60);
    }

    /**
     * @param array<string,mixed> $j
     * @return array<string,mixed>
     */
    private function formatJob(array $j): array
    {
        $start = $j['scheduled_start'] !== null ? new \DateTimeImmutable((string) $j['scheduled_start'], new \DateTimeZone('UTC')) : null;
        $end = $j['scheduled_end'] !== null ? new \DateTimeImmutable((string) $j['scheduled_end'], new \DateTimeZone('UTC')) : null;

        return [
            'id' => (int) $j['id'],
            'reference' => $j['reference'],
            'mode' => $j['mode'],
            'status' => $j['status'],
            'technician_id' => $j['technician_id'] !== null ? (int) $j['technician_id'] : null,
            'customer' => trim(($j['first_name'] ?? '') . ' ' . ($j['last_name'] ?? '')),
            'phone' => $j['phone'] ?? null,
            'services' => $j['services'] ?? '',
            'address' => trim(($j['street'] ?? '') . ' ' . ($j['number'] ?? '') . ', ' . ($j['postal_code'] ?? '') . ' ' . ($j['city'] ?? '')),
            'lat' => $j['lat'] ?? null,
            'lng' => $j['lng'] ?? null,
            'start_local' => $start !== null ? Clock::format($start, 'H:i') : null,
            'end_local' => $end !== null ? Clock::format($end, 'H:i') : null,
            'active_duration_min' => (int) $j['active_duration_min'],
            'travel_in_min' => $j['travel_in_min'] !== null ? (int) $j['travel_in_min'] : null,
            'travel_out_min' => $j['travel_out_min'] !== null ? (int) $j['travel_out_min'] : null,
        ];
    }
}
