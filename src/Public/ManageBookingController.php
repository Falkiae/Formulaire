<?php

declare(strict_types=1);

namespace Keepnew\Public;

use Keepnew\Admin\RescheduleAvailabilityService;
use Keepnew\Booking\BookingService;
use Keepnew\Core\Csrf;
use Keepnew\Core\Database;
use Keepnew\Core\Exception\HttpException;
use Keepnew\Core\Exception\NotFoundException;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\View;
use Keepnew\Support\Clock;

/**
 * Page de gestion client (« mes-rdv »), accessible sans compte via le
 * manage_token unique de chaque réservation (déjà généré à la création,
 * jusqu'ici jamais consommé). Portée volontairement restreinte : consulter,
 * changer la date/heure d'un rendez-vous déjà planifié (réutilise le même
 * moteur que la replanification admin, RescheduleAvailabilityService), et
 * annuler toute la réservation (BookingService::cancel(), déjà existant) —
 * uniquement avant le délai configuré (`booking.self_service_deadline_hours`,
 * réglages admin). Le technicien est choisi automatiquement, comme dans le
 * tunnel public — jamais choisi par le client.
 */
final class ManageBookingController
{
    public function __construct(
        private readonly View $view,
        private readonly Csrf $csrf,
        private readonly Database $db,
        private readonly BookingService $bookings,
        private readonly RescheduleAvailabilityService $availability,
    ) {
    }

    /**
     * GET /rdv/{token}
     */
    public function show(Request $request): Response
    {
        $token = $this->token($request);
        $booking = $this->safeView($token);
        if ($booking === null) {
            return $this->view->render('public/manage-booking', ['booking' => null] + $this->contact(), 404);
        }

        return $this->view->render('public/manage-booking', [
            'token' => $token,
            'csrf' => $this->csrf->field(),
            'booking' => $booking,
            'within_window' => $this->bookings->withinSelfServiceWindow($token),
            'msg' => $request->string('msg'),
            'msg_type' => $request->string('msgtype') === 'ok' ? 'ok' : 'error',
        ] + $this->contact());
    }

    /**
     * GET /rdv/{token}/creneaux/mois?job_id=X&month=YYYY-MM
     */
    public function slotDates(Request $request): Response
    {
        $token = $this->token($request);
        $jobId = $this->ownedJobId($request, $token);
        if ($jobId === null || !$this->bookings->withinSelfServiceWindow($token)) {
            return Response::json(['dates' => []])->withHeader('Cache-Control', 'no-store');
        }

        $month = $request->string('month');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = Clock::format(Clock::nowUtc(), 'Y-m');
        }

        return Response::json(['dates' => $this->availability->datesWithSlots($jobId, $month)])
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * GET /rdv/{token}/creneaux?job_id=X&date=YYYY-MM-DD
     */
    public function slotsForDate(Request $request): Response
    {
        $token = $this->token($request);
        $jobId = $this->ownedJobId($request, $token);
        $date = $request->string('date');
        if ($jobId === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !$this->bookings->withinSelfServiceWindow($token)) {
            return Response::json(['slots' => []])->withHeader('Cache-Control', 'no-store');
        }

        return Response::json(['slots' => $this->availability->slotsForDate($jobId, $date)])
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * POST /rdv/{token}/replanifier — le client choisit une date/heure ;
     * le technicien est résolu et vérifié côté serveur au moment de la
     * soumission (jamais transmis par le client), même logique de choix
     * automatique que le sélecteur admin (technicien déjà assigné s'il est
     * encore libre à ce créneau, sinon le premier disponible).
     */
    public function reschedule(Request $request): Response
    {
        $token = $this->token($request);
        $jobId = $this->ownedJobId($request, $token);
        $date = $request->string('date');
        $time = $request->string('time');
        if ($jobId === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            return $this->redirectWithFlash($token, 'Créneau invalide.');
        }

        $job = $this->db->selectOne('SELECT mode, technician_id FROM jobs WHERE id = :id', ['id' => $jobId]);
        if ($job === null) {
            return $this->redirectWithFlash($token, 'Prestation introuvable.');
        }

        $slots = $this->availability->slotsForDate($jobId, $date);
        $techs = $slots[$time] ?? [];
        if ($techs === []) {
            return $this->redirectWithFlash($token, 'Ce créneau n\'est plus disponible, merci d\'en choisir un autre.');
        }
        $currentTechId = (int) ($job['technician_id'] ?? 0);
        $chosen = null;
        foreach ($techs as $t) {
            if ((int) $t['id'] === $currentTechId) {
                $chosen = $t;
                break;
            }
        }
        $chosen ??= $techs[0];

        $startUtc = Clock::fromDisplay($date . ' ' . $time)->format('c');

        try {
            $this->bookings->schedule($token, [
                (string) $job['mode'] => [
                    'technician_id' => (int) $chosen['id'],
                    'bay_id' => $chosen['bay_id'] ?? null,
                    'start_utc' => $startUtc,
                ],
            ]);
        } catch (HttpException $e) {
            return $this->redirectWithFlash($token, $e->getMessage());
        }

        return $this->redirectWithFlash($token, 'Rendez-vous mis à jour.', 'ok');
    }

    /**
     * POST /rdv/{token}/annuler
     */
    public function cancel(Request $request): Response
    {
        $token = $this->token($request);
        try {
            $this->bookings->cancel($token);
        } catch (HttpException $e) {
            return $this->redirectWithFlash($token, $e->getMessage());
        }

        return $this->redirectWithFlash($token, 'Réservation annulée.', 'ok');
    }

    // --- Helpers -----------------------------------------------------------

    private function token(Request $request): string
    {
        return (string) $request->attribute('token');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeView(string $token): ?array
    {
        try {
            return $this->bookings->view($token);
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * Vérifie que le job appartient bien à la commande de ce token (jamais
     * faire confiance à un job_id transmis par le client sans ce contrôle).
     */
    private function ownedJobId(Request $request, string $token): ?int
    {
        $jobId = $request->int('job_id');
        if ($jobId <= 0) {
            return null;
        }
        try {
            $bookingId = (int) $this->bookings->findByManageToken($token)['id'];
        } catch (NotFoundException) {
            return null;
        }
        $owned = $this->db->scalar('SELECT id FROM jobs WHERE id = :j AND booking_id = :b', ['j' => $jobId, 'b' => $bookingId]);

        return $owned !== null ? $jobId : null;
    }

    /**
     * @return array{contact_phone:?string, contact_email:?string, terms_url:?string}
     */
    private function contact(): array
    {
        $rows = $this->db->select(
            "SELECT `key`, `value` FROM settings WHERE `key` IN ('company.phone', 'company.email', 'company.terms_url')",
        );
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$r['key']] = $r['value'];
        }

        return [
            'contact_phone' => $byKey['company.phone'] ?? null,
            'contact_email' => $byKey['company.email'] ?? null,
            'terms_url' => $byKey['company.terms_url'] ?? null,
        ];
    }

    private function redirectWithFlash(string $token, string $message, string $type = 'error'): Response
    {
        // Pas de session admin ici (visiteur public) — message transmis en
        // query string, affiché puis ignoré au rendu (repli simple, pas de
        // flash-session nécessaire pour une seule page sans navigation complexe).
        return Response::redirect('/rdv/' . $token . '?msg=' . rawurlencode($message) . '&msgtype=' . $type);
    }
}
