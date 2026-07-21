<?php

declare(strict_types=1);

namespace Keepnew\Notification;

use Keepnew\Core\Database;
use Keepnew\Support\Clock;

/**
 * Moteur d'événements de notification.
 *
 * Chaque événement (booking_created, booking_confirmed, reminder_48h,
 * reminder_2h, technician_en_route, job_completed, review_request,
 * cart_abandoned, booking_cancelled) a des templates par canal (email/SMS) avec
 * un délai relatif. Les envois immédiats partent tout de suite ; les rappels
 * sont programmés (scheduled_at) puis expédiés par cron (dispatchDue()).
 *
 * Toutes les tentatives sont journalisées dans notifications_log.
 */
final class NotificationService
{
    /** Événements dont le délai est relatif au RENDEZ-VOUS (job), pas à « maintenant ». */
    private const APPOINTMENT_ANCHORED = ['reminder_48h', 'reminder_2h', 'technician_en_route'];

    public function __construct(
        private readonly Database $db,
        private readonly TemplateRenderer $renderer,
        private readonly MailerInterface $mailer,
        private readonly SmsProviderInterface $sms,
    ) {
    }

    /**
     * Déclenche un événement pour une commande : programme (ou envoie) toutes
     * les notifications configurées.
     */
    public function trigger(string $eventKey, int $bookingId): void
    {
        $event = $this->db->selectOne('SELECT * FROM notification_events WHERE event_key = :k AND is_active = 1', ['k' => $eventKey]);
        if ($event === null) {
            return;
        }
        $templates = $this->db->select(
            'SELECT * FROM notification_templates WHERE event_id = :e AND is_active = 1',
            ['e' => (int) $event['id']],
        );
        if ($templates === []) {
            return;
        }

        $context = $this->context($bookingId);
        if ($context === null) {
            return;
        }
        $anchor = in_array($eventKey, self::APPOINTMENT_ANCHORED, true) && $context['_job_start'] !== null
            ? new \DateTimeImmutable($context['_job_start'], new \DateTimeZone('UTC'))
            : Clock::nowUtc();

        foreach ($templates as $tpl) {
            $recipient = $tpl['channel'] === 'sms' ? ($context['customer']['phone'] ?? '') : ($context['customer']['email'] ?? '');
            if ($recipient === '' || $recipient === null) {
                continue;
            }
            $scheduledAt = $anchor->modify(sprintf('%+d minutes', (int) $tpl['offset_minutes']));

            $logId = $this->db->insert('notifications_log', [
                'booking_id' => $bookingId,
                'event_key' => $eventKey,
                'channel' => $tpl['channel'],
                'recipient' => (string) $recipient,
                'status' => 'queued',
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
            ]);

            // Envoi immédiat si l'échéance est déjà atteinte.
            if ($scheduledAt <= Clock::nowUtc()) {
                $this->deliver($logId);
            }
        }
    }

    /**
     * Expédie les notifications dues (appelé par cron). Renvoie le nombre traité.
     */
    public function dispatchDue(int $limit = 100): int
    {
        $now = Clock::nowUtc()->format('Y-m-d H:i:s');
        $rows = $this->db->select(
            "SELECT id FROM notifications_log WHERE status = 'queued' AND scheduled_at <= :now ORDER BY scheduled_at LIMIT {$limit}",
            ['now' => $now],
        );
        $count = 0;
        foreach ($rows as $row) {
            if ($this->deliver((int) $row['id'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Rend et envoie une notification journalisée.
     */
    private function deliver(int $logId): bool
    {
        $log = $this->db->selectOne('SELECT * FROM notifications_log WHERE id = :id', ['id' => $logId]);
        if ($log === null || $log['status'] !== 'queued') {
            return false;
        }

        $event = $this->db->selectOne('SELECT id FROM notification_events WHERE event_key = :k', ['k' => $log['event_key']]);
        $tpl = $event !== null ? $this->db->selectOne(
            'SELECT * FROM notification_templates WHERE event_id = :e AND channel = :c AND is_active = 1 LIMIT 1',
            ['e' => (int) $event['id'], 'c' => $log['channel']],
        ) : null;
        $context = $this->context((int) $log['booking_id']);
        if ($tpl === null || $context === null) {
            $this->db->run("UPDATE notifications_log SET status = 'skipped' WHERE id = :id", ['id' => $logId]);

            return false;
        }

        try {
            if ($log['channel'] === 'sms') {
                $body = $this->renderer->render((string) $tpl['body'], $context, false);
                $ref = $this->sms->send((string) $log['recipient'], $body);
            } else {
                $subject = $this->renderer->render((string) ($tpl['subject'] ?? ''), $context, false);
                $body = $this->renderer->render((string) $tpl['body'], $context, true);
                $ref = $this->mailer->send((string) $log['recipient'], $subject, $body);
            }
            $this->db->run(
                "UPDATE notifications_log SET status = 'sent', provider_ref = :ref, sent_at = UTC_TIMESTAMP() WHERE id = :id",
                ['ref' => $ref, 'id' => $logId],
            );

            return true;
        } catch (\Throwable $e) {
            $this->db->run(
                "UPDATE notifications_log SET status = 'failed', error = :err WHERE id = :id",
                ['err' => substr($e->getMessage(), 0, 255), 'id' => $logId],
            );

            return false;
        }
    }

    /**
     * Construit le contexte de rendu depuis la commande.
     *
     * @return array<string, mixed>|null
     */
    private function context(int $bookingId): ?array
    {
        $booking = $this->db->selectOne(
            'SELECT b.*, c.first_name, c.last_name, c.email, c.phone FROM bookings b JOIN customers c ON c.id = b.customer_id WHERE b.id = :id',
            ['id' => $bookingId],
        );
        if ($booking === null) {
            return null;
        }
        $job = $this->db->selectOne(
            "SELECT j.scheduled_start, j.arrival_from, t.first_name AS tech_first
             FROM jobs j LEFT JOIN technicians t ON t.id = j.technician_id
             WHERE j.booking_id = :b AND j.scheduled_start IS NOT NULL ORDER BY j.scheduled_start LIMIT 1",
            ['b' => $bookingId],
        );

        $jobStart = $job['scheduled_start'] ?? null;
        $jobLocal = $jobStart !== null ? Clock::format(new \DateTimeImmutable($jobStart, new \DateTimeZone('UTC')), 'd/m/Y à H:i') : '';
        $arrival = $job['arrival_from'] ?? null;

        return [
            '_job_start' => $jobStart,
            'booking' => [
                'reference' => $booking['reference'],
                'total' => number_format(((int) $booking['total_cents']) / 100, 2, ',', ' ') . ' €',
                'manage_url' => '/rdv/' . $booking['manage_token'],
            ],
            'customer' => [
                'first_name' => $booking['first_name'],
                'last_name' => $booking['last_name'],
                'email' => $booking['email'],
                'phone' => $booking['phone'],
            ],
            'job' => [
                'date' => $jobLocal,
                'arrival_from' => $arrival !== null ? Clock::format(new \DateTimeImmutable($arrival, new \DateTimeZone('UTC')), 'H:i') : '',
            ],
            'technician' => [
                'first_name' => $job['tech_first'] ?? '',
            ],
        ];
    }
}
