<?php

declare(strict_types=1);

namespace Keepnew\Geo;

/**
 * Un point géographique : coordonnées (géocodées) et/ou code postal.
 *
 * Certains fournisseurs raisonnent en lat/lng (ORS, haversine), d'autres en
 * code postal (matrice de repli). Un point porte les deux si disponibles.
 */
final readonly class GeoPoint
{
    public function __construct(
        public ?float $lat = null,
        public ?float $lng = null,
        public ?string $postal = null,
    ) {
    }

    public function hasCoordinates(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }
}
