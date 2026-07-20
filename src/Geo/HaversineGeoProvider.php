<?php

declare(strict_types=1);

namespace Keepnew\Geo;

/**
 * Estimation du temps de trajet par distance à vol d'oiseau (haversine),
 * corrigée d'un facteur de détour routier et d'une vitesse moyenne.
 *
 * C'est le dernier repli, toujours disponible hors ligne. Grossier mais robuste
 * quand ni l'API ni la matrice de codes postaux ne répondent.
 */
final class HaversineGeoProvider implements GeoProviderInterface
{
    private const EARTH_RADIUS_M = 6_371_000;

    public function __construct(
        // Vitesse moyenne effective (km/h) et facteur de détour route vs vol d'oiseau.
        private readonly float $averageSpeedKmh = 45.0,
        private readonly float $detourFactor = 1.3,
    ) {
    }

    public function name(): string
    {
        return 'haversine';
    }

    public function travelSeconds(GeoPoint $from, GeoPoint $to): ?int
    {
        if (!$from->hasCoordinates() || !$to->hasCoordinates()) {
            return null;
        }

        $distanceM = $this->haversineMeters($from->lat, $from->lng, $to->lat, $to->lng) * $this->detourFactor;
        $speedMs = ($this->averageSpeedKmh * 1000) / 3600;

        if ($speedMs <= 0) {
            return null;
        }

        return (int) round($distanceM / $speedMs);
    }

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
