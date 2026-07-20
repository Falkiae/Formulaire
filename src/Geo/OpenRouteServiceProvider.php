<?php

declare(strict_types=1);

namespace Keepnew\Geo;

/**
 * Fournisseur OpenRouteService (Matrix API), choix par défaut du projet.
 *
 * Appel HTTPS via cURL. Renvoie null en cas d'erreur/absence de clé, pour
 * laisser la chaîne basculer sur le repli (matrice codes postaux / haversine).
 * À envelopper dans CachingGeoProvider pour ne pas épuiser le quota.
 */
final class OpenRouteServiceProvider implements GeoProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $endpoint = 'https://api.openrouteservice.org/v2/matrix/driving-car',
        private readonly int $timeoutSeconds = 4,
    ) {
    }

    public function name(): string
    {
        return 'ors';
    }

    public function travelSeconds(GeoPoint $from, GeoPoint $to): ?int
    {
        if ($this->apiKey === '' || !$from->hasCoordinates() || !$to->hasCoordinates()) {
            return null;
        }

        // ORS attend les coordonnées en [lng, lat].
        $payload = json_encode([
            'locations' => [[$from->lng, $from->lat], [$to->lng, $to->lat]],
            'metrics' => ['duration'],
            'sources' => [0],
            'destinations' => [1],
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $this->apiKey,
                'Content-Type: application/json',
            ],
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

        $duration = $data['durations'][0][0] ?? null;

        return is_numeric($duration) ? (int) round((float) $duration) : null;
    }
}
