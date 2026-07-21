<?php

declare(strict_types=1);

namespace Keepnew\Http\Middleware;

use Keepnew\Core\Middleware;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Session;

/**
 * Exige une session authentifiée pour accéder aux routes back-office.
 *
 * Non authentifié : redirige vers /admin/connexion (HTML) ou renvoie 401 (JSON).
 */
final class AuthMiddleware implements Middleware
{
    public function __construct(private readonly Session $session)
    {
    }

    private const INACTIVITY_LIMIT = 7200; // 2 heures en secondes

    public function handle(Request $request, callable $next): Response
    {
        if (!$this->session->isAuthenticated()) {
            return $request->isJson()
                ? Response::json(['error' => 'Authentification requise.'], 401)
                : Response::redirect('/admin/connexion');
        }

        if ($this->session->isInactive(self::INACTIVITY_LIMIT)) {
            $this->session->logout();

            return $request->isJson()
                ? Response::json(['error' => 'Session expirée, veuillez vous reconnecter.'], 401)
                : Response::redirect('/admin/connexion?expired=1');
        }

        $this->session->touchActivity();

        return $next($request);
    }
}
