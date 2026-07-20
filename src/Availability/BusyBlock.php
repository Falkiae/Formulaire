<?php

declare(strict_types=1);

namespace Keepnew\Availability;

use Keepnew\Geo\GeoPoint;

/**
 * Un créneau occupé dans l'agenda d'un technicien (job existant ou hold), avec
 * le lieu de l'intervention pour le calcul du trajet vers/depuis un candidat.
 */
final readonly class BusyBlock
{
    public function __construct(
        public Interval $interval,
        public ?GeoPoint $point = null,
    ) {
    }
}
