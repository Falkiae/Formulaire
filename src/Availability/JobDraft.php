<?php

declare(strict_types=1);

namespace Keepnew\Availability;

use Keepnew\Geo\GeoPoint;

/**
 * Un job à planifier, issu du découpage du panier par mode d'exécution.
 *
 * - `activeDurationMin` : temps de travail du technicien (bloque l'agenda terrain).
 * - `occupancyDurationMin` : immobilisation d'un poste atelier (séchage inclus),
 *   >= activeDurationMin ; sans objet en mode domicile.
 * - `requiredSkillIds` : compétences requises par TOUTES les prestations du job.
 */
final readonly class JobDraft
{
    /**
     * @param list<int> $requiredSkillIds
     */
    public function __construct(
        public string $mode,               // onsite | workshop
        public int $activeDurationMin,
        public int $occupancyDurationMin,
        public array $requiredSkillIds,
        public ?GeoPoint $point = null,     // adresse client (domicile)
    ) {
    }
}
