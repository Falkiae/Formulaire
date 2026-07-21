<?php

declare(strict_types=1);

namespace Keepnew\Notification;

/**
 * Fournisseur SMS Twilio (API Messages). Activé par configuration.
 */
final class TwilioSmsProvider implements SmsProviderInterface
{
    public function __construct(
        private readonly string $accountSid,
        private readonly string $authToken,
        private readonly string $from,
    ) {
    }

    public function name(): string
    {
        return 'twilio';
    }

    public function send(string $to, string $message): string
    {
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERPWD => $this->accountSid . ':' . $this->authToken,
            CURLOPT_POSTFIELDS => http_build_query(['To' => $to, 'From' => $this->from, 'Body' => $message]),
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException('Échec envoi Twilio (HTTP ' . $status . ').');
        }

        $data = json_decode((string) $response, true);

        return (string) ($data['sid'] ?? 'twilio:ok');
    }
}
