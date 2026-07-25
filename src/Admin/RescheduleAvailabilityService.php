<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Availability\AvailabilityEngine;
use Keepnew\Availability\AvailabilityRepository;
use Keepnew\Availability\AvailabilityService;
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
 * quel). La durée/compétences du job sont reconstruites via
 * AvailabilityService::splitIntoJobs() — la même mécanique catalogue que le
 * tunnel public — plutôt que figées sur les colonnes stockées au moment de
 * la réservation initiale, avec repli sur ces colonnes stockées si le
 * catalogue a changé depuis (ex. mode retiré du service) et empêche de
 * reconstruire la ligne : une replanification ne doit jamais échouer à
 * cause d'un changement de configuration ultérieur.
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
        private readonly AvailabilityService $availability,
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
            'draft' => $this->buildDraft($jobId, $mode, $point, (int) $job['active_duration_min'], (int) $job['occupancy_duration_min']),
            'mode' => $mode,
            'locationId' => $job['location_id'] !== null ? (int) $job['location_id'] : null,
            'point' => $point,
        ];
    }

    /**
     * Reconstruit le JobDraft via AvailabilityService::splitIntoJobs() — même
     * mécanique catalogue/variantes/extras que le tunnel public — à partir des
     * lignes réellement réservées (booking_items + extras). Repli sur les
     * durées/compétences figées en base si cette reconstruction échoue (ex. un
     * mode a été retiré du service depuis la réservation, Phase N) : une
     * replanification d'un job déjà accepté ne doit jamais échouer à cause
     * d'un changement de configuration ultérieur.
     */
    private function buildDraft(int $jobId, string $mode, ?GeoPoint $point, int $fallbackActive, int $fallbackOccupancy): JobDraft
    {
        $rows = $this->db->select(
            "SELECT bi.id, bi.service_id, bi.mode, bi.variant_id, bi.quantity,
                    GROUP_CONCAT(bie.extra_id) AS extra_ids
             FROM booking_items bi
             LEFT JOIN booking_item_extras bie ON bie.booking_item_id = bi.id
             WHERE bi.job_id = :j
             GROUP BY bi.id",
            ['j' => $jobId],
        );

        $lines = array_map(static fn (array $r): array => [
            'service_id' => (int) $r['service_id'],
            'mode' => (string) $r['mode'],
            'variant_id' => $r['variant_id'] !== null ? (int) $r['variant_id'] : null,
            'extra_ids' => $r['extra_ids'] !== null ? array_map('intval', explode(',', (string) $r['extra_ids'])) : [],
            'quantity' => (int) $r['quantity'],
        ], $rows);

        try {
            $jobs = $this->availability->splitIntoJobs($lines, $point);
            $draft = $jobs[$mode] ?? null;
            if ($draft !== null) {
                return $draft;
            }
        } catch (\InvalidArgumentException) {
            // Catalogue changé depuis la réservation (mode/variante/extra retiré) : repli ci-dessous.
        }

        $skillIds = [];
        foreach (array_unique(array_column($lines, 'service_id')) as $serviceId) {
            foreach ($this->catalog->serviceSkillIds($serviceId) as $skillId) {
                if (!in_array($skillId, $skillIds, true)) {
                    $skillIds[] = $skillId;
                }
            }
        }

        return new JobDraft($mode, $fallbackActive, $fallbackOccupancy, $skillIds, $point);
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
