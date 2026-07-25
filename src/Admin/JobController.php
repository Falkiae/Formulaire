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
use Keepnew\Geo\NominatimGeocoder;
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
        private readonly RescheduleAvailabilityService $availability,
        private readonly NominatimGeocoder $geocoder,
    ) {
    }

    /**
     * GET /admin/job/{id}[?partial=1] — la fiche job. En partiel (appelée en
     * fetch() depuis le panneau coulissant du calendrier/dispatch), ne rend
     * que le contenu (admin/job/_panel), sans le châssis de page — même
     * template que la page complète, réutilisé aux deux endroits pour ne rien
     * dupliquer. Repli naturel sans JS ou sur mobile : la page complète.
     */
    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $data = $this->jobData($id);

        if ($request->bool('partial')) {
            return Response::html($this->view->capture('admin/job/_panel', $data));
        }

        return $this->view->render('admin/job', $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function jobData(int $id): array
    {
        $job = $this->db->selectOne(
            'SELECT j.*, b.reference, b.id AS booking_id, c.id AS customer_id, c.first_name, c.last_name, c.phone, c.email,
                    a.street, a.number, a.postal_code, a.city, a.access_notes, a.lat, a.lng,
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

        // Comble a posteriori les coordonnées manquantes (adresse créée avant
        // l'ajout du géocodage) — ponctuel, jamais bloquant : la fiche s'affiche
        // normalement même si le géocodage échoue.
        if ($job['mode'] === 'onsite' && $job['lat'] === null && $job['address_id'] !== null && ($job['street'] ?? '') !== '') {
            $coords = $this->geocoder->geocode(
                (string) $job['street'],
                (string) ($job['number'] ?? ''),
                (string) $job['postal_code'],
                (string) $job['city'],
            );
            if ($coords !== null) {
                $this->db->run(
                    'UPDATE addresses SET lat = :lat, lng = :lng WHERE id = :id',
                    ['lat' => $coords['lat'], 'lng' => $coords['lng'], 'id' => (int) $job['address_id']],
                );
                $job['lat'] = $coords['lat'];
                $job['lng'] = $coords['lng'];
            }
        }

        $bookingId = (int) $job['booking_id'];

        // Pré-remplissage du formulaire de replanification (heure belge).
        $scheduledLocal = $job['scheduled_start'] !== null
            ? Clock::format(new \DateTimeImmutable((string) $job['scheduled_start'] . ' UTC'), 'Y-m-d\TH:i')
            : '';

        return [
            'csrf' => $this->csrf->field(),
            'csrf_token' => $this->csrf->token(),
            'job' => $job,
            'statuses' => self::STATUSES,
            'technicians' => $this->dispatch->activeTechnicians(),
            'active_bays' => $this->dispatch->activeBays(),
            'customer_addresses' => $this->db->select(
                'SELECT id, label, street, number, postal_code, city FROM addresses WHERE customer_id = :c ORDER BY id',
                ['c' => (int) $job['customer_id']],
            ),
            'scheduled_local' => $scheduledLocal,
            'items' => $this->db->select('SELECT label_snapshot, quantity, line_total_cents FROM booking_items WHERE job_id = :j', ['j' => $id]),
            'answers' => $this->db->select('SELECT field_key, value_text FROM booking_answers WHERE booking_id = :b', ['b' => $bookingId]),
            'photos' => $this->db->select('SELECT kind, file_path FROM booking_photos WHERE job_id = :j', ['j' => $id]),
            'history' => $this->db->select('SELECT old_status, new_status, note, created_at FROM booking_status_history WHERE booking_id = :b ORDER BY id DESC', ['b' => $bookingId]),
            'notes' => $this->db->select('SELECT n.body, n.created_at, u.first_name FROM customer_notes n LEFT JOIN users u ON u.id = n.author_id WHERE n.customer_id = :c ORDER BY n.id DESC', ['c' => (int) $job['customer_id']]),
            'user_name' => $this->session->get('user_name'),
            'flash' => $this->session->pullFlash('job_ok'),
        ];
    }

    /**
     * GET /admin/job/{id}/creneaux/mois?month=YYYY-MM — dates du mois ayant au
     * moins un créneau réellement libre (JSON, pour griser le mini-calendrier).
     */
    public function slotDates(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $month = $request->string('month');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = Clock::format(Clock::nowUtc(), 'Y-m');
        }

        try {
            return Response::json(['dates' => $this->availability->datesWithSlots($id, $month)]);
        } catch (\Throwable) {
            return Response::json(['error' => 'Erreur lors du calcul des disponibilités.'], 500);
        }
    }

    /**
     * GET /admin/job/{id}/creneaux?date=YYYY-MM-DD — créneaux réellement
     * libres de ce jour, groupés par heure avec les techniciens disponibles.
     */
    public function slotsForDate(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $date = $request->string('date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return Response::json(['error' => 'Date invalide.'], 422);
        }

        try {
            return Response::json(['slots' => $this->availability->slotsForDate($id, $date)]);
        } catch (\Throwable) {
            return Response::json(['error' => 'Erreur lors du calcul des disponibilités.'], 500);
        }
    }

    public function updateStatus(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $status = $request->string('status');
        if (!in_array($status, self::STATUSES, true)) {
            $this->session->flash('job_ok', 'Statut invalide.');

            return $this->finish($request, $id);
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

        return $this->finish($request, $id);
    }

    /**
     * POST /admin/job/{id}/planifier — replanifie (date/heure + technicien).
     * Réutilise DispatchService::reassign (conflit dur refusé, trajet signalé).
     */
    public function updateSchedule(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $job = $this->db->selectOne('SELECT mode FROM jobs WHERE id = :id', ['id' => $id]);
        if ($job === null) {
            throw new NotFoundException('Job introuvable.');
        }

        $technicianId = $request->int('technician_id');
        $local = $request->string('scheduled_start'); // "Y-m-dTH:i" (heure belge)
        if ($technicianId <= 0 || $local === '') {
            $this->session->flash('job_ok', 'Indiquez une date/heure et un technicien.');

            return $this->finish($request, $id);
        }

        // Heure belge saisie → instant UTC non ambigu (offset explicite).
        $startUtc = Clock::fromDisplay(str_replace('T', ' ', $local))->format('c');
        $userId = $this->session->userId();

        $targetMode = $request->string('mode');
        if (!in_array($targetMode, ['onsite', 'workshop'], true)) {
            $targetMode = (string) $job['mode'];
        }

        // Mode inchangé → simple réassignation (conserve poste/adresse).
        if ($targetMode === (string) $job['mode']) {
            $result = $this->dispatch->reassign($id, $technicianId, $startUtc, $userId);

            if ($result['conflict']) {
                $this->session->flash('job_ok', 'Conflit : ce technicien a déjà un rendez-vous sur ce créneau. Aucune modification.');
            } elseif (!$result['travel_fits']) {
                $this->session->flash('job_ok', 'Rendez-vous déplacé, mais le trajet ne tient pas dans le planning (à vérifier).');
            } else {
                $this->session->flash('job_ok', 'Rendez-vous déplacé et réassigné.');
            }

            return $this->finish($request, $id);
        }

        // Changement de mode : garde-fous ressources.
        $bayId = $request->int('bay_id');
        $addressId = $request->int('address_id');
        if ($targetMode === 'workshop' && $bayId <= 0) {
            $this->session->flash('job_ok', 'Passage en atelier : choisissez un poste de travail.');

            return $this->finish($request, $id);
        }
        if ($targetMode === 'onsite' && $addressId <= 0) {
            $this->session->flash('job_ok', 'Passage à domicile : choisissez une adresse (ajoutez-en une sur la fiche client si besoin).');

            return $this->finish($request, $id);
        }

        $result = $this->dispatch->reschedule(
            $id,
            $technicianId,
            $targetMode,
            $bayId > 0 ? $bayId : null,
            $addressId > 0 ? $addressId : null,
            $startUtc,
            $userId,
        );

        if ($result['unsupported_mode']) {
            $this->session->flash('job_ok', 'Une prestation de ce rendez-vous n\'est pas proposée en ' . ($targetMode === 'workshop' ? 'atelier' : 'domicile') . '. Changement refusé.');
        } elseif ($result['invalid']) {
            $this->session->flash('job_ok', 'Données de replanification invalides.');
        } elseif ($result['conflict']) {
            $this->session->flash('job_ok', 'Conflit : ce technicien a déjà un rendez-vous sur ce créneau. Aucune modification.');
        } elseif ($result['bay_conflict']) {
            $this->session->flash('job_ok', 'Conflit : ce poste d\'atelier est déjà occupé sur ce créneau. Aucune modification.');
        } elseif (!$result['travel_fits']) {
            $this->session->flash('job_ok', 'Mode changé et rendez-vous déplacé, mais le trajet ne tient pas dans le planning (à vérifier).');
        } else {
            $this->session->flash('job_ok', 'Mode changé (' . ($targetMode === 'workshop' ? 'atelier' : 'domicile') . ') et rendez-vous replanifié. Prix inchangé.');
        }

        return $this->finish($request, $id);
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

        return $this->finish($request, $id);
    }

    /**
     * Après une mutation (statut, replanification, note) : si la requête vient
     * du panneau coulissant (`ajax=1`), renvoie le panneau fraîchement rendu
     * (le flash qu'on vient de poser y est déjà inclus) pour un rafraîchissement
     * en place ; sinon repli classique POST-redirect-GET sur la page complète.
     */
    private function finish(Request $request, int $id): Response
    {
        if ($request->bool('ajax')) {
            return Response::html($this->view->capture('admin/job/_panel', $this->jobData($id)));
        }

        return Response::redirect("/admin/job/{$id}");
    }
}
