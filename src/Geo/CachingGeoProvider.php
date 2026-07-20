<?php

declare(strict_types=1);

namespace Keepnew\Geo;

use Keepnew\Core\Database;
use Keepnew\Support\Clock;

/**
 * Cache agressif des temps de trajet (table geo_distance_cache).
 *
 * Clé = hash(origine, destination, mode). TTL par défaut 30 jours. Enveloppe un
 * fournisseur coûteux (API) pour ne pas le solliciter en boucle pendant la
 * génération de créneaux (un même trajet est demandé des dizaines de fois).
 */
final class CachingGeoProvider implements GeoProviderInterface
{
    public function __construct(
        private readonly Database $db,
        private readonly GeoProviderInterface $inner,
        private readonly int $ttlDays = 30,
        private readonly string $travelMode = 'driving',
    ) {
    }

    public function name(): string
    {
        return 'cache:' . $this->inner->name();
    }

    public function travelSeconds(GeoPoint $from, GeoPoint $to): ?int
    {
        $key = $this->cacheKey($from, $to);

        $cached = $this->db->selectOne(
            'SELECT duration_sec, expires_at FROM geo_distance_cache WHERE cache_key = :k',
            ['k' => $key],
        );
        $now = Clock::nowUtc()->format('Y-m-d H:i:s');
        if ($cached !== null && $cached['expires_at'] > $now) {
            return (int) $cached['duration_sec'];
        }

        $seconds = $this->inner->travelSeconds($from, $to);
        if ($seconds === null) {
            return null;
        }

        $expires = Clock::nowUtc()->modify("+{$this->ttlDays} days")->format('Y-m-d H:i:s');

        // Upsert du cache (INSERT ... ON DUPLICATE KEY UPDATE).
        $this->db->run(
            'INSERT INTO geo_distance_cache
                (cache_key, origin_lat, origin_lng, dest_lat, dest_lng, travel_mode, duration_sec, provider, expires_at)
             VALUES (:k, :olat, :olng, :dlat, :dlng, :mode, :sec, :prov, :exp)
             ON DUPLICATE KEY UPDATE duration_sec = VALUES(duration_sec),
                                     provider = VALUES(provider),
                                     expires_at = VALUES(expires_at)',
            [
                'k' => $key,
                'olat' => $from->lat,
                'olng' => $from->lng,
                'dlat' => $to->lat,
                'dlng' => $to->lng,
                'mode' => $this->travelMode,
                'sec' => $seconds,
                'prov' => $this->inner->name(),
                'exp' => $expires,
            ],
        );

        return $seconds;
    }

    private function cacheKey(GeoPoint $from, GeoPoint $to): string
    {
        $origin = $from->hasCoordinates() ? "{$from->lat},{$from->lng}" : (string) $from->postal;
        $dest = $to->hasCoordinates() ? "{$to->lat},{$to->lng}" : (string) $to->postal;

        return hash('sha256', $origin . '|' . $dest . '|' . $this->travelMode);
    }
}
