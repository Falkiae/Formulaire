<?php

declare(strict_types=1);

namespace Keepnew\Pricing;

/**
 * Résultat du calcul d'UNE ligne de prestation (service + variante + mode +
 * extras + modificateurs), pour une quantité donnée.
 *
 * Montants en centimes, durées en minutes. `occupancyDurationMin` n'a de sens
 * qu'en mode atelier (immobilisation du poste après le travail actif).
 */
final readonly class LineQuote
{
    /**
     * @param list<PriceComponent> $components Détail « ligne par ligne ».
     */
    public function __construct(
        public int $unitPriceCents,
        public int $unitDurationMin,
        public int $quantity,
        public int $linePriceCents,
        public int $lineDurationMin,
        public int $occupancyDurationMin,
        public array $components,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'unit_price_cents' => $this->unitPriceCents,
            'unit_duration_min' => $this->unitDurationMin,
            'quantity' => $this->quantity,
            'line_price_cents' => $this->linePriceCents,
            'line_duration_min' => $this->lineDurationMin,
            'occupancy_duration_min' => $this->occupancyDurationMin,
            'components' => array_map(static fn (PriceComponent $c): array => $c->toArray(), $this->components),
        ];
    }
}
