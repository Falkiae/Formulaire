<?php

declare(strict_types=1);

namespace Keepnew\Http\Middleware;

use Keepnew\Core\Middleware;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Session;

/**
 * Contrôle d'accès par rôle (RBAC).
 *
 * S'ajoute APRÈS AuthMiddleware (qui garantit l'authentification). Vérifie que
 * le rôle du compte connecté figure dans la liste autorisée ; sinon, redirige
 * vers la page d'accueil du rôle (HTML) ou renvoie 403 (JSON/API).
 *
 * Les rôles sont ceux de l'ENUM users.role : admin, dispatcher, technician,
 * accountant.
 */
final class RoleMiddleware implements Middleware
{
    /**
     * @param list<string> $allowedRoles
     */
    public function __construct(
        private readonly Session $session,
        private readonly array $allowedRoles,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $role = (string) $this->session->get('user_role', '');

        if (!in_array($role, $this->allowedRoles, true)) {
            if ($request->isJson()) {
                return Response::json(['error' => 'Accès non autorisé pour votre rôle.'], 403);
            }

            $this->session->flash('access_denied', "Vous n'avez pas accès à cette section.");

            return Response::redirect(self::landingFor($role));
        }

        return $next($request);
    }

    /**
     * Page d'accueil naturelle d'un rôle (pour rediriger après un refus).
     */
    public static function landingFor(string $role): string
    {
        return match ($role) {
            'technician' => '/tech',
            'accountant' => '/admin/factures',
            'dispatcher' => '/admin/dispatch',
            'admin' => '/admin/dispatch',
            default => '/admin/connexion',
        };
    }
}
