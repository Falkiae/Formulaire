<?php

declare(strict_types=1);

namespace Keepnew\Notification;

/**
 * Abstraction de l'envoi d'email. Implémentation par défaut : LogMailer.
 * En production : SmtpMailer (PHPMailer), activé par configuration.
 */
interface MailerInterface
{
    public function send(string $to, string $subject, string $htmlBody): string;

    public function name(): string;
}
