<?php

declare(strict_types=1);

namespace Keepnew\Http\Api;

use Keepnew\Availability\AvailabilityRepository;
use Keepnew\Availability\ZoneResolver;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Geo\GeoPoint;

/**
 * API publique — vérification légère d'un code postal contre les zones de
 * service, sans passer par la recherche de créneau (qui exige un panier non
 * vide). Utilisée par le widget dès l'étape 1 pour bloquer immédiatement une
 * adresse non couverte, avant même le choix d'une prestation.
 */
final class ZoneCheckApiController
{
    public function __construct(
        private readonly AvailabilityRepository $availabilityRepo,
        private readonly ZoneResolver $zoneResolver,
    ) {
    }

    /**
     * GET /api/zones/check?postal=XXXX
     */
    public function check(Request $request): Response
    {
        $postal = $request->string('postal');
        if ($postal === '') {
            return Response::json(['error' => 'Code postal requis.'], 422);
        }

        $point = new GeoPoint(null, null, $postal);
        $result = $this->zoneResolver->resolve($point, $this->availabilityRepo->zones());

        return Response::json(['ok' => $result->inZone && !$result->refused]);
    }
}
