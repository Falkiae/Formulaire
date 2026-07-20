<?php

declare(strict_types=1);

namespace Keepnew\Availability;

use Keepnew\Catalog\CatalogRepository;
use Keepnew\Catalog\LineResolver;
use Keepnew\Core\Database;
use Keepnew\Geo\GeoPoint;
use Keepnew\Geo\GeoProviderInterface;
use Keepnew\Pricing\PriceCalculator;
use Keepnew\Support\Clock;

/**
 * Orchestrateur du moteur de disponibilité (branché base).
 *
 * Étape 0 : découpe le panier en jobs par mode d'exécution. Puis, pour la
 * branche domicile : résolution de zone + filtrage des techniciens par zone et
 * compétences + moteur avec contrôle de trajet. Pour la branche atelier :
 * postes + techniciens compétents.
 */
final class AvailabilityService
{
    public function __construct(
        private readonly AvailabilityRepository $repo,
        private readonly CatalogRepository $catalog,
        private readonly LineResolver $resolver,
        private readonly PriceCalculator $calculator,
        private readonly ZoneResolver $zoneResolver,
        private readonly GeoProviderInterface $geo,
        private readonly Database $db,
    ) {
    }

    /**
     * Découpe le panier en jobs par mode. Durées cumulées, compétences unifiées.
     *
     * @param list<array<string, mixed>> $lines
     * @return array{onsite:?JobDraft, workshop:?JobDraft}
     */
    public function splitIntoJobs(array $lines, ?GeoPoint $address): array
    {
        $groups = ['onsite' => [], 'workshop' => []];
        foreach ($lines as $line) {
            $mode = (string) ($line['mode'] ?? 'onsite');
            $groups[$mode][] = $line;
        }

        return [
            'onsite' => $groups['onsite'] !== [] ? $this->buildJob('onsite', $groups['onsite'], $address) : null,
            'workshop' => $groups['workshop'] !== [] ? $this->buildJob('workshop', $groups['workshop'], null) : null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function buildJob(string $mode, array $lines, ?GeoPoint $point): JobDraft
    {
        $activeDuration = 0;
        $occupancy = 0;
        $skillIds = [];

        foreach ($lines as $line) {
            $serviceId = (int) $line['service_id'];
            $input = $this->resolver->resolve(
                $serviceId,
                $mode,
                isset($line['variant_id']) && (int) $line['variant_id'] > 0 ? (int) $line['variant_id'] : null,
                array_map('intval', (array) ($line['extra_ids'] ?? [])),
                max(1, (int) ($line['quantity'] ?? 1)),
            );
            $quote = $this->calculator->calculateLine($input);
            $activeDuration += $quote->lineDurationMin;
            // Immobilisation atelier : durée active + séchage additionnel de la ligne.
            $occupancy += max($quote->occupancyDurationMin, $quote->lineDurationMin);

            foreach ($this->catalog->serviceSkillIds($serviceId) as $skillId) {
                if (!in_array($skillId, $skillIds, true)) {
                    $skillIds[] = $skillId;
                }
            }
        }

        return new JobDraft($mode, $activeDuration, $occupancy, $skillIds, $point);
    }

    /**
     * Créneaux domicile pour un panier à une adresse donnée.
     *
     * @param list<array<string, mixed>> $lines
     * @return array{status:string, zone?:ZoneResult, slots?:list<array<string,mixed>>}
     */
    public function onsiteAvailability(array $lines, GeoPoint $address, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $jobs = $this->splitIntoJobs($lines, $address);
        $job = $jobs['onsite'];
        if ($job === null) {
            return ['status' => 'no_onsite_job'];
        }

        // Résolution de zone (bloquant en domicile).
        $zone = $this->zoneResolver->resolve($address, $this->repo->zones());
        if (!$zone->inZone || $zone->refused) {
            return ['status' => 'out_of_zone', 'zone' => $zone];
        }

        // Fractionnement suggéré si la durée dépasse le maximum configurable.
        $config = $this->loadConfig();
        $split = $job->activeDurationMin > $config->maxJobDurationMin;

        // Filtrage des techniciens par zone assignée + compétences.
        $contexts = $this->repo->technicianContexts($from, $to, 'onsite');
        $eligible = [];
        foreach ($contexts as $ctx) {
            $techZones = $this->repo->technicianZoneIds($ctx->technicianId);
            if (array_intersect($techZones, $zone->matchedZoneIds) === []) {
                continue;
            }
            $eligible[] = $ctx;
        }

        $engine = new AvailabilityEngine($config, $this->geo);
        $slots = $engine->onsiteSlots($job, $eligible, $from, $to, Clock::nowUtc());

        return [
            'status' => 'ok',
            'zone' => $zone,
            'split_suggested' => $split,
            'slots' => array_map(static fn (TimeSlot $s): array => $s->toArray(), $slots),
        ];
    }

    /**
     * Créneaux atelier pour un panier.
     *
     * @param list<array<string, mixed>> $lines
     * @return array{status:string, slots?:list<array<string,mixed>>}
     */
    public function workshopAvailability(array $lines, int $locationId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $jobs = $this->splitIntoJobs($lines, null);
        $job = $jobs['workshop'];
        if ($job === null) {
            return ['status' => 'no_workshop_job'];
        }

        $config = $this->loadConfig();
        $contexts = $this->repo->technicianContexts($from, $to, 'workshop');
        $bays = $this->repo->bayContexts($locationId, $from, $to);

        $engine = new AvailabilityEngine($config, $this->geo);
        $slots = $engine->workshopSlots($job, $contexts, $bays, $from, $to, Clock::nowUtc());

        return [
            'status' => 'ok',
            'slots' => array_map(static fn (TimeSlot $s): array => $s->toArray(), $slots),
        ];
    }

    private function loadConfig(): EngineConfig
    {
        $s = [];
        foreach ($this->db->select("SELECT `key`, `value` FROM settings WHERE `group` = 'availability'") as $row) {
            $s[$row['key']] = $row['value'];
        }

        return new EngineConfig(
            slotStepMin: (int) ($s['availability.slot_step_min'] ?? 30),
            minLeadHours: (int) ($s['availability.min_lead_hours'] ?? 24),
            horizonDays: (int) ($s['availability.horizon_days'] ?? 90),
            setupBufferMin: (int) ($s['availability.setup_buffer_min'] ?? 15),
            maxJobDurationMin: (int) ($s['availability.max_job_duration_min'] ?? 360),
        );
    }
}
