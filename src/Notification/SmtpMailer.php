<?php

declare(strict_types=1);

namespace Keepnew\Notification;

/**
 * Mailer SMTP via PHPMailer (activé par configuration en production).
 *
 * PHPMailer est chargé par Composer. Si la classe n'est pas disponible, la
 * fabrique retombe sur LogMailer — l'application démarre donc sans SMTP.
 */
final class SmtpMailer implements MailerInterface
{
    /**
     * @param array{host:string, port:int, user:string, password:string, encryption:string, from_address:string, from_name:string} $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function name(): string
    {
        return 'smtp';
    }

    public function send(string $to, string $subject, string $htmlBody): string
    {
        $class = 'PHPMailer\\PHPMailer\\PHPMailer';
        if (!class_exists($class)) {
            throw new \RuntimeException('PHPMailer non installé.');
        }

        /** @var \PHPMailer\PHPMailer\PHPMailer $mail */
        $mail = new $class(true);
        $mail->isSMTP();
        $mail->Host = $this->config['host'];
        $mail->Port = $this->config['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $this->config['user'];
        $mail->Password = $this->config['password'];
        $mail->SMTPSecure = $this->config['encryption'];
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($this->config['from_address'], $this->config['from_name']);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->send();

        return 'smtp:sent';
    }
}
