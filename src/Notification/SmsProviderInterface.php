<?php

declare(strict_types=1);

namespace Keepnew\Notification;

/**
 * Abstraction de l'envoi de SMS. Implémentations : Twilio, Brevo, et un
 * fournisseur nul (log) mockable pour les tests et le lancement.
 */
interface SmsProviderInterface
{
    /**
     * Envoie un SMS. Renvoie une référence fournisseur, ou lève en cas d'échec.
     */
    public function send(string $to, string $message): string;

    public function name(): string;
}
