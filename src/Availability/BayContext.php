<?php

declare(strict_types=1);

namespace Keepnew\Availability;

/**
 * Contexte d'un poste de travail (bay) pour la recherche de créneaux atelier :
 * blocs occupés (immobilisation, séchage inclus) indexés par date locale.
 */
final readonly class BayContext
{
    /**
     * @param array<string, list<Interval>> $busyByDate
     */
    public function __construct(
        public int $bayId,
        public array $busyByDate,
    ) {
    }

    /**
     * @return list<Interval>
     */
    public function busy(string $date): array
    {
        return $this->busyByDate[$date] ?? [];
    }
}
