<?php

declare(strict_types=1);

namespace Keepnew\Availability;

use Keepnew\Core\Database;
use Keepnew\Geo\GeoPoint;
use Keepnew\Support\Clock;

/**
 * Construit, depuis la base, les contextes que consomme AvailabilityEngine :
 * fenêtres de disponibilité UTC (règles récurrentes moins congés), blocs
 * occupés (jobs planifiés + holds), postes de travail et zones.
 *
 * Toute la logique temporelle passe par ScheduleBuilder (DST-safe).
 */
final class AvailabilityRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Contextes techniciens pour une plage de dates.
     *
     * @param 'onsite'|'workshop' $scope  onsite : dispo terrain (location NULL) ;
     *                                    workshop : dispo atelier (location_id défini)
     * @param int $excludeJobId  Job à exclure des blocs occupés (replanification
     *                           d'un job déjà planifié : il ne doit pas se
     *                           bloquer lui-même sur son propre créneau actuel).
     * @return list<TechnicianContext>
     */
    public function technicianContexts(\DateTimeImmutable $from, \DateTimeImmutable $to, string $scope, int $excludeJobId = 0): array
    {
        $dates = $this->localDates($from, $to);
        $technicians = $this->db->select('SELECT * FROM technicians WHERE is_active = 1');
        $contexts = [];

        foreach ($technicians as $tech) {
            $techId = (int) $tech['id'];
            $skillIds = array_map(
                static fn (array $r): int => (int) $r['skill_id'],
                $this->db->select('SELECT skill_id FROM technician_skills WHERE technician_id = :id', ['id' => $techId]),
            );

            $rules = $this->db->select(
                $scope === 'workshop'
                    ? 'SELECT * FROM technician_availability WHERE technician_id = :id AND location_id IS NOT NULL'
                    : 'SELECT * FROM technician_availability WHERE technician_id = :id AND location_id IS NULL',
                ['id' => $techId],
            );
            $timeOff = $this->db->select(
                'SELECT starts_at, ends_at FROM technician_time_off WHERE technician_id = :id',
                ['id' => $techId],
            );

            $windowsByDate = [];
            $busyByDate = [];
            foreach ($dates as $date) {
                $weekday = (int) (new \DateTimeImmutable($date, new \DateTimeZone(Clock::DISPLAY_TZ)))->format('w');
                $windows = [];
                foreach ($rules as $rule) {
                    if ((int) $rule['weekday'] !== $weekday) {
                        continue;
                    }
                    $window = ScheduleBuilder::windowForDate(
                        $date,
                        substr((string) $rule['start_time'], 0, 5),
                        substr((string) $rule['end_time'], 0, 5),
                    );
                    // Retire les congés recoupant la fenêtre.
                    $offs = [];
                    foreach ($timeOff as $off) {
                        $offInterval = new Interval(
                            new \DateTimeImmutable((string) $off['starts_at'], new \DateTimeZone('UTC')),
                            new \DateTimeImmutable((string) $off['ends_at'], new \DateTimeZone('UTC')),
                        );
                        if ($offInterval->overlaps($window)) {
                            $offs[] = $offInterval;
                        }
                    }
                    foreach (ScheduleBuilder::subtractAll($window, $offs) as $piece) {
                        $windows[] = $piece;
                    }
                }
                if ($windows !== []) {
                    $windowsByDate[$date] = $windows;
                    $busyByDate[$date] = $this->technicianBusy($techId, $date, $excludeJobId);
                }
            }

            $contexts[] = new TechnicianContext(
                technicianId: $techId,
                skillIds: $skillIds,
                homePoint: new GeoPoint(
                    $tech['home_lat'] !== null ? (float) $tech['home_lat'] : null,
                    $tech['home_lng'] !== null ? (float) $tech['home_lng'] : null,
                    $tech['home_postal'],
                ),
                windowsByDate: $windowsByDate,
                busyByDate: $busyByDate,
                maxJobsPerDay: (int) $tech['max_jobs_per_day'],
            );
        }

        return $contexts;
    }

    /**
     * Blocs occupés d'un technicien un jour donné : jobs planifiés + holds.
     *
     * @return list<BusyBlock>
     */
    private function technicianBusy(int $techId, string $date, int $excludeJobId = 0): array
    {
        $dayStart = ScheduleBuilder::windowForDate($date, '00:00', '23:59')->start->modify('-3 hours');
        $dayEnd = $dayStart->modify('+30 hours');
        $params = [
            'id' => $techId,
            's' => $dayStart->format('Y-m-d H:i:s'),
            'e' => $dayEnd->format('Y-m-d H:i:s'),
        ];

        $blocks = [];

        $excludeSql = '';
        if ($excludeJobId > 0) {
            $excludeSql = ' AND j.id <> :excludeJobId';
            $params['excludeJobId'] = $excludeJobId;
        }
        $jobs = $this->db->select(
            "SELECT j.scheduled_start, j.scheduled_end, a.lat, a.lng, a.postal_code
             FROM jobs j
             LEFT JOIN addresses a ON a.id = j.address_id
             WHERE j.technician_id = :id
               AND j.status IN ('scheduled','en_route','in_progress')
               AND j.scheduled_start IS NOT NULL
               AND j.scheduled_start BETWEEN :s AND :e
               {$excludeSql}",
            $params,
        );
        foreach ($jobs as $job) {
            $blocks[] = new BusyBlock(
                new Interval(
                    new \DateTimeImmutable((string) $job['scheduled_start'], new \DateTimeZone('UTC')),
                    new \DateTimeImmutable((string) $job['scheduled_end'], new \DateTimeZone('UTC')),
                ),
                new GeoPoint(
                    $job['lat'] !== null ? (float) $job['lat'] : null,
                    $job['lng'] !== null ? (float) $job['lng'] : null,
                    $job['postal_code'],
                ),
            );
        }

        // Holds actifs (réservations temporaires du tunnel). Requête sans
        // :excludeJobId (non applicable ici) — params reconstruits sans cette
        // clé, sinon PDO (prepares natifs) rejette un paramètre lié non
        // référencé dans la requête (SQLSTATE[HY093]).
        $now = Clock::nowUtc()->format('Y-m-d H:i:s');
        $holds = $this->db->select(
            'SELECT starts_at, ends_at FROM slot_holds
             WHERE technician_id = :id AND expires_at > :now
               AND starts_at BETWEEN :s AND :e',
            ['id' => $techId, 's' => $params['s'], 'e' => $params['e'], 'now' => $now],
        );
        foreach ($holds as $hold) {
            $blocks[] = new BusyBlock(new Interval(
                new \DateTimeImmutable((string) $hold['starts_at'], new \DateTimeZone('UTC')),
                new \DateTimeImmutable((string) $hold['ends_at'], new \DateTimeZone('UTC')),
            ));
        }

        return $blocks;
    }

    /**
     * Postes de travail d'un atelier avec leurs blocs occupés.
     *
     * @param int $excludeJobId  Job à exclure des blocs occupés (cf. technicianContexts()).
     * @return list<BayContext>
     */
    public function bayContexts(int $locationId, \DateTimeImmutable $from, \DateTimeImmutable $to, int $excludeJobId = 0): array
    {
        $bays = $this->db->select(
            'SELECT id FROM workshop_bays WHERE location_id = :loc AND is_active = 1 ORDER BY sort_order',
            ['loc' => $locationId],
        );
        $now = Clock::nowUtc()->format('Y-m-d H:i:s');
        $contexts = [];

        foreach ($bays as $bay) {
            $bayId = (int) $bay['id'];
            $busyByDate = [];
            foreach ($this->localDates($from, $to) as $date) {
                $dayStart = ScheduleBuilder::windowForDate($date, '00:00', '23:59')->start->modify('-3 hours');
                $dayEnd = $dayStart->modify('+40 hours');
                $intervals = [];

                // Jobs atelier planifiés sur ce poste (immobilisation = occupancy).
                $bayParams = ['bay' => $bayId, 's' => $dayStart->format('Y-m-d H:i:s'), 'e' => $dayEnd->format('Y-m-d H:i:s')];
                $bayExcludeSql = '';
                if ($excludeJobId > 0) {
                    $bayExcludeSql = ' AND id <> :excludeJobId';
                    $bayParams['excludeJobId'] = $excludeJobId;
                }
                $jobs = $this->db->select(
                    "SELECT scheduled_start, scheduled_end, occupancy_duration_min
                     FROM jobs
                     WHERE bay_id = :bay AND status IN ('scheduled','en_route','in_progress')
                       AND scheduled_start BETWEEN :s AND :e
                       {$bayExcludeSql}",
                    $bayParams,
                );
                foreach ($jobs as $job) {
                    $start = new \DateTimeImmutable((string) $job['scheduled_start'], new \DateTimeZone('UTC'));
                    $end = $start->modify('+' . max((int) $job['occupancy_duration_min'], 1) . ' minutes');
                    $intervals[] = new Interval($start, $end);
                }

                // Holds sur ce poste.
                $holds = $this->db->select(
                    'SELECT starts_at, ends_at FROM slot_holds WHERE bay_id = :bay AND expires_at > :now
                       AND starts_at BETWEEN :s AND :e',
                    ['bay' => $bayId, 'now' => $now, 's' => $dayStart->format('Y-m-d H:i:s'), 'e' => $dayEnd->format('Y-m-d H:i:s')],
                );
                foreach ($holds as $hold) {
                    $intervals[] = new Interval(
                        new \DateTimeImmutable((string) $hold['starts_at'], new \DateTimeZone('UTC')),
                        new \DateTimeImmutable((string) $hold['ends_at'], new \DateTimeZone('UTC')),
                    );
                }

                if ($intervals !== []) {
                    $busyByDate[$date] = $intervals;
                }
            }
            $contexts[] = new BayContext($bayId, $busyByDate);
        }

        return $contexts;
    }

    /**
     * Zones actives avec codes postaux et modificateurs, pour ZoneResolver.
     *
     * @return list<array<string, mixed>>
     */
    public function zones(): array
    {
        $zones = $this->db->select('SELECT * FROM service_zones WHERE is_active = 1 ORDER BY priority');
        foreach ($zones as &$zone) {
            $zone['postal_codes'] = array_map(
                static fn (array $r): string => (string) $r['postal_code'],
                $this->db->select('SELECT postal_code FROM zone_postal_codes WHERE zone_id = :id', ['id' => (int) $zone['id']]),
            );
            $zone['modifiers'] = $this->db->select(
                'SELECT modifier_type AS type, calc_type, calc_value AS value FROM zone_pricing_modifiers WHERE zone_id = :id',
                ['id' => (int) $zone['id']],
            );
            $zone['polygon'] = $zone['polygon_json'] !== null ? (json_decode((string) $zone['polygon_json'], true) ?: []) : [];
        }

        return $zones;
    }

    /**
     * Zones (ids) assignées à un technicien, pour le filtrage terrain.
     *
     * @return list<int>
     */
    public function technicianZoneIds(int $techId): array
    {
        return array_map(
            static fn (array $r): int => (int) $r['zone_id'],
            $this->db->select('SELECT zone_id FROM technician_zones WHERE technician_id = :id', ['id' => $techId]),
        );
    }

    /**
     * Identifiant de l'atelier actif par défaut (mono-site au lancement).
     */
    public function defaultWorkshopLocationId(): ?int
    {
        $id = $this->db->scalar('SELECT id FROM locations WHERE is_active = 1 ORDER BY sort_order LIMIT 1');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @return list<string>
     */
    private function localDates(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $tz = new \DateTimeZone(Clock::DISPLAY_TZ);
        $current = $from->setTimezone($tz)->setTime(0, 0);
        $last = $to->setTimezone($tz)->setTime(0, 0);
        $dates = [];
        while ($current <= $last) {
            $dates[] = $current->format('Y-m-d');
            $current = $current->modify('+1 day');
        }

        return $dates;
    }
}
