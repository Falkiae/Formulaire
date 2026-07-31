<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Booking\BookingEditService;
use Keepnew\Booking\BookingService;
use Keepnew\Catalog\CatalogRepository;
use Keepnew\Core\Csrf;
use Keepnew\Core\Database;
use Keepnew\Core\Exception\HttpException;
use Keepnew\Core\Exception\NotFoundException;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Session;
use Keepnew\Core\View;
use Keepnew\Geo\NominatimGeocoder;
use Keepnew\Notification\NotificationService;
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
        private readonly BookingService $bookings,
        private readonly NotificationService $notifications,
        private readonly BookingEditService $edits,
        private readonly CatalogRepository $catalog,
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
            // Une commande = un mode = un rendez-vous (BookingService::assertSingleMode).
            // Seules les commandes antérieures à cette règle en comptent
            // plusieurs ; c'est le seul cas où annuler un rendez-vous isolé a
            // un sens distinct d'annuler la commande.
            'booking_job_count' => (int) $this->db->scalar(
                'SELECT COUNT(*) FROM jobs WHERE booking_id = :b',
                ['b' => $bookingId],
            ),
            'review_sent_at' => $this->reviewRequestSentAt($bookingId),
            // Chaque ligne porte ses extras posés et ceux encore rattachables :
            // les extras font partie du prix de la ligne, ils doivent se lire et
            // se modifier au même endroit.
            'items' => array_map(
                fn (array $it): array => $it + [
                    'extras' => $this->edits->extrasOf((int) $it['id']),
                    'attachable_extras' => $this->edits->attachableExtras($it),
                ],
                $this->db->select(
                    'SELECT id, service_id, label_snapshot, quantity, unit_price_cents, unit_duration_min, line_total_cents
                       FROM booking_items WHERE job_id = :j ORDER BY id',
                    ['j' => $id],
                ),
            ),
            // Totaux de la commande : c'est eux que la retouche fait bouger.
            'booking' => $this->db->selectOne(
                'SELECT subtotal_cents, discount_cents, travel_surcharge_cents, vat_cents, total_cents, vat_rate_bp
                   FROM bookings WHERE id = :b',
                ['b' => $bookingId],
            ),
            // Prestations proposables : seulement celles servies dans le mode du
            // rendez-vous — ajouter un service atelier à une intervention à
            // domicile n'aurait pas de tarif applicable.
            'catalog_services' => $this->db->select(
                "SELECT s.id, s.name, m.price_cents
                   FROM services s
                   JOIN service_delivery_modes m ON m.service_id = s.id AND m.mode = :mode
                  WHERE s.is_active = 1
                  ORDER BY s.name",
                ['mode' => (string) $job['mode']],
            ),
            'edit_block' => $this->editBlockReason($bookingId),
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
        [$mode, $addressId] = $this->slotModeParams($request);

        return Response::json(['dates' => $this->availability->datesWithSlots($id, $month, $mode, $addressId)])
            ->withHeader('Cache-Control', 'no-store');
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
        [$mode, $addressId] = $this->slotModeParams($request);

        return Response::json(['slots' => $this->availability->slotsForDate($id, $date, $mode, $addressId)])
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Lit les surcharges optionnelles de mode/adresse de la bascule
     * Domicile/Atelier du sélecteur (Phase Y) — absentes = mode actuel du job.
     *
     * @return array{0:?string, 1:?int}
     */
    private function slotModeParams(Request $request): array
    {
        $mode = $request->string('mode');
        $mode = in_array($mode, ['onsite', 'workshop'], true) ? $mode : null;
        $addressId = $request->int('address_id');

        return [$mode, $addressId > 0 ? $addressId : null];
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
     * POST /admin/job/{id}/ligne — ajoute une prestation, du catalogue ou sur
     * mesure, au rendez-vous.
     */
    public function addLine(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $bookingId = $this->bookingIdOf($id);

        try {
            if ($request->string('kind') === 'custom') {
                $this->edits->addCustomLine(
                    $bookingId,
                    $id,
                    $request->string('label'),
                    $this->cents($request->string('price')),
                    $request->int('quantity', 1),
                    $request->int('duration_min'),
                );
                $message = 'Prestation sur mesure ajoutée.';
            } else {
                $variantId = $request->int('variant_id');
                $this->edits->addCatalogLine(
                    $bookingId,
                    $id,
                    $request->int('service_id'),
                    $variantId > 0 ? $variantId : null,
                    $request->int('quantity', 1),
                );
                $message = 'Prestation ajoutée.';
            }
            $this->session->flash('job_ok', $message . $this->overlapWarning($id));
        } catch (HttpException | NotFoundException $e) {
            $this->session->flash('job_ok', $e->getMessage());
        }

        return $this->finish($request, $id);
    }

    /**
     * POST /admin/job/{id}/ligne/{itemId} — corrige prix et quantité.
     */
    public function updateLine(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->edits->updateLine(
                $this->bookingIdOf($id),
                (int) $request->attribute('itemId'),
                $this->cents($request->string('price')),
                $request->int('quantity', 1),
            );
            $this->session->flash('job_ok', 'Ligne mise à jour.' . $this->overlapWarning($id));
        } catch (HttpException | NotFoundException $e) {
            $this->session->flash('job_ok', $e->getMessage());
        }

        return $this->finish($request, $id);
    }

    /**
     * POST /admin/job/{id}/ligne/{itemId}/supprimer
     */
    public function removeLine(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->edits->removeLine($this->bookingIdOf($id), (int) $request->attribute('itemId'));
            $this->session->flash('job_ok', 'Ligne retirée.');
        } catch (HttpException | NotFoundException $e) {
            $this->session->flash('job_ok', $e->getMessage());
        }

        return $this->finish($request, $id);
    }

    /**
     * POST /admin/job/{id}/ligne/{itemId}/extra — rattache un extra à la ligne.
     */
    public function addExtra(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->edits->addExtra(
                $this->bookingIdOf($id),
                (int) $request->attribute('itemId'),
                $request->int('extra_id'),
            );
            $this->session->flash('job_ok', 'Extra ajouté.' . $this->overlapWarning($id));
        } catch (HttpException | NotFoundException $e) {
            $this->session->flash('job_ok', $e->getMessage());
        }

        return $this->finish($request, $id);
    }

    /**
     * POST /admin/job/{id}/ligne/{itemId}/extra/{extraRowId}/supprimer
     */
    public function removeExtra(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->edits->removeExtra(
                $this->bookingIdOf($id),
                (int) $request->attribute('itemId'),
                (int) $request->attribute('extraRowId'),
            );
            $this->session->flash('job_ok', 'Extra retiré.');
        } catch (HttpException | NotFoundException $e) {
            $this->session->flash('job_ok', $e->getMessage());
        }

        return $this->finish($request, $id);
    }

    /**
     * POST /admin/job/{id}/remise — remise sur la commande, en € ou en %.
     */
    public function setDiscount(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $bookingId = $this->bookingIdOf($id);
            if ($request->string('unit') === 'percent') {
                $percent = (float) str_replace(',', '.', $request->string('value'));
                $this->edits->setDiscountPercent($bookingId, (int) round($percent * 100));
            } else {
                $this->edits->setDiscount($bookingId, $this->cents($request->string('value')));
            }
            $this->session->flash('job_ok', 'Remise appliquée.');
        } catch (HttpException | NotFoundException $e) {
            $this->session->flash('job_ok', $e->getMessage());
        }

        return $this->finish($request, $id);
    }

    /**
     * Convertit un montant saisi en euros (« 12,50 », « 12.5 ») en centimes.
     * Passer par des flottants n'est acceptable QUE pour cette conversion de
     * saisie ; tout le reste du projet raisonne en entiers.
     */
    private function cents(string $input): int
    {
        $normalised = str_replace([' ', ','], ['', '.'], trim($input));

        return (int) round(((float) $normalised) * 100);
    }

    /**
     * Raison pour laquelle la commande n'est plus retouchable, ou null.
     * Affiché en clair plutôt que de masquer les champs sans explication.
     */
    private function editBlockReason(int $bookingId): ?string
    {
        try {
            $this->edits->assertEditable($bookingId);

            return null;
        } catch (HttpException | NotFoundException $e) {
            return $e->getMessage();
        }
    }

    private function bookingIdOf(int $jobId): int
    {
        $bookingId = $this->db->scalar('SELECT booking_id FROM jobs WHERE id = :id', ['id' => $jobId]);
        if ($bookingId === null) {
            throw new NotFoundException('Job introuvable.');
        }

        return (int) $bookingId;
    }

    private function overlapWarning(int $jobId): string
    {
        return $this->edits->overlapsAnotherJob($jobId)
            ? ' Attention : la durée a changé et le rendez-vous chevauche désormais une autre intervention du même technicien — replanifiez.'
            : '';
    }

    /**
     * POST /admin/job/{id}/demande-avis — envoie la demande d'avis au client.
     *
     * Manuelle et non automatique : c'est un geste commercial qu'on ne veut
     * poser qu'après une intervention réellement satisfaisante, jamais en
     * masse. Réservée aux rendez-vous terminés, et une seule fois par commande.
     */
    public function requestReview(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $job = $this->db->selectOne('SELECT status, booking_id FROM jobs WHERE id = :id', ['id' => $id]);
        if ($job === null) {
            throw new NotFoundException('Job introuvable.');
        }

        if ($job['status'] !== 'completed') {
            $this->session->flash('job_ok', 'La demande d\'avis n\'est possible qu\'une fois la prestation terminée.');

            return $this->finish($request, $id);
        }

        if ($this->reviewRequestSentAt((int) $job['booking_id']) !== null) {
            $this->session->flash('job_ok', 'Une demande d\'avis a déjà été envoyée pour cette commande.');

            return $this->finish($request, $id);
        }

        $this->notifications->trigger('review_request', (int) $job['booking_id']);

        // trigger() reste silencieux si l'événement est désactivé ou dépourvu
        // de modèle : on le dit plutôt que d'annoncer un envoi imaginaire.
        $this->session->flash(
            'job_ok',
            $this->reviewRequestSentAt((int) $job['booking_id']) !== null
                ? 'Demande d\'avis envoyée au client.'
                : 'Aucune demande envoyée : vérifiez que l\'événement « Demande d\'avis » est actif et que le client a un e-mail (/admin/notifications).',
        );

        return $this->finish($request, $id);
    }

    /**
     * Date d'envoi de la demande d'avis pour cette commande, ou null.
     */
    private function reviewRequestSentAt(int $bookingId): ?string
    {
        $value = $this->db->scalar(
            "SELECT COALESCE(sent_at, scheduled_at) FROM notifications_log
              WHERE booking_id = :b AND event_key = 'review_request' AND status <> 'failed'
              ORDER BY id DESC LIMIT 1",
            ['b' => $bookingId],
        );

        return $value !== null ? (string) $value : null;
    }

    /**
     * POST /admin/job/{id}/annuler-commande — annule toute la commande de ce
     * job (cascade sur toutes ses prestations non terminées), même logique
     * que l'annulation client (BookingService::cancelById, partagée).
     */
    public function cancelBooking(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $job = $this->db->selectOne('SELECT booking_id FROM jobs WHERE id = :id', ['id' => $id]);
        if ($job === null) {
            throw new NotFoundException('Job introuvable.');
        }

        try {
            $this->bookings->cancelById((int) $job['booking_id'], $this->session->userId());
            $this->session->flash('job_ok', 'Commande annulée (toutes ses prestations non terminées ont été annulées).');
        } catch (HttpException $e) {
            $this->session->flash('job_ok', $e->getMessage());
        }

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
