<?php

declare(strict_types=1);

namespace Keepnew\Pricing;

/**
 * Devis complet d'un panier : lignes détaillées + totaux agrégés.
 *
 * Montants en centimes. La base taxable nette (net_htva) = sous-total des lignes
 * − remise cumul − coupon + supplément déplacement. La TVA s'applique dessus.
 */
final readonly class CartQuote
{
    /**
     * @param list<array{line:LineQuote, address_key:string}> $lines
     */
    public function __construct(
        public array $lines,
        public int $linesSubtotalCents,
        public int $cumulDiscountCents,
        public int $couponDiscountCents,
        public int $travelSurchargeCents,
        public int $netHtvaCents,
        public int $vatRateBp,
        public int $vatCents,
        public int $totalTvacCents,
        public int $totalActiveDurationMin,
    ) {
    }

    public function totalDiscountCents(): int
    {
        return $this->cumulDiscountCents + $this->couponDiscountCents;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lines' => array_map(
                static fn (array $l): array => ['address_key' => $l['address_key']] + $l['line']->toArray(),
                $this->lines,
            ),
            'lines_subtotal_cents' => $this->linesSubtotalCents,
            'cumul_discount_cents' => $this->cumulDiscountCents,
            'coupon_discount_cents' => $this->couponDiscountCents,
            'total_discount_cents' => $this->totalDiscountCents(),
            'travel_surcharge_cents' => $this->travelSurchargeCents,
            'net_htva_cents' => $this->netHtvaCents,
            'vat_rate_bp' => $this->vatRateBp,
            'vat_cents' => $this->vatCents,
            'total_tvac_cents' => $this->totalTvacCents,
            'total_active_duration_min' => $this->totalActiveDurationMin,
        ];
    }
}
