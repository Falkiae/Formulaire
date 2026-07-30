<?php

declare(strict_types=1);

namespace Keepnew\Http\Api;

use Keepnew\Booking\BookingService;
use Keepnew\Booking\CartService;
use Keepnew\Core\Exception\HttpException;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Form\FormRepository;
use Keepnew\Form\FormValidator;
use Keepnew\Notification\NotificationService;
use Keepnew\Tracking\TrackingService;

/**
 * API réservation — confirmation de commande (sans paiement) et gestion via
 * token signé (consultation, annulation, choix de créneau différé).
 */
final class BookingApiController
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly CartService $cart,
        private readonly FormRepository $forms,
        private readonly FormValidator $validator,
        private readonly NotificationService $notifications,
        private readonly TrackingService $tracking,
    ) {
    }

    /**
     * POST /api/bookings — crée une réservation depuis un panier.
     *
     * Corps : { token, customer:{...}, address:{...}, slots:{onsite?, workshop?},
     *           answers?:{}, consent_terms:true, hp?:"" }
     */
    public function create(Request $request): Response
    {
        // Honeypot anti-spam : un champ caché « hp » rempli => robot.
        if ($request->string('hp') !== '') {
            return Response::json(['error' => 'Requête rejetée.'], 400);
        }
        if (!$request->bool('consent_terms')) {
            throw new HttpException(422, 'Les conditions générales doivent être acceptées.');
        }

        // Revalidation serveur des réponses au formulaire (jamais de confiance au front),
        // filtrée aux prestations du panier : elle doit exiger exactement ce que le
        // client a vu, ni plus (champ non pertinent) ni moins.
        $answers = $request->array('answers');
        $cartToken = $request->string('token');
        $serviceIds = array_values(array_unique(array_map(
            static fn (array $line): int => (int) $line['service_id'],
            $this->cart->lines($cartToken),
        )));
        $form = $serviceIds === [] ? $this->forms->publishedForm() : $this->forms->publishedFormForServices($serviceIds);
        if ($form !== null) {
            $errors = $this->validator->validate($answers, $form['fields'], $form['conditions']);
            if ($errors !== []) {
                return Response::json(['error' => 'Formulaire incomplet.', 'fields' => $errors], 422);
            }
        }

        $result = $this->bookings->createFromCart(
            $request->string('token'),
            $request->array('customer'),
            $request->array('address'),
            $this->normaliseSlots($request->array('slots')),
            $request->array('answers'),
        );

        // Confirmation immédiate + programmation des rappels (48 h / 2 h).
        foreach (['booking_confirmed', 'reminder_48h', 'reminder_2h'] as $event) {
            $this->notifications->trigger($event, $result['booking_id']);
        }

        // Conversion serveur (Meta CAPI), dédupliquée via l'event_id du pixel.
        $eventId = $request->string('event_id') ?: ('kn-' . $result['booking_id']);
        $this->tracking->purchaseFromBooking(
            $result['booking_id'],
            $eventId,
            $request->ip(),
            $request->header('user-agent'),
        );

        return Response::json($result + ['event_id' => $eventId], 201);
    }

    /**
     * GET /api/bookings/{token} — consultation via token de gestion.
     */
    public function show(Request $request): Response
    {
        return Response::json($this->bookings->view((string) $request->attribute('token')));
    }

    /**
     * POST /api/bookings/{token}/schedule — choix de créneau (prestation sans date).
     */
    public function schedule(Request $request): Response
    {
        $this->bookings->schedule((string) $request->attribute('token'), $this->normaliseSlots($request->array('slots')));

        return Response::json($this->bookings->view((string) $request->attribute('token')));
    }

    /**
     * POST /api/bookings/{token}/cancel — annulation.
     */
    public function cancel(Request $request): Response
    {
        $token = (string) $request->attribute('token');
        // La notification est déclenchée par BookingService lui-même, pour
        // couvrir aussi l'annulation depuis le back-office et depuis la page
        // client /rdv/{token}.
        $this->bookings->cancel($token);

        return Response::json($this->bookings->view($token));
    }

    /**
     * Normalise la structure des créneaux reçue en JSON.
     *
     * @param array<string, mixed> $slots
     * @return array<string, array<string, mixed>>
     */
    private function normaliseSlots(array $slots): array
    {
        $out = [];
        foreach (['onsite', 'workshop'] as $mode) {
            if (!isset($slots[$mode]) || !is_array($slots[$mode])) {
                continue;
            }
            $slot = $slots[$mode];
            if (empty($slot['start_utc']) || empty($slot['technician_id'])) {
                continue;
            }
            // Normalise l'horodatage (ISO8601 accepté) en DATETIME MySQL UTC.
            try {
                $start = (new \DateTimeImmutable((string) $slot['start_utc']))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s');
            } catch (\Exception) {
                continue;
            }
            $out[$mode] = [
                'start_utc' => $start,
                'technician_id' => (int) $slot['technician_id'],
                'bay_id' => isset($slot['bay_id']) ? (int) $slot['bay_id'] : null,
            ];
        }

        return $out;
    }
}
