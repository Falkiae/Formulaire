<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Core\Csrf;
use Keepnew\Core\Database;
use Keepnew\Core\Exception\NotFoundException;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Session;
use Keepnew\Core\View;
use Keepnew\Support\Clock;

/**
 * Fiche job : client, prestations, réponses au formulaire, photos avant/après,
 * historique de statut, notes internes, et replanification (date/heure +
 * technicien) réutilisant la détection de conflit/trajet du dispatch.
 */
final class JobController
{
    private const STATUSES = ['scheduled', 'en_route', 'in_progress', 'completed', 'cancelled', 'no_show'];

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Database $db,
        private readonly DispatchService $dispatch,
    ) {
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $job = $this->db->selectOne(
            'SELECT j.*, b.reference, b.id AS booking_id, c.id AS customer_id, c.first_name, c.last_name, c.phone, c.email,
                    a.street, a.number, a.postal_code, a.city, a.access_notes,
                    t.first_name AS tech_first, t.last_name AS tech_last
             FROM jobs j
             JOIN bookings b ON b.id = j.booking_id
             JOIN customers c ON c.id = b.customer_id
             LEFT JOIN addresses a ON a.id = j.address_id
             LEFT JOIN technicians t ON t.id = j.technician_id
             WHERE j.id = :id',
            ['id' => $id],
        );
        if ($job === null) {
            throw new NotFoundException('Job introuvable.');
        }

        $bookingId = (int) $job['booking_id'];

        // Pré-remplissage du formulaire de replanification (heure belge).
        $scheduledLocal = $job['scheduled_start'] !== null
            ? Clock::format(new \DateTimeImmutable((string) $job['scheduled_start'] . ' UTC'), 'Y-m-d\TH:i')
            : '';

        return $this->view->render('admin/job', [
            'csrf' => $this->csrf->field(),
            'job' => $job,
            'statuses' => self::STATUSES,
            'technicians' => $this->dispatch->activeTechnicians(),
            'scheduled_local' => $scheduledLocal,
            'items' => $this->db->select('SELECT label_snapshot, quantity, line_total_cents FROM booking_items WHERE job_id = :j', ['j' => $id]),
            'answers' => $this->db->select('SELECT field_key, value_text FROM booking_answers WHERE booking_id = :b', ['b' => $bookingId]),
            'photos' => $this->db->select('SELECT kind, file_path FROM booking_photos WHERE job_id = :j', ['j' => $id]),
            'history' => $this->db->select('SELECT old_status, new_status, note, created_at FROM booking_status_history WHERE booking_id = :b ORDER BY id DESC', ['b' => $bookingId]),
            'notes' => $this->db->select('SELECT n.body, n.created_at, u.first_name FROM customer_notes n LEFT JOIN users u ON u.id = n.author_id WHERE n.customer_id = :c ORDER BY n.id DESC', ['c' => (int) $job['customer_id']]),
            'user_name' => $this->session->get('user_name'),
            'flash' => $this->session->pullFlash('job_ok'),
        ]);
    }

    public function updateStatus(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $status = $request->string('status');
        if (!in_array($status, self::STATUSES, true)) {
            $this->session->flash('job_ok', 'Statut invalide.');

            return Response::redirect("/admin/job/{$id}");
        }

        $job = $this->db->selectOne('SELECT status, booking_id FROM jobs WHERE id = :id', ['id' => $id]);
        if ($job === null) {
            throw new NotFoundException('Job introuvable.');
        }

        $this->db->transaction(function (Database $db) use ($id, $status, $job): void {
            $db->run('UPDATE jobs SET status = :s WHERE id = :id', ['s' => $status, 'id' => $id]);
            $db->insert('booking_status_history', [
                'booking_id' => (int) $job['booking_id'],
                'job_id' => $id,
                'old_status' => $job['status'],
                'new_status' => $status,
                'changed_by' => $this->session->userId(),
                'note' => 'Statut du job mis à jour',
            ]);
        });

        $this->session->flash('job_ok', 'Statut mis à jour.');

        return Response::redirect("/admin/job/{$id}");
    }

    /**
     * POST /admin/job/{id}/planifier — replanifie (date/heure + technicien).
     * Réutilise DispatchService::reassign (conflit dur refusé, trajet signalé).
     */
    public function updateSchedule(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        if ($this->db->selectOne('SELECT id FROM jobs WHERE id = :id', ['id' => $id]) === null) {
            throw new NotFoundException('Job introuvable.');
        }

        $technicianId = $request->int('technician_id');
        $local = $request->string('scheduled_start'); // "Y-m-dTH:i" (heure belge)
        if ($technicianId <= 0 || $local === '') {
            $this->session->flash('job_ok', 'Indiquez une date/heure et un technicien.');

            return Response::redirect("/admin/job/{$id}");
        }

        // Heure belge saisie → instant UTC non ambigu (offset explicite).
        $startUtc = Clock::fromDisplay(str_replace('T', ' ', $local))->format('c');
        $result = $this->dispatch->reassign($id, $technicianId, $startUtc, $this->session->userId());

        if ($result['conflict']) {
            $this->session->flash('job_ok', 'Conflit : ce technicien a déjà un rendez-vous sur ce créneau. Aucune modification.');
        } elseif (!$result['travel_fits']) {
            $this->session->flash('job_ok', 'Rendez-vous déplacé, mais le trajet ne tient pas dans le planning (à vérifier).');
        } else {
            $this->session->flash('job_ok', 'Rendez-vous déplacé et réassigné.');
        }

        return Response::redirect("/admin/job/{$id}");
    }

    public function addNote(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $customerId = $request->int('customer_id');
        $body = $request->string('body');
        if ($body !== '' && $customerId > 0) {
            $this->db->insert('customer_notes', [
                'customer_id' => $customerId,
                'author_id' => $this->session->userId(),
                'body' => $body,
            ]);
            $this->session->flash('job_ok', 'Note ajoutée.');
        }

        return Response::redirect("/admin/job/{$id}");
    }
}
