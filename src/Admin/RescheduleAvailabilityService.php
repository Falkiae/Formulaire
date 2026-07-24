<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Availability\AvailabilityEngine;
use Keepnew\Availability\AvailabilityRepository;
use Keepnew\Availability\EngineConfig;
use Keepnew\Availability\JobDraft;
use Keepnew\Availability\TimeSlot;
use Keepnew\Availability\ZoneResolver;
use Keepnew\Catalog\CatalogRepository;
use Keepnew\Core\Database;
use Keepnew\Geo\GeoPoint;
use Keepnew\Geo\GeoProviderInterface;
use Keepnew\Support\Clock;

/**
 * Créneaux réellement disponibles pour la REPLANIFICATION d'un job déjà
 * planifié (tous techniciens éligibles confondus), pour le sélecteur de
 * créneaux du panneau job. Construit sur les mêmes briques bas niveau que le
 * moteur de disponibilité public (AvailabilityEngine, pur, réutilisé tel
 * quel), mais sans passer par AvailabilityService (couplé au panier et à la
 * dérivation de durée depuis le catalogue) : la durée est déjà connue sur le
 * job existant.
 *
 * Le mode du job n'est PAS changé ici (changer de mode reste une action
 * distincte via le formulaire classique) — uniquement date/heure/technicien.
 */
final class RescheduleAvailabilityService
{
    public function __construct(
        private readonly Database $db,
        private readonly AvailabilityRepository $repo,
        private readonly CatalogRepository $catalog,
        private readonly ZoneResolver $zoneResolver,
        private readonly GeoProviderInterface $geo,
    ) {
    }

