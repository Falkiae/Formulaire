<?php

declare(strict_types=1);

namespace Keepnew\Pricing;

/**
 * Paramètres de tarification du panier, chargés depuis la configuration
 * (`settings`, `duration_rules`) — jamais en dur dans le code.
 *
 * Pourcentages en POINTS DE BASE (1000 = 10,00 %).
 */
final readonly class PricingRules
{
    public function __construct(
        // Remise multi-prestations à la même adresse.
        public int $multiItemPercentBp = 1000,
        public int $multiItemMinItems = 2,
        public int $multiItemAppliesFromItem = 2,
        // Décote de cumul sur la durée (même endroit).
        public int $durationCumulPercentBp = 1000,
        // TVA.
        public int $vatRateBp = 2100,
    ) {
    }
}
