<?php

declare(strict_types=1);

namespace Keepnew\Core;

/**
 * Contrat d'un middleware (couche traversée avant le contrôleur).
 *
 * Modèle « oignon » : chaque middleware reçoit la requête et un callable
 * $next. Il peut agir avant (validation, auth), appeler $next($request) pour
 * poursuivre, puis agir après (en-têtes), ou court-circuiter en renvoyant sa
 * propre Response sans appeler $next.
 *
 * @phpstan-type Next callable(Request): Response
 */
interface Middleware
{
    /**
     * @param callable(Request): Response $next
     */
    public function handle(Request $request, callable $next): Response;
}
