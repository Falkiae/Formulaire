<?php

declare(strict_types=1);

namespace Keepnew\Geo;

/**
 * Abstraction du calcul de temps de trajet.
 *
 * Implémentations : OpenRouteService (défaut), Google Maps (config), matrice de
 * repli par codes postaux, estimation haversine, et un wrapper de cache.
 *
 * Renvoie le temps de trajet en SECONDES, ou null si le fournisseur ne peut pas
 * répondre pour ce couple de points (le chaînage passe alors au suivant).
 */
interface GeoProviderInterface
{
    public function travelSeconds(GeoPoint $from, GeoPoint $to): ?int;

    /** Identifiant court du fournisseur (pour le cache et les logs). */
    public function name(): string;
}
