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
     * Jobs planifiés sur une PLAGE de dates (locale), avec filtres optionnels.
     * Sert au calendrier mensuel / hebdomadaire.
     *
     * @param array{mode?:string, technician_id?:int, zone_id?:int, location_id?:int} $filters
     * @return list<array<string,mixed>>
     */
    public function range(string $fromDate, string $toDate, array $filters = []): array
    {
        $from = Clock::fromDisplay($fromDate . ' 00:00:00')->format('Y-m-d H:i:s');
        $to = Clock::fromDisplay($toDate . ' 23:59:59')->format('Y-m-d H:i:s');

        $where = "j.scheduled_start BETWEEN :from AND :to AND j.status NOT IN ('cancelled')";
        $params = ['from' => $from, 'to' => $to];
        $joins = '';

        if (in_array($filters['mode'] ?? '', ['onsite', 'workshop'], true)) {
            $where .= ' AND j.mode = :mode';
            $params['mode'] = $filters['mode'];
        }
        if (($filters['technician_id'] ?? 0) > 0) {
            $where .= ' AND j.technician_id = :tech';
            $params['tech'] = (int) $filters['technician_id'];
        }
        // Territoire "zone" : ne concerne que les jobs à domicile (adresse
        // dont le code postal appartient à la zone) — cohérent avec
        // ZoneResolver, sans dupliquer sa logique de résolution.
        if (($filters['zone_id'] ?? 0) > 0) {
            $joins .= ' JOIN zone_postal_codes zpc ON zpc.postal_code = a.postal_code AND zpc.zone_id = :zone_id';
            $params['zone_id'] = (int) $filters['zone_id'];
        }
        // Territoire "atelier" : ne concerne que les jobs en atelier (poste
        // rattaché à l'atelier choisi).
        if (($filters['location_id'] ?? 0) > 0) {
            $joins .= ' JOIN workshop_bays wb ON wb.id = j.bay_id AND wb.location_id = :location_id';
            $params['location_id'] = (int) $filters['location_id'];
        }

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
             {$joins}
             WHERE {$where}
             GROUP BY j.id
             ORDER BY j.scheduled_start",
            $params,
        );

        return array_map([$this, 'formatJob'], $rows);
    }

    /**
     * Techniciens actifs (pour les filtres du calendrier / la réassignation).
     *
     * @return list<array<string,mixed>>
     */
    public function activeTechnicians(): array
    {
        return $this->db->select('SELECT id, first_name, last_name FROM technicians WHERE is_active = 1 ORDER BY first_name');
    }

    /**
     * Postes d'atelier actifs, avec le nom de leur atelier (pour le passage en
     * mode atelier depuis la fiche job).
     *
     * @return list<array<string,mixed>>
     */
    public function activeBays(): array
    {
        return $this->db->select(
            'SELECT wb.id, wb.name, wb.location_id, l.name AS location_name
               FROM workshop_bays wb
               JOIN locations l ON l.id = wb.location_id
              WHERE wb.is_active = 1 AND l.is_active = 1
              ORDER BY l.sort_order, wb.sort_order, wb.name',
        );
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
            // `scheduled_end IS NULL` traité comme occupé (jamais comme absent) :
            // un `scheduled_end` manquant ne doit jamais faire disparaître un
            // conflit réel de ce COUNT.
            $overlap = (int) $db->scalar(
                "SELECT COUNT(*) FROM jobs
                 WHERE technician_id = :t AND id <> :self
                   AND status IN ('scheduled','en_route','in_progress')
                   AND scheduled_start < :end AND (scheduled_end IS NULL OR scheduled_end > :start)
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
     * Replanifie un job en changeant AUSSI son mode (domicile ↔ atelier).
     *
     * Acte opérationnel : réaffecte poste/atelier ou adresse, recalcule les
     * durées de planning selon le mode cible, vérifie les conflits technicien
     * et poste. Le PRIX de la commande n'est pas retouché (les lignes gardent
     * leur tarif ; seul booking_items.mode est aligné pour cohérence).
     *
     * @return array{ok:bool, conflict:bool, bay_conflict:bool, unsupported_mode:bool, invalid:bool, travel_fits:bool, travel_in_min:int, travel_out_min:int}
     */
    public function reschedule(int $jobId, int $technicianId, string $mode, ?int $bayId, ?int $addressId, string $startUtc, ?int $userId): array
    {
        $fail = static function (string $key): array {
            $r = [
                'ok' => false, 'conflict' => false, 'bay_conflict' => false,
                'unsupported_mode' => false, 'invalid' => false, 'travel_fits' => false,
                'travel_in_min' => 0, 'travel_out_min' => 0,
            ];
            $r[$key] = true;

            return $r;
        };

        if (!in_array($mode, ['onsite', 'workshop'], true)) {
            return $fail('invalid');
        }
        if ($jobId <= 0 || $technicianId <= 0) {
            return $fail('invalid');
        }

        $start = (new \DateTimeImmutable($startUtc))->setTimezone(new \DateTimeZone('UTC'));

        return $this->db->transaction(function (Database $db) use ($jobId, $technicianId, $mode, $bayId, $addressId, $start, $userId, $fail): array {
            $job = $db->selectOne('SELECT * FROM jobs WHERE id = :id FOR UPDATE', ['id' => $jobId]);
            if ($job === null) {
                throw new HttpException(404, 'Job introuvable.');
            }

            // Durées recalculées pour le mode cible depuis les lignes de la commande.
            $dur = $this->recomputeDurations($db, $jobId, $mode);
            if ($dur === null) {
                return $fail('unsupported_mode');
            }
            $active = $dur['active'];
            $occupancy = $mode === 'workshop' ? max($dur['occupancy'], $active) : $active;
            $end = $start->modify('+' . ($mode === 'workshop' ? $occupancy : $active) . ' minutes');
            $startStr = $start->format('Y-m-d H:i:s');
            $endStr = $end->format('Y-m-d H:i:s');

            // Résolution des ressources selon le mode.
            $locationId = null;
            $addrId = null;
            if ($mode === 'workshop') {
                if ($bayId === null || $bayId <= 0) {
                    return $fail('invalid');
                }
                $bay = $db->selectOne('SELECT id, location_id FROM workshop_bays WHERE id = :b AND is_active = 1', ['b' => $bayId]);
                if ($bay === null) {
                    return $fail('invalid');
                }
                $locationId = (int) $bay['location_id'];
            } else {
                if ($addressId === null || $addressId <= 0) {
                    return $fail('invalid');
                }
                $addr = $db->selectOne('SELECT id FROM addresses WHERE id = :a', ['a' => $addressId]);
                if ($addr === null) {
                    return $fail('invalid');
                }
                $addrId = $addressId;
            }

            // Conflit technicien (chevauchement avec un autre job planifié).
            // `scheduled_end IS NULL` traité comme occupé, jamais comme absent.
            $overlap = (int) $db->scalar(
                "SELECT COUNT(*) FROM jobs
                 WHERE technician_id = :t AND id <> :self
                   AND status IN ('scheduled','en_route','in_progress')
                   AND scheduled_start < :end AND (scheduled_end IS NULL OR scheduled_end > :start)
                 FOR UPDATE",
                ['t' => $technicianId, 'self' => $jobId, 'start' => $startStr, 'end' => $endStr],
            );
            if ($overlap > 0) {
                return $fail('conflict');
            }

            // Conflit de poste (mode atelier).
            if ($mode === 'workshop') {
                $bayOverlap = (int) $db->scalar(
                    "SELECT COUNT(*) FROM jobs
                     WHERE bay_id = :b AND id <> :self
                       AND status IN ('scheduled','en_route','in_progress')
                       AND scheduled_start < :end AND (scheduled_end IS NULL OR scheduled_end > :start)
                     FOR UPDATE",
                    ['b' => $bayId, 'self' => $jobId, 'start' => $startStr, 'end' => $endStr],
                );
                if ($bayOverlap > 0) {
                    return $fail('bay_conflict');
                }
            }

            // Trajet : nul en atelier ; recalculé contre les voisins en domicile.
            $travelIn = 0;
            $travelOut = 0;
            $fits = true;
            if ($mode === 'onsite') {
                $addr = $db->selectOne('SELECT lat, lng, postal_code FROM addresses WHERE id = :a', ['a' => $addrId]);
                $jobPoint = new GeoPoint(
                    $addr !== null && $addr['lat'] !== null ? (float) $addr['lat'] : null,
                    $addr !== null && $addr['lng'] !== null ? (float) $addr['lng'] : null,
                    $addr['postal_code'] ?? null,
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
            }

            // Application.
            $db->run(
                "UPDATE jobs SET mode = :mode, location_id = :loc, bay_id = :bay, address_id = :addr,
                        technician_id = :t, scheduled_start = :s, scheduled_end = :e,
                        active_duration_min = :active, occupancy_duration_min = :occ,
                        travel_in_min = :ti, travel_out_min = :tout,
                        arrival_from = :af_start, arrival_to = :af_end,
                        status = CASE WHEN status = 'unscheduled' THEN 'scheduled' ELSE status END
                 WHERE id = :id",
                [
                    'mode' => $mode,
                    'loc' => $locationId,
                    'bay' => $mode === 'workshop' ? $bayId : null,
                    'addr' => $addrId,
                    't' => $technicianId,
                    's' => $startStr,
                    'e' => $endStr,
                    'active' => $active,
                    'occ' => $occupancy,
                    'ti' => $travelIn,
                    'tout' => $travelOut,
                    'af_start' => $mode === 'onsite' ? $startStr : null,
                    'af_end' => $mode === 'onsite' ? $start->modify('+120 minutes')->format('Y-m-d H:i:s') : null,
                    'id' => $jobId,
                ],
            );

            // Cohérence : les lignes de ce job suivent le nouveau mode (prix inchangé).
            $db->run('UPDATE booking_items SET mode = :m WHERE job_id = :j', ['m' => $mode, 'j' => $jobId]);

            $db->insert('audit_log', [
                'user_id' => $userId,
                'action' => 'dispatch.mode_change',
                'entity_type' => 'job',
                'entity_id' => $jobId,
                'new_values' => json_encode([
                    'mode' => $mode, 'technician_id' => $technicianId,
                    'bay_id' => $mode === 'workshop' ? $bayId : null, 'address_id' => $addrId,
                    'start' => $startStr, 'active_min' => $active, 'occupancy_min' => $occupancy,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return [
                'ok' => true, 'conflict' => false, 'bay_conflict' => false,
                'unsupported_mode' => false, 'invalid' => false,
                'travel_fits' => $fits, 'travel_in_min' => $travelIn, 'travel_out_min' => $travelOut,
            ];
        });
    }

    /**
     * Recalcule les durées d'un job pour un mode donné à partir de ses lignes
     * (booking_items), en miroir de BookingService::computeJobDurations.
     * Renvoie null si un service de la ligne n'existe pas dans le mode cible.
     *
     * @return array{active:int, occupancy:int}|null
     */
    private function recomputeDurations(Database $db, int $jobId, string $mode): ?array
    {
        $rows = $db->select(
            'SELECT bi.quantity, bi.variant_id,
                    sdm.id AS mode_id, sdm.active_duration_min AS mode_active, sdm.occupancy_duration_min AS mode_occ,
                    s.base_duration_min,
                    sv.duration_delta_min AS variant_delta
               FROM booking_items bi
               JOIN services s ON s.id = bi.service_id
               LEFT JOIN service_delivery_modes sdm ON sdm.service_id = bi.service_id AND sdm.mode = :mode
               LEFT JOIN service_variants sv ON sv.id = bi.variant_id
              WHERE bi.job_id = :job',
            ['mode' => $mode, 'job' => $jobId],
        );

        if ($rows === []) {
            return null;
        }

        $totalActive = 0;
        $totalOccupancy = 0;
        foreach ($rows as $row) {
            // Le service doit être proposé dans le mode cible.
            if ($row['mode_id'] === null) {
                return null;
            }
            $active = $row['mode_active'] !== null ? (int) $row['mode_active'] : (int) $row['base_duration_min'];
            $active += $row['variant_delta'] !== null ? (int) $row['variant_delta'] : 0;
            $active *= max(1, (int) $row['quantity']);
            $occupancy = $row['mode_occ'] !== null ? (int) $row['mode_occ'] : $active;

            $totalActive += $active;
            $totalOccupancy += max($occupancy, $active);
        }

        return ['active' => $totalActive, 'occupancy' => $totalOccupancy];
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
            'date_local' => $start !== null ? Clock::format($start, 'Y-m-d') : null,
            'active_duration_min' => (int) $j['active_duration_min'],
            'travel_in_min' => $j['travel_in_min'] !== null ? (int) $j['travel_in_min'] : null,
            'travel_out_min' => $j['travel_out_min'] !== null ? (int) $j['travel_out_min'] : null,
        ];
    }
}
