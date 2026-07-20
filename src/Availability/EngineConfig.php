<?php

declare(strict_types=1);

namespace Keepnew\Availability;

/**
 * Réglages du moteur de disponibilité, chargés depuis `settings`.
 */
final readonly class EngineConfig
{
    public function __construct(
        public int $slotStepMin = 30,
        public int $minLeadHours = 24,
        public int $horizonDays = 90,
        public int $setupBufferMin = 15,
        public int $maxJobDurationMin = 360,
        public int $arrivalWindowMin = 120,  // fenêtre d'arrivée domicile (ex. 9h–11h)
        public int $maxSlotsPerJob = 60,      // limite anti-énumération
    ) {
    }
}
