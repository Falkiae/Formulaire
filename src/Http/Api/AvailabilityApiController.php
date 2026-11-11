<?php

declare(strict_types=1);

namespace Keepnew\Http\Api;

use Keepnew\Availability\AvailabilityRepository;
use Keepnew\Availability\AvailabilityService;
use Keepnew\Booking\CartService;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Geo\GeoPoint;
use Keepnew\Support\Clock;
use Keepnew\Support\MonthWindow;

/**
 * API disponibilité — créneaux domicile et/ou atelier pour un panier.
 *
 * Endpoint sensible (énumération de créneaux) : à protéger par rate limiting.
 */
final class AvailabilityApiController
{
    public function __construct(
        private readonly CartService $cart,
        private readonly AvailabilityService $availability,
        private readonly AvailabilityRepository $availabilityRepo,
    ) {
    }

    /**
     * POST /api/availability
     * Corps : { token, lat?, lng?, postal?, month? (AAAA-MM), days? }
     *
     * `month` est la fenêtre du calendrier : un mois civil belge, borné au
     * présent d'un côté et à MonthWindow::MAX_MONTHS_AHEAD de l'autre. `days`
     * reste accepté (fenêtre glissante depuis maintenant) pour tout appelant
     * qui ne navigue pas par mois.
     */
    public function search(Request $request): Response
    {
        $token = $request->string('token');
        $lines = $this->cart->lines($token);
        if ($lines === []) {
            return Response::json(['error' => 'Panier vide.'], 422);
        }

        $address = new GeoPoint(
            $request->has('lat') ? (float) $request->string('lat') : null,
            $request->has('lng') ? (float) $request->string('lng') : null,
            $request->string('postal') ?: null,
        );

        $now = Clock::nowUtc();
        $month = $request->string('month');
        $result = [];

        if ($month !== '') {
            if (!MonthWindow::isValidKey($month)) {
                return Response::json(['error' => 'Mois invalide.'], 422);
            }
            // L'horizon vient du réglage `availability.horizon_days` : le
            // calendrier s'arrête là où le moteur cesse de proposer.
            $resolved = MonthWindow::resolve($month, $now, $this->availability->horizonDays());
            $from = $resolved['from'];
            $to = $resolved['to'];
            $result['window'] = $resolved['window'];
        } else {
            $from = $now;
            $to = $now->modify('+' . max(1, min(90, $request->int('days', 14))) . ' days');
        }

        $hasOnsite = array_filter($lines, static fn (array $l): bool => $l['mode'] === 'onsite') !== [];
        if ($hasOnsite) {
            $result['onsite'] = $this->availability->onsiteAvailability($lines, $address, $from, $to);
            // Sérialise l'objet zone éventuel.
            if (isset($result['onsite']['zone'])) {
                $zone = $result['onsite']['zone'];
                $result['onsite']['zone'] = [
                    'in_zone' => $zone->inZone,
                    'surcharge_cents' => $zone->surchargeCents,
                    'refused' => $zone->refused,
                ];
            }
        }

        $hasWorkshop = array_filter($lines, static fn (array $l): bool => $l['mode'] === 'workshop') !== [];
        if ($hasWorkshop) {
            $locationId = $this->availabilityRepo->defaultWorkshopLocationId();
            $result['workshop'] = $locationId !== null
                ? $this->availability->workshopAvailability($lines, $locationId, $from, $to)
                : ['status' => 'no_workshop'];
        }

        return Response::json($result)->withHeader('Cache-Control', 'no-store');
    }
}
