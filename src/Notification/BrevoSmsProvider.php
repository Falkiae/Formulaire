<?php

declare(strict_types=1);

namespace Keepnew\Notification;

/**
 * Fournisseur SMS Brevo (ex-Sendinblue). Activé par configuration.
 */
final class BrevoSmsProvider implements SmsProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $sender,
    ) {
    }

    public function name(): string
    {
        return 'brevo';
    }

    public function send(string $to, string $message): string
    {
        $ch = curl_init('https://api.brevo.com/v3/transactionalSMS/sms');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['api-key: ' . $this->apiKey, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['sender' => $this->sender, 'recipient' => $to, 'content' => $message], JSON_THROW_ON_ERROR),
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException('Échec envoi Brevo (HTTP ' . $status . ').');
        }

        $data = json_decode((string) $response, true);

        return (string) ($data['reference'] ?? 'brevo:ok');
    }
}
