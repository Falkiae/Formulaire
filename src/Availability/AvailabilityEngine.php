<?php

declare(strict_types=1);

namespace Keepnew\Availability;

use Keepnew\Geo\GeoPoint;
use Keepnew\Geo\GeoProviderInterface;
use Keepnew\Support\Clock;

/**
 * Moteur de disponibilité — cœur du système.
 *
 * Pur (aucun accès base) : reçoit des contextes déjà construits (fenêtres UTC,
 * blocs occupés) et produit des créneaux réservables. La construction des
 * contextes depuis la base est le rôle d'AvailabilityRepository.
 *
 * Deux branches :
 *  - DOMICILE : filtrage compétences, génération de créneaux, et surtout
 *    CONTRÔLE DU TEMPS DE TRAJET entre le job précédent et le job suivant du
 *    technicien (le créneau est rejeté si trajet + job dépasse le gap).
 *  - ATELIER : la ressource limitante est le POSTE (bay) autant que le
 *    technicien ; un créneau n'est proposé que si un poste ET un technicien
 *    compétent sont libres simultanément. Sortie = dépôt + reprise estimée.
 *
 * Tout est raisonné en UTC (robuste au changement d'heure d'été).
 */
final class AvailabilityEngine
{
    public function __construct(
        private readonly EngineConfig $config,
        private readonly GeoProviderInterface $geo,
    ) {
    }

    /**
     * Branche DOMICILE : créneaux avec contrôle de trajet.
     *
     * @param list<TechnicianContext> $technicians  Déjà filtrés par zone
     * @return list<TimeSlot>
     */
    public function onsiteSlots(
        JobDraft $job,
        array $technicians,
        \DateTimeImmutable $fromDate,
        \DateTimeImmutable $toDate,
        \DateTimeImmutable $nowUtc,
    ): array {
        $minBookable = $nowUtc->modify("+{$this->config->minLeadHours} hours");
        $horizon = $nowUtc->modify("+{$this->config->horizonDays} days");
        if ($toDate > $horizon) {
            $toDate = $horizon;
        }

        // Bloc occupé par le technicien = travail actif + buffer setup/rangement.
        $blockMin = $job->activeDurationMin + $this->config->setupBufferMin;
        $slots = [];

        foreach ($this->eachLocalDate($fromDate, $toDate) as $dateStr) {
            foreach ($technicians as $tech) {
                if (!$tech->hasSkills($job->requiredSkillIds)) {
                    continue;
                }
                // Limite de jobs/jour/technicien (les holds ne comptent pas).
                if (count($tech->busy($dateStr)) >= $tech->maxJobsPerDay) {
                    continue;
                }

                foreach ($tech->windows($dateStr) as $window) {
                    $busy = $tech->busy($dateStr);
                    foreach ($this->gridStarts($window, $blockMin, $minBookable) as $start) {
                        $blockEnd = $start->modify("+{$blockMin} minutes");
                        $candidate = new Interval($start, $blockEnd);

                        if ($this->overlapsAny($candidate, $busy)) {
                            continue;
                        }

                        [$prev, $next] = $this->neighbours($candidate, $busy);

                        // Trajet depuis le job précédent (0 si premier job du jour).
                        $travelIn = $prev !== null && $prev->point !== null && $job->point !== null
                            ? $this->travelMinutes($prev->point, $job->point)
                            : 0;
                        if ($prev !== null && $prev->interval->end->modify("+{$travelIn} minutes") > $start) {
                            continue;
                        }

                        // Trajet vers le job suivant (0 si dernier job du jour).
                        $travelOut = $next !== null && $next->point !== null && $job->point !== null
                            ? $this->travelMinutes($job->point, $next->point)
                            : 0;
                        if ($next !== null && $blockEnd->modify("+{$travelOut} minutes") > $next->interval->start) {
                            continue;
                        }

                        $slots[] = new TimeSlot(
                            start: $start,
                            end: $start->modify("+{$job->activeDurationMin} minutes"),
                            technicianId: $tech->technicianId,
                            mode: 'onsite',
                            travelInMin: $prev !== null ? $travelIn : null,
                            travelOutMin: $next !== null ? $travelOut : null,
                        );

                        if (count($slots) >= $this->config->maxSlotsPerJob) {
                            return $this->sortSlots($slots);
                        }
                    }
                }
            }
        }

        return $this->sortSlots($slots);
    }

