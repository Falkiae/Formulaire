<?php

declare(strict_types=1);

namespace Keepnew\Geo;

use Keepnew\Core\Database;

/**
 * Repli par matrice de trajets entre codes postaux (table postal_travel_matrix).
 *
 * Utilisé quand l'API cartographique est indisponible. Ne répond que si les deux
 * points portent un code postal et que le couple existe dans la matrice.
 */
final class PostalMatrixGeoProvider implements GeoProviderInterface
{
    public function __construct(private readonly Database $db)
    {
    }

    public function name(): string
    {
        return 'postal_matrix';
    }

    public function travelSeconds(GeoPoint $from, GeoPoint $to): ?int
    {
        if ($from->postal === null || $to->postal === null) {
            return null;
        }

        // Même code postal : trajet interne court, non tabulé.
        if ($from->postal === $to->postal) {
            return 5 * 60;
        }

        $minutes = $this->db->scalar(
            'SELECT duration_min FROM postal_travel_matrix WHERE from_postal = :f AND to_postal = :t',
            ['f' => $from->postal, 't' => $to->postal],
        );

        if ($minutes === null) {
            // Essaie le sens inverse (la matrice peut n'être renseignée qu'un sens).
            $minutes = $this->db->scalar(
                'SELECT duration_min FROM postal_travel_matrix WHERE from_postal = :t AND to_postal = :f',
                ['f' => $from->postal, 't' => $to->postal],
            );
        }

        return $minutes === null ? null : (int) $minutes * 60;
    }
}
