<?php

declare(strict_types=1);

namespace Keepnew\Availability;

/**
 * Résultat de la résolution de zone pour une adresse.
 */
final readonly class ZoneResult
{
    /**
     * @param list<int> $matchedZoneIds
     */
    public function __construct(
        public bool $inZone,
        public array $matchedZoneIds,
        public int $surchargeCents,
        public bool $refused,
    ) {
    }
}