    /**
     * Branche ATELIER : poste + technicien compétent libres simultanément.
     *
     * @param list<TechnicianContext> $technicians
     * @param list<BayContext> $bays
     * @return list<TimeSlot>
     */
    public function workshopSlots(
        JobDraft $job,
        array $technicians,
        array $bays,
        \DateTimeImmutable $fromDate,
        \DateTimeImmutable $toDate,
        \DateTimeImmutable $nowUtc,
    ): array {
        $minBookable = $nowUtc->modify("+{$this->config->minLeadHours} hours");
        $horizon = $nowUtc->modify("+{$this->config->horizonDays} days");
        if ($toDate > $horizon) {
            $toDate = $horizon;
        }

        $active = $job->activeDurationMin;
        // Immobilisation du poste (séchage) : au moins la durée active.
        $occupancy = max($job->occupancyDurationMin, $active);
        $slots = [];

        foreach ($this->eachLocalDate($fromDate, $toDate) as $dateStr) {
            foreach ($technicians as $tech) {
                if (!$tech->hasSkills($job->requiredSkillIds)) {
                    continue;
                }
                foreach ($tech->windows($dateStr) as $window) {
                    $techBusy = $tech->busy($dateStr);
                    foreach ($this->gridStarts($window, $occupancy, $minBookable) as $start) {
                        $techBlock = new Interval($start, $start->modify("+{$active} minutes"));
                        $bayBlock = new Interval($start, $start->modify("+{$occupancy} minutes"));

                        // Le technicien doit être libre pendant le travail actif.
                        if ($this->overlapsAny($techBlock, $techBusy)) {
                            continue;
                        }

                        // Il faut un poste libre pendant toute l'immobilisation.
                        $bayId = $this->firstFreeBay($bays, $bayBlock, $dateStr);
                        if ($bayId === null) {
                            continue;
                        }

                        $slots[] = new TimeSlot(
                            start: $start,
                            end: $start->modify("+{$active} minutes"),
                            technicianId: $tech->technicianId,
                            mode: 'workshop',
                            bayId: $bayId,
                            pickupAt: $bayBlock->end,
                        );

                        if (count($slots) >= $this->config->maxSlotsPerJob) {
                            return $this->sortSlots($slots);
                        }
                    }
                }
            }
        }

        return $this->sortSlots($slots);
    }

    // --- Helpers ---------------------------------------------------------------

    /**
     * Points de départ candidats sur la grille (pas configurable), alignés sur
     * le début de la fenêtre, tels que [start, start+durationMin] tienne dans la
     * fenêtre et que start respecte le délai minimum de réservation.
     *
     * @return \Generator<\DateTimeImmutable>
     */
    private function gridStarts(Interval $window, int $durationMin, \DateTimeImmutable $minBookable): \Generator
    {
        $step = $this->config->slotStepMin;
        $cursor = $window->start;

        while (true) {
            $end = $cursor->modify("+{$durationMin} minutes");
            if ($end > $window->end) {
                break;
            }
            if ($cursor >= $minBookable) {
                yield $cursor;
            }
            $cursor = $cursor->modify("+{$step} minutes");
        }
    }

    /**
     * @param list<BusyBlock> $busy
     */
    private function overlapsAny(Interval $candidate, array $busy): bool
    {
        foreach ($busy as $block) {
            if ($candidate->overlaps($block->interval)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bloc occupé juste avant et juste après le candidat (sans chevauchement).
     *
     * @param list<BusyBlock> $busy
     * @return array{0:?BusyBlock, 1:?BusyBlock}
     */
    private function neighbours(Interval $candidate, array $busy): array
    {
        $prev = null;
        $next = null;
        foreach ($busy as $block) {
            if ($block->interval->end <= $candidate->start) {
                if ($prev === null || $block->interval->end > $prev->interval->end) {
                    $prev = $block;
                }
            } elseif ($block->interval->start >= $candidate->end) {
                if ($next === null || $block->interval->start < $next->interval->start) {
                    $next = $block;
                }
            }
        }

        return [$prev, $next];
    }

    /**
     * @param list<BayContext> $bays
     */
    private function firstFreeBay(array $bays, Interval $bayBlock, string $dateStr): ?int
    {
        foreach ($bays as $bay) {
            $free = true;
            foreach ($bay->busy($dateStr) as $busyInterval) {
                if ($bayBlock->overlaps($busyInterval)) {
                    $free = false;
                    break;
                }
            }
            if ($free) {
                return $bay->bayId;
            }
        }

        return null;
    }

    private function travelMinutes(GeoPoint $from, GeoPoint $to): int
    {
        $seconds = $this->geo->travelSeconds($from, $to);

        return $seconds === null ? 0 : (int) ceil($seconds / 60);
    }

    /**
     * @param list<TimeSlot> $slots
     * @return list<TimeSlot>
     */
    private function sortSlots(array $slots): array
    {
        usort($slots, static fn (TimeSlot $a, TimeSlot $b): int => $a->start <=> $b->start);

        return $slots;
    }

    /**
     * Itère les dates locales (Europe/Brussels) entre deux instants UTC.
     *
     * @return \Generator<string>
     */
    private function eachLocalDate(\DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc): \Generator
    {
        $tz = new \DateTimeZone(Clock::DISPLAY_TZ);
        $current = $fromUtc->setTimezone($tz)->setTime(0, 0);
        $last = $toUtc->setTimezone($tz)->setTime(0, 0);

        while ($current <= $last) {
            yield $current->format('Y-m-d');
            $current = $current->modify('+1 day');
        }
    }
}
