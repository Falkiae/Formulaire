<?php

declare(strict_types=1);

namespace Keepnew\Availability;

use Keepnew\Geo\GeoPoint;

/**
 * Contexte d'un technicien pour une recherche de créneaux : fenêtres de travail
 * (déjà en UTC, congés retirés) et blocs occupés (jobs + holds), indexés par
 * date locale « Y-m-d ». Fourni au moteur, qui reste ainsi pur et testable.
 */
final readonly class TechnicianContext
{
    /**
     * @param list<int> $skillIds
     * @param array<string, list<Interval>> $windowsByDate  Fenêtres de dispo UTC
     * @param array<string, list<BusyBlock>> $busyByDate     Blocs occupés
     */
    public function __construct(
        public int $technicianId,
        public array $skillIds,
        public GeoPoint $homePoint,
        public array $windowsByDate,
        public array $busyByDate,
        public int $maxJobsPerDay = 6,
    ) {
    }

    /**
     * @param list<int> $requiredSkillIds
     */
    public function hasSkills(array $requiredSkillIds): bool
    {
        foreach ($requiredSkillIds as $skillId) {
            if (!in_array($skillId, $this->skillIds, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<Interval>
     */
    public function windows(string $date): array
    {
        return $this->windowsByDate[$date] ?? [];
    }

    /**
     * @return list<BusyBlock>
     */
    public function busy(string $date): array
    {
        return $this->busyByDate[$date] ?? [];
    }
}
