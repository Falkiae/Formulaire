<?php

declare(strict_types=1);

namespace Keepnew\Http\Api;

use Keepnew\Booking\BookingService;
use Keepnew\Core\Exception\HttpException;
use Keepnew\Core\Request;
use Keepnew\Core\Response;

/**
 * API réservation — confirmation de commande (sans paiement) et gestion via
 * token signé (consultation, annulation, choix de créneau différé).
 */
final class BookingApiController
{
    public function __construct(private readonly BookingService $bookings)
    {
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

        $result = $this->bookings->createFromCart(
            $request->string('token'),
            $request->array('customer'),
            $request->array('address'),
            $this->normaliseSlots($request->array('slots')),
            $request->array('answers'),
        );

        return Response::json($result, 201);
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
        $this->bookings->cancel((string) $request->attribute('token'));

        return Response::json($this->bookings->view((string) $request->attribute('token')));
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
