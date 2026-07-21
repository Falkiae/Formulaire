<?php

declare(strict_types=1);

namespace Keepnew\Notification;

/**
 * Mailer de secours / développement : écrit l'email dans un fichier de log
 * (hors webroot) au lieu de l'envoyer. Actif tant qu'aucun SMTP n'est configuré.
 */
final class LogMailer implements MailerInterface
{
    public function __construct(private readonly string $logDir)
    {
    }

    public function name(): string
    {
        return 'log';
    }

    public function send(string $to, string $subject, string $htmlBody): string
    {
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0775, true);
        }
        $ref = 'log:' . substr(sha1($to . $subject . microtime()), 0, 12);
        $entry = sprintf(
            "[%s] To: %s | Subject: %s | Ref: %s\n%s\n%s\n",
            gmdate('c'),
            $to,
            $subject,
            $ref,
            $htmlBody,
            str_repeat('-', 60),
        );
        @file_put_contents($this->logDir . '/mail.log', $entry, FILE_APPEND);

        return $ref;
    }
}