    /**
     * Dates d'un mois ayant au moins un créneau réellement libre (pour griser
     * les jours impossibles du mini-calendrier). Une invocation légère du
     * moteur par jour, plafonnée à 1 créneau (on ne veut savoir que "oui/non").
     *
     * @return list<string> dates "Y-m-d"
     */
    public function datesWithSlots(int $jobId, string $monthYm): array
    {
        $job = $this->loadJob($jobId);
        if ($job === null) {
            return [];
        }

        $tz = new \DateTimeZone(Clock::DISPLAY_TZ);
        $first = new \DateTimeImmutable($monthYm . '-01', $tz);
        $last = $first->modify('last day of this month');
        $config = $this->config(maxSlotsPerJob: 1);

        $dates = [];
        $cursor = $first;
        while ($cursor <= $last) {
            $dateStr = $cursor->format('Y-m-d');
            $from = $cursor->setTime(0, 0);
            $to = $cursor->setTime(23, 59);
            if ($this->computeSlots($job, $from, $to, $config) !== []) {
                $dates[] = $dateStr;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $dates;
    }

    /**
     * Créneaux libres d'un jour donné, groupés par heure avec la liste des
     * techniciens réellement disponibles à chacun.
     *
     * @return array<string, list<array{id:int, name:string}>> "H:i" => [{id,name}, ...]
     */
    public function slotsForDate(int $jobId, string $date): array
    {
        $job = $this->loadJob($jobId);
        if ($job === null) {
            return [];
        }

        $tz = new \DateTimeZone(Clock::DISPLAY_TZ);
        $day = new \DateTimeImmutable($date, $tz);
        $from = $day->setTime(0, 0);
        $to = $day->setTime(23, 59);
        $config = $this->config(maxSlotsPerJob: 200);

        $slots = $this->computeSlots($job, $from, $to, $config);
        $techNames = $this->technicianNames();

        $grouped = [];
        foreach ($slots as $slot) {
            $time = Clock::format($slot->start, 'H:i');
            $techId = $slot->technicianId;
            $grouped[$time][$techId] = ['id' => $techId, 'name' => $techNames[$techId] ?? ('#' . $techId)];
        }
        foreach ($grouped as $time => $techs) {
            $grouped[$time] = array_values($techs);
        }
        ksort($grouped);

        return $grouped;
    }

    /**
     * @return array{id:int, draft:JobDraft, mode:string, locationId:?int, point:?GeoPoint}|null
     */
    private function loadJob(int $jobId): ?array
    {
        $job = $this->db->selectOne(
            'SELECT j.mode, j.active_duration_min, j.occupancy_duration_min, j.location_id,
                    a.lat, a.lng, a.postal_code
             FROM jobs j
             LEFT JOIN addresses a ON a.id = j.address_id
             WHERE j.id = :id',
            ['id' => $jobId],
        );
        if ($job === null) {
            return null;
        }

        $serviceIds = array_map(
            static fn (array $r): int => (int) $r['service_id'],
            $this->db->select('SELECT DISTINCT service_id FROM booking_items WHERE job_id = :j', ['j' => $jobId]),
        );
        $skillIds = [];
        foreach ($serviceIds as $serviceId) {
            foreach ($this->catalog->serviceSkillIds($serviceId) as $skillId) {
                if (!in_array($skillId, $skillIds, true)) {
                    $skillIds[] = $skillId;
                }
            }
        }

        $mode = (string) $job['mode'];
        $point = $mode === 'onsite'
            ? new GeoPoint(
                $job['lat'] !== null ? (float) $job['lat'] : null,
                $job['lng'] !== null ? (float) $job['lng'] : null,
                $job['postal_code'],
            )
            : null;

        return [
            'id' => $jobId,
            'draft' => new JobDraft(
                $mode,
                (int) $job['active_duration_min'],
                (int) $job['occupancy_duration_min'],
                $skillIds,
                $point,
            ),
            'mode' => $mode,
            'locationId' => $job['location_id'] !== null ? (int) $job['location_id'] : null,
            'point' => $point,
        ];
    }

    /**
     * @param array{id:int, draft:JobDraft, mode:string, locationId:?int, point:?GeoPoint} $job
     * @return list<TimeSlot>
     */
    private function computeSlots(array $job, \DateTimeImmutable $from, \DateTimeImmutable $to, EngineConfig $config): array
    {
        $engine = new AvailabilityEngine($config, $this->geo);

        if ($job['mode'] === 'workshop') {
            if ($job['locationId'] === null) {
                return [];
            }

            // NB: on exclut le job lui-même (dernier paramètre) — sinon il se
            // bloquerait sur son propre créneau actuel.
            $contexts = $this->repo->technicianContexts($from, $to, 'workshop', $job['id']);
            $bays = $this->repo->bayContexts($job['locationId'], $from, $to, $job['id']);

            return $engine->workshopSlots($job['draft'], $contexts, $bays, $from, $to, Clock::nowUtc());
        }

        $contexts = $this->repo->technicianContexts($from, $to, 'onsite', $job['id']);

        // Filtrage par zone si l'adresse résout à une zone connue — repli
        // volontairement permissif si le filtrage ne laisse plus AUCUN
        // technicien candidat (adresse hors zone connue, ou zone valide mais
        // plus aucun technicien actif n'y est assigné depuis la réservation
        // initiale) : on ne doit jamais bloquer la replanification d'un job
        // déjà accepté à cause d'un changement de configuration entre-temps.
        if ($job['point'] !== null) {
            $zone = $this->zoneResolver->resolve($job['point'], $this->repo->zones());
            if ($zone->inZone && $zone->matchedZoneIds !== []) {
                $filtered = array_values(array_filter(
                    $contexts,
                    fn ($ctx): bool => array_intersect($this->repo->technicianZoneIds($ctx->technicianId), $zone->matchedZoneIds) !== [],
                ));
                if ($filtered !== []) {
                    $contexts = $filtered;
                }
            }
        }

        return $engine->onsiteSlots($job['draft'], $contexts, $from, $to, Clock::nowUtc());
    }

    /**
     * @return array<int, string>
     */
    private function technicianNames(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT id, first_name, last_name FROM technicians WHERE is_active = 1') as $t) {
            $out[(int) $t['id']] = trim($t['first_name'] . ' ' . $t['last_name']);
        }

        return $out;
    }

    private function config(int $maxSlotsPerJob): EngineConfig
    {
        $s = [];
        foreach ($this->db->select("SELECT `key`, `value` FROM settings WHERE `group` = 'availability'") as $row) {
            $s[$row['key']] = $row['value'];
        }

        return new EngineConfig(
            slotStepMin: (int) ($s['availability.slot_step_min'] ?? 30),
            minLeadHours: 0, // replanification interne : pas de délai minimum imposé à l'admin
            horizonDays: (int) ($s['availability.horizon_days'] ?? 90),
            setupBufferMin: (int) ($s['availability.setup_buffer_min'] ?? 15),
            maxJobDurationMin: (int) ($s['availability.max_job_duration_min'] ?? 360),
            maxSlotsPerJob: $maxSlotsPerJob,
        );
    }
}
