<?php

declare(strict_types=1);

namespace Keepnew\Geo;

/**
 * Chaîne de fournisseurs : interroge chacun dans l'ordre et renvoie la première
 * réponse non nulle. Permet la stratégie « API d'abord, repli codes postaux,
 * puis estimation haversine ».
 */
final class ChainGeoProvider implements GeoProviderInterface
{
    /** @var list<GeoProviderInterface> */
    private array $providers;

    public function __construct(GeoProviderInterface ...$providers)
    {
        $this->providers = array_values($providers);
    }

    public function name(): string
    {
        return 'chain';
    }

    public function travelSeconds(GeoPoint $from, GeoPoint $to): ?int
    {
        foreach ($this->providers as $provider) {
            $seconds = $provider->travelSeconds($from, $to);
            if ($seconds !== null) {
                return $seconds;
            }
        }

        return null;
    }
}
