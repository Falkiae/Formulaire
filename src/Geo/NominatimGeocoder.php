<?php

declare(strict_types=1);

namespace Keepnew\Geo;

/**
 * Géocodage d'une adresse (rue/code postal/ville) en coordonnées GPS, via
 * Nominatim (service gratuit d'OpenStreetMap, aucune clé API). Utilisé pour
 * alimenter la carte du panneau job — sans lien avec GeoProviderInterface
 * (qui estime un temps de trajet entre deux points déjà connus, pas un
 * géocodage).
 *
 * Politique d'usage Nominatim : un User-Agent identifiant obligatoire, ~1
 * requête/seconde. Appelé ponctuellement (création/consultation d'une
 * adresse par un humain), jamais en lot — largement sous ces limites.
 * Renvoie toujours null en cas d'échec (jamais bloquant pour l'appelant).
 */
final class NominatimGeocoder
{
    public function __construct(
        private readonly string $endpoint = 'https://nominatim.openstreetmap.org/search',
        private readonly string $userAgent = 'Keepnew-Booking/1.0 (+https://keepnew.be)',
        private readonly int $timeoutSeconds = 5,
    ) {
    }

    /**
     * @return array{lat:float, lng:float}|null
     */
    public function geocode(string $street, string $number, string $postalCode, string $city, string $country = 'Belgique'): ?array
    {
        if (trim($street) === '' || trim($postalCode) === '') {
            return null;
        }

        $query = trim(sprintf('%s %s, %s %s, %s', $street, $number, $postalCode, $city, $country));
        $url = $this->endpoint . '?' . http_build_query(['q' => $query, 'format' => 'json', 'limit' => 1]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => ['User-Agent: ' . $this->userAgent],
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            return null;
        }

        try {
            $data = json_decode((string) $response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $first = $data[0] ?? null;
        if (!is_array($first) || !isset($first['lat'], $first['lon']) || !is_numeric($first['lat']) || !is_numeric($first['lon'])) {
            return null;
        }

        return ['lat' => (float) $first['lat'], 'lng' => (float) $first['lon']];
    }
}
