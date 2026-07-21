<?php

declare(strict_types=1);

namespace Keepnew\Notification;

/**
 * Fournisseur SMS nul : n'envoie rien, journalise. Actif par défaut au
 * lancement (aucun coût), remplaçable par Twilio/Brevo par configuration.
 */
final class NullSmsProvider implements SmsProviderInterface
{
    public function name(): string
    {
        return 'null';
    }

    public function send(string $to, string $message): string
    {
        return 'null:' . substr(sha1($to . $message), 0, 12);
    }
}
