<?php

declare(strict_types=1);

namespace Keepnew\Http\Controller;

use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Support\Clock;

/**
 * Contrôleur de santé — sert à vérifier que le noyau répond correctement.
 *
 * Sert d'exemple canonique : une route publique qui renvoie du JSON, et une
 * route paramétrée qui relit un paramètre d'URL via Request::attribute().
 */
final class HealthController
{
    /**
     * GET /health — état du service.
     */
    public function index(Request $request): Response
    {
        return Response::json([
            'service' => 'keepnew-booking',
            'status' => 'ok',
            'time_utc' => Clock::nowUtc()->format(DATE_ATOM),
            'time_brussels' => Clock::format(Clock::nowUtc(), 'd/m/Y H:i'),
        ]);
    }

    /**
     * GET /health/echo/{value} — renvoie le paramètre de route (démonstration).
     */
    public function echo(Request $request): Response
    {
        return Response::json([
            'echo' => $request->attribute('value'),
        ]);
    }
}
