<?php

declare(strict_types=1);

namespace Keepnew\Tracking;

/**
 * Client Meta Conversions API (CAPI) — événements CÔTÉ SERVEUR.
 *
 * L'`event_id` est partagé avec le pixel navigateur pour la DÉDUPLICATION :
 * Meta considère l'événement client et l'événement serveur comme un seul.
 * Les données utilisateur (email/tel) sont hachées SHA-256.
 *
 * Sans pixel/token configurés, le client est inactif (no-op) : l'application
 * fonctionne sans tracking.
 */
final class MetaCapiClient
{
    public function __construct(
        private readonly string $pixelId,
        private readonly string $accessToken,
        private readonly string $apiVersion = 'v19.0',
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->pixelId !== '' && $this->accessToken !== '';
    }

    /**
     * Envoie un événement Purchase dédupliqué.
     *
     * @param array{email?:string, phone?:string, first_name?:string, last_name?:string, city?:string} $user
     * @param array{value:float, currency:string, contents?:array<int,mixed>} $custom
     */
    public function purchase(string $eventId, array $user, array $custom, ?string $clientIp = null, ?string $userAgent = null): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $userData = array_filter([
            'em' => isset($user['email']) ? Hasher::email($user['email']) : null,
            'ph' => isset($user['phone']) ? Hasher::phone($user['phone']) : null,
            'fn' => isset($user['first_name']) ? Hasher::plain($user['first_name']) : null,
            'ln' => isset($user['last_name']) ? Hasher::plain($user['last_name']) : null,
            'ct' => isset($user['city']) ? Hasher::plain($user['city']) : null,
            'client_ip_address' => $clientIp,
            'client_user_agent' => $userAgent,
        ]);

        $payload = [
            'data' => [[
                'event_name' => 'Purchase',
                'event_time' => time(),
                'event_id' => $eventId, // clé de déduplication
                'action_source' => 'website',
                'user_data' => $userData,
                'custom_data' => [
                    'value' => $custom['value'],
                    'currency' => $custom['currency'],
                    'contents' => $custom['contents'] ?? [],
                ],
            ]],
        ];

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->pixelId}/events?access_token=" . urlencode($this->accessToken);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $response !== false && $status >= 200 && $status < 300;
    }
}
