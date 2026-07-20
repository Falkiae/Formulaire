<?php

declare(strict_types=1);

namespace Keepnew\Http\Middleware;

use Keepnew\Core\Csrf;
use Keepnew\Core\Exception\HttpException;
use Keepnew\Core\Middleware;
use Keepnew\Core\Request;
use Keepnew\Core\Response;

/**
 * Vérifie le jeton CSRF sur toute requête mutante (POST/PUT/PATCH/DELETE).
 *
 * Le jeton est accepté soit dans le champ `_csrf` du corps, soit dans l'en-tête
 * `X-CSRF-Token`. Les méthodes de lecture (GET/HEAD) passent sans contrôle.
 */
final class CsrfMiddleware implements Middleware
{
    private const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->method(), self::MUTATING, true)) {
            $token = $request->string('_csrf') ?: $request->header('x-csrf-token');
            if (!$this->csrf->verify($token)) {
                throw new HttpException(419, 'Session expirée, veuillez recharger la page.');
            }
        }

        return $next($request);
    }
}
