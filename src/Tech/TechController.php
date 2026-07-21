<?php

declare(strict_types=1);

namespace Keepnew\Tech;

use Keepnew\Core\Csrf;
use Keepnew\Core\Database;
use Keepnew\Core\Exception\HttpException;
use Keepnew\Core\Exception\NotFoundException;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Session;
use Keepnew\Core\View;
use Keepnew\Notification\NotificationService;
use Keepnew\Support\Clock;
use Keepnew\Support\ImageUpload;

/**
 * App technicien (PWA) : planning du jour, fiche job, pointage CP 121, statut,
 * photos avant/après, signature client, encaissement sur place.
 */
final class TechController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Database $db,
        private readonly TimeEntryService $time,
        private readonly NotificationService $notifications,
        private readonly ImageUpload $uploads,
    ) {
    }

    /**
     * Résout le technicien lié à l'utilisateur connecté.
     *
     * @return array<string, mixed>
     */
    private function technician(): array
    {
        $userId = $this->session->userId();
        $tech = $userId !== null ? $this->db->selectOne('SELECT * FROM technicians WHERE user_id = :u', ['u' => $userId]) : null;
        if ($tech === null) {
            throw new HttpException(403, 'Compte technicien introuvable.');
        }

        return $tech;
    }

    /**
     * GET /tech — planning du jour.
     */
    public function planning(Request $request): Response
    {
        $tech = $this->technician();
        $date = $request->string('date') ?: Clock::format(Clock::nowUtc(), 'Y-m-d');
        $window = \Keepnew\Availability\ScheduleBuilder::windowForDate($date, '00:00', '23:59');

        $jobs = $this->db->select(
            "SELECT j.id, j.mode, j.status, j.scheduled_start, j.scheduled_end, j.arrival_from,
                    b.reference, c.first_name, c.last_name, c.phone,
                    a.street, a.number, a.postal_code, a.city, a.lat, a.lng,
                    GROUP_CONCAT(bi.label_snapshot SEPARATOR ' + ') AS services
             FROM jobs j
             JOIN bookings b ON b.id = j.booking_id
             JOIN customers c ON c.id = b.customer_id
             LEFT JOIN addresses a ON a.id = j.address_id
             LEFT JOIN booking_items bi ON bi.job_id = j.id
             WHERE j.technician_id = :t AND j.status NOT IN ('cancelled')
               AND j.scheduled_start BETWEEN :from AND :to
             GROUP BY j.id ORDER BY j.scheduled_start",
            ['t' => (int) $tech['id'], 'from' => $window->start->format('Y-m-d H:i:s'), 'to' => $window->end->format('Y-m-d H:i:s')],
        );

        return $this->view->render('tech/planning', [
            'tech' => $tech,
            'date' => $date,
            'jobs' => array_map(fn (array $j): array => $this->formatJob($j), $jobs),
            'user_name' => $this->session->get('user_name'),
        ]);
    }

    /**
     * GET /tech/job/{id}
     */
    public function job(Request $request): Response
    {
        $tech = $this->technician();
        $job = $this->loadJob((int) $request->attribute('id'), (int) $tech['id']);

        $bookingId = (int) $job['booking_id'];

        return $this->view->render('tech/job', [
            'csrf' => $this->csrf->field(),
            'job' => $job,
            'last_punch' => $this->time->lastType((int) $job['id']),
            'answers' => $this->db->select('SELECT field_key, value_text FROM booking_answers WHERE booking_id = :b', ['b' => $bookingId]),
            'photos' => $this->db->select('SELECT id, kind FROM booking_photos WHERE job_id = :j ORDER BY id', ['j' => (int) $job['id']]),
            'payment' => $this->db->selectOne('SELECT status, method, amount_cents FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => $bookingId]),
            'user_name' => $this->session->get('user_name'),
        ]);
    }

    /**
     * POST /tech/job/{id}/pointer — pointage start/stop.
     */
    public function punch(Request $request): Response
    {
        $tech = $this->technician();
        $job = $this->loadJob((int) $request->attribute('id'), (int) $tech['id']);
        $type = $request->string('type') === 'stop' ? 'stop' : 'start';

        $this->time->punch(
            (int) $tech['id'],
            (int) $job['id'],
            $type,
            $request->has('lat') ? (float) $request->string('lat') : null,
            $request->has('lng') ? (float) $request->string('lng') : null,
        );

        return Response::redirect('/tech/job/' . (int) $job['id']);
    }

    /**
     * POST /tech/job/{id}/statut — en_route / in_progress / completed.
     */
    public function status(Request $request): Response
    {
        $tech = $this->technician();
        $job = $this->loadJob((int) $request->attribute('id'), (int) $tech['id']);
        $status = $request->string('status');
        if (!in_array($status, ['en_route', 'in_progress', 'completed', 'no_show'], true)) {
            return Response::redirect('/tech/job/' . (int) $job['id']);
        }

        $this->db->transaction(function (Database $db) use ($job, $status): void {
            $db->run('UPDATE jobs SET status = :s WHERE id = :id', ['s' => $status, 'id' => (int) $job['id']]);
            $db->insert('booking_status_history', [
                'booking_id' => (int) $job['booking_id'], 'job_id' => (int) $job['id'],
                'old_status' => $job['status'], 'new_status' => $status,
                'changed_by' => $this->session->userId(), 'note' => 'Mise à jour terrain',
            ]);
        });

        // Notifications & mobilité selon la transition.
        if ($status === 'en_route') {
            $this->notifications->trigger('technician_en_route', (int) $job['booking_id']);
        } elseif ($status === 'completed') {
            $this->notifications->trigger('job_completed', (int) $job['booking_id']);
            $this->time->createMobilityForJob((int) $job['id']);
        }

        return Response::redirect('/tech/job/' . (int) $job['id']);
    }

    /**
     * POST /tech/job/{id}/photo — upload photo avant/après (sécurisé).
     */
    public function photo(Request $request): Response
    {
        $tech = $this->technician();
        $job = $this->loadJob((int) $request->attribute('id'), (int) $tech['id']);
        $kind = in_array($request->string('kind'), ['before', 'after'], true) ? $request->string('kind') : 'after';

        $file = $request->file('photo');
        if ($file !== null) {
            try {
                $path = $this->uploads->store($file, 'photos');
                $this->db->insert('booking_photos', [
                    'booking_id' => (int) $job['booking_id'],
                    'job_id' => (int) $job['id'],
                    'kind' => $kind,
                    'file_path' => $path,
                    'uploaded_by' => $this->session->userId(),
                ]);
            } catch (\RuntimeException $e) {
                // Échec silencieux côté UX ; on pourrait flasher l'erreur.
            }
        }

        return Response::redirect('/tech/job/' . (int) $job['id']);
    }

    /**
     * POST /tech/job/{id}/signature — signature client (data URL PNG).
     */
    public function signature(Request $request): Response
    {
        $tech = $this->technician();
        $job = $this->loadJob((int) $request->attribute('id'), (int) $tech['id']);
        $dataUrl = $request->string('signature');

        if (str_starts_with($dataUrl, 'data:image/png;base64,')) {
            $binary = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);
            if ($binary !== false && strlen($binary) < 500_000) {
                $dir = dirname(__DIR__, 2) . '/storage/uploads/signatures/' . date('Y/m');
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $rel = 'signatures/' . date('Y/m') . '/' . bin2hex(random_bytes(12)) . '.png';
                @file_put_contents(dirname(__DIR__, 2) . '/storage/uploads/' . $rel, $binary);
                $this->db->insert('booking_photos', [
                    'booking_id' => (int) $job['booking_id'],
                    'job_id' => (int) $job['id'],
                    'kind' => 'intake', // réutilise l'enum ; distingué par le chemin
                    'file_path' => $rel,
                    'uploaded_by' => $this->session->userId(),
                ]);
            }
        }

        return Response::redirect('/tech/job/' . (int) $job['id']);
    }

    /**
     * POST /tech/job/{id}/encaisser — encaissement sur place (espèces).
     */
    public function collect(Request $request): Response
    {
        $tech = $this->technician();
        $job = $this->loadJob((int) $request->attribute('id'), (int) $tech['id']);
        $method = in_array($request->string('method'), ['cash', 'bancontact', 'transfer'], true) ? $request->string('method') : 'cash';

        $booking = $this->db->selectOne('SELECT total_cents FROM bookings WHERE id = :id', ['id' => (int) $job['booking_id']]);

        $this->db->transaction(function (Database $db) use ($job, $booking, $method): void {
            $db->insert('payments', [
                'booking_id' => (int) $job['booking_id'],
                'gateway' => 'null',
                'method' => $method,
                'amount_cents' => (int) ($booking['total_cents'] ?? 0),
                'status' => 'paid',
                'paid_at' => Clock::nowUtc()->format('Y-m-d H:i:s'),
                'collected_by' => $this->session->userId(),
            ]);
            $db->run("UPDATE bookings SET payment_status = 'paid' WHERE id = :id", ['id' => (int) $job['booking_id']]);
        });

        return Response::redirect('/tech/job/' . (int) $job['id']);
    }

    /**
     * GET /tech/photo/{id} — sert une photo stockée hors webroot (accès contrôlé).
     */
    public function servePhoto(Request $request): Response
    {
        $this->technician(); // exige un technicien authentifié
        $photo = $this->db->selectOne('SELECT file_path FROM booking_photos WHERE id = :id', ['id' => (int) $request->attribute('id')]);
        if ($photo === null) {
            throw new NotFoundException('Photo introuvable.');
        }
        $path = dirname(__DIR__, 2) . '/storage/uploads/' . $photo['file_path'];
        if (!is_file($path)) {
            throw new NotFoundException('Fichier absent.');
        }
        $mime = str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg';

        return Response::html((string) file_get_contents($path))->withHeader('Content-Type', $mime);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJob(int $jobId, int $technicianId): array
    {
        $job = $this->db->selectOne(
            'SELECT j.*, b.reference, b.booking_mode, b.id AS booking_id, b.total_cents, b.payment_status,
                    c.first_name, c.last_name, c.phone, c.email,
                    a.street, a.number, a.postal_code, a.city, a.lat, a.lng, a.access_notes
             FROM jobs j
             JOIN bookings b ON b.id = j.booking_id
             JOIN customers c ON c.id = b.customer_id
             LEFT JOIN addresses a ON a.id = j.address_id
             WHERE j.id = :id AND j.technician_id = :t',
            ['id' => $jobId, 't' => $technicianId],
        );
        if ($job === null) {
            throw new NotFoundException('Rendez-vous introuvable.');
        }
        $job['services'] = $this->db->scalar("SELECT GROUP_CONCAT(label_snapshot SEPARATOR ' + ') FROM booking_items WHERE job_id = :j", ['j' => $jobId]);

        return $job;
    }

    /**
     * @param array<string, mixed> $j
     * @return array<string, mixed>
     */
    private function formatJob(array $j): array
    {
        $start = $j['scheduled_start'] !== null ? new \DateTimeImmutable((string) $j['scheduled_start'], new \DateTimeZone('UTC')) : null;

        return [
            'id' => (int) $j['id'],
            'reference' => $j['reference'],
            'mode' => $j['mode'],
            'status' => $j['status'],
            'customer' => trim(($j['first_name'] ?? '') . ' ' . ($j['last_name'] ?? '')),
            'phone' => $j['phone'] ?? null,
            'services' => $j['services'] ?? '',
            'address' => trim(($j['street'] ?? '') . ' ' . ($j['number'] ?? '') . ', ' . ($j['postal_code'] ?? '') . ' ' . ($j['city'] ?? '')),
            'lat' => $j['lat'] ?? null,
            'lng' => $j['lng'] ?? null,
            'start_local' => $start !== null ? Clock::format($start, 'H:i') : null,
        ];
    }
}
