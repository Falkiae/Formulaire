<?php

declare(strict_types=1);

namespace Keepnew\Pricing;

/**
 * Une ligne du détail de calcul (« ligne par ligne » du simulateur).
 *
 * Chaque composant explique une brique du prix/durée : la base, la variante,
 * un extra, un modificateur. C'est ce qui rend la grille tarifaire débogable.
 */
final readonly class PriceComponent
{
    public function __construct(
        public string $label,
        public int $priceCents,
        public int $durationMin,
        public string $kind = 'base', // base|mode|variant|extra|modifier
    ) {
    }

    /**
     * @return array{label:string, price_cents:int, duration_min:int, kind:string}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'price_cents' => $this->priceCents,
            'duration_min' => $this->durationMin,
            'kind' => $this->kind,
        ];
    }
}
