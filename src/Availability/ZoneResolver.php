<?php

declare(strict_types=1);

namespace Keepnew\Availability;

use Keepnew\Geo\Distance;
use Keepnew\Geo\GeoPoint;
use Keepnew\Support\Money;

/**
 * Résolution de zone : une adresse tombe-t-elle dans une zone de service active ?
 *
 * Trois types de zones : rayon (haversine depuis un centre), codes postaux
 * (appartenance), polygone (point-dans-polygone). Agrège les modificateurs
 * tarifaires des zones correspondantes (supplément déplacement, ou refus).
 *
 * Pur : reçoit les zones déjà chargées (tableaux) ; la lecture base est faite
 * par AvailabilityRepository.
 */
final class ZoneResolver
{
    /**
     * @param list<array<string, mixed>> $zones Chaque zone :
     *   id, zone_type, center_lat, center_lng, radius_km, polygon (list),
     *   postal_codes (list<string>), modifiers (list<array{type,calc_type,value}>)
     */
    public function resolve(GeoPoint $point, array $zones): ZoneResult
    {
        $matched = [];
        $surcharge = 0;
        $refused = false;

        foreach ($zones as $zone) {
            if (!$this->matches($point, $zone)) {
                continue;
            }
            $matched[] = (int) $zone['id'];

            foreach ($zone['modifiers'] ?? [] as $mod) {
                if ($mod['type'] === 'refuse') {
                    $refused = true;
                } elseif ($mod['type'] === 'surcharge') {
                    $surcharge += $this->modifierAmount($mod, 0);
                }
            }
        }

        return new ZoneResult(
            inZone: $matched !== [],
            matchedZoneIds: $matched,
            surchargeCents: $surcharge,
            refused: $refused,
        );
    }

    /**
     * @param array<string, mixed> $zone
     */
    private function matches(GeoPoint $point, array $zone): bool
    {
        return match ($zone['zone_type']) {
            'radius' => $point->hasCoordinates()
                && $zone['center_lat'] !== null
                && Distance::haversineKm(
                    $point->lat,
                    $point->lng,
                    (float) $zone['center_lat'],
                    (float) $zone['center_lng'],
                ) <= (float) $zone['radius_km'],
            'postal_codes' => $point->postal !== null
                && in_array($point->postal, $zone['postal_codes'] ?? [], true),
            'polygon' => $point->hasCoordinates()
                && Distance::pointInPolygon($point->lat, $point->lng, $zone['polygon'] ?? []),
            default => false,
        };
    }

    /**
     * @param array{calc_type?:string, value?:int} $mod
     */
    private function modifierAmount(array $mod, int $base): int
    {
        return match ($mod['calc_type'] ?? 'fixed') {
            'percent' => Money::percentOf($base, (int) ($mod['value'] ?? 0)),
            default => (int) ($mod['value'] ?? 0),
        };
    }
}
