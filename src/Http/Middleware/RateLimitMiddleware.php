<?php

declare(strict_types=1);

namespace Keepnew\Http\Middleware;

use Keepnew\Core\Database;
use Keepnew\Core\Exception\HttpException;
use Keepnew\Core\Middleware;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Support\Clock;

/**
 * Limitation de débit par IP, adossée à la table `rate_limits`.
 *
 * Protège le login, le formulaire public et l'endpoint de disponibilité
 * (anti-énumération de créneaux). Fenêtre glissante par minute : au-delà de
 * $maxHits requêtes dans la fenêtre, on renvoie HTTP 429.
 *
 * Instanciée avec un identifiant de « seau » distinct par usage, ex. :
 *   new RateLimitMiddleware($db, 'availability', 30)
 */
final class RateLimitMiddleware implements Middleware
{
    public function __construct(
        private readonly Database $db,
        private readonly string $bucket,
        private readonly int $maxHits = 60,
        private readonly int $windowSeconds = 60,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $windowStart = Clock::nowUtc()
            ->setTime(
                (int) Clock::nowUtc()->format('H'),
                (int) Clock::nowUtc()->format('i'),
            )
            ->format('Y-m-d H:i:00');

        $bucketKey = $this->bucket . ':' . $request->ip();

        // Incrémente atomiquement le compteur de la fenêtre courante.
        $this->db->run(
            'INSERT INTO rate_limits (bucket, hits, window_start)
             VALUES (:bucket, 1, :window_start)
             ON DUPLICATE KEY UPDATE hits = hits + 1',
            ['bucket' => $bucketKey, 'window_start' => $windowStart],
        );

        $hits = (int) $this->db->scalar(
            'SELECT hits FROM rate_limits WHERE bucket = :bucket AND window_start = :window_start',
            ['bucket' => $bucketKey, 'window_start' => $windowStart],
        );

        if ($hits > $this->maxHits) {
            throw new HttpException(429, 'Trop de requêtes. Merci de patienter un instant.');
        }

        return $next($request);
    }
}
