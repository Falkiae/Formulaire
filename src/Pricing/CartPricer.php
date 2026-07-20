<?php

declare(strict_types=1);

namespace Keepnew\Pricing;

use Keepnew\Support\Money;

/**
 * Moteur de tarification du PANIER (multi-lignes).
 *
 * Pur (aucun accès base). Il agrège les lignes calculées par PriceCalculator et
 * applique, dans un ordre explicite :
 *   1. Calcul de chaque ligne (base + variante + extras + modificateurs).
 *   2. Remise cumul : −X % à partir de la Nᵉ prestation À LA MÊME ADRESSE.
 *      Les prestations sont comptées à l'unité (quantité incluse) et regroupées
 *      par `address_key` (ex. « onsite:<adresse> » vs « workshop:<atelier> »).
 *   3. Décote de cumul sur la DURÉE (deux prestations au même endroit prennent
 *      moins de temps total : le setup n'est fait qu'une fois).
 *   4. Coupon (fixe ou pourcentage), sur le sous-total après remise cumul.
 *   5. Supplément déplacement (hors zone), taxable.
 *   6. TVA sur la base nette, total TVAC.
 *
 * Toutes les remises réduisent la base taxable AVANT TVA.
 */
final class CartPricer
{
    public function __construct(private readonly PriceCalculator $calculator)
    {
    }

    /**
     * @param list<array{input: array<string, mixed>, address_key?: string}> $lines
     * @param array{type:string, value:int, min_order_cents?:int}|null $coupon
     */
    public function price(
        array $lines,
        PricingRules $rules,
        ?array $coupon = null,
        int $travelSurchargeCents = 0,
    ): CartQuote {
        // 1. Calcul de chaque ligne + collecte des prestations unitaires.
        $lineQuotes = [];
        $units = []; // ['address_key' => ..., 'price' => unit, 'duration' => unit]
        $linesSubtotal = 0;
        $rawDuration = 0;

        foreach ($lines as $line) {
            $addressKey = $line['address_key'] ?? 'default';
            $quote = $this->calculator->calculateLine($line['input']);
            $lineQuotes[] = ['line' => $quote, 'address_key' => $addressKey];
            $linesSubtotal += $quote->linePriceCents;
            $rawDuration += $quote->lineDurationMin;

            for ($i = 0; $i < $quote->quantity; $i++) {
                $units[] = [
                    'address_key' => $addressKey,
                    'price' => $quote->unitPriceCents,
                    'duration' => $quote->unitDurationMin,
                ];
            }
        }

        // 2 & 3. Remise cumul (prix) et décote de cumul (durée), par groupe d'adresse.
        [$cumulDiscount, $durationDiscount] = $this->computeCumul($units, $rules);

        // 4. Coupon, sur le sous-total après remise cumul.
        $afterCumul = $linesSubtotal - $cumulDiscount;
        $couponDiscount = $this->computeCoupon($coupon, $afterCumul);

        // 5 & 6. Base taxable nette + TVA.
        $netHtva = max(0, $linesSubtotal - $cumulDiscount - $couponDiscount + max(0, $travelSurchargeCents));
        $vat = Money::vat($netHtva, $rules->vatRateBp);

        $totalDuration = max(0, $rawDuration - $durationDiscount);

        return new CartQuote(
            lines: $lineQuotes,
            linesSubtotalCents: $linesSubtotal,
            cumulDiscountCents: $cumulDiscount,
            couponDiscountCents: $couponDiscount,
            travelSurchargeCents: max(0, $travelSurchargeCents),
            netHtvaCents: $netHtva,
            vatRateBp: $rules->vatRateBp,
            vatCents: $vat,
            totalTvacCents: $netHtva + $vat,
            totalActiveDurationMin: $totalDuration,
        );
    }

    /**
     * Calcule la remise cumul (prix) et la décote de cumul (durée).
     *
     * Les prestations sont regroupées par adresse. Dans un groupe d'au moins
     * `multiItemMinItems` prestations, chaque prestation à partir de la
     * `multiItemAppliesFromItem`ᵉ est remisée.
     *
     * @param list<array{address_key:string, price:int, duration:int}> $units
     * @return array{0:int, 1:int} [remise prix, décote durée]
     */
    private function computeCumul(array $units, PricingRules $rules): array
    {
        // Regroupe en préservant l'ordre d'apparition.
        $groups = [];
        foreach ($units as $unit) {
            $groups[$unit['address_key']][] = $unit;
        }

        $priceDiscount = 0;
        $durationDiscount = 0;

        foreach ($groups as $groupUnits) {
            if (count($groupUnits) < $rules->multiItemMinItems) {
                continue;
            }

            foreach ($groupUnits as $index => $unit) {
                // La 1ʳᵉ prestation (index 0) reste plein tarif ; on remise à
                // partir de la position configurée (appliesFromItem, base 1).
                if ($index + 1 < $rules->multiItemAppliesFromItem) {
                    continue;
                }
                $priceDiscount += Money::percentOf($unit['price'], $rules->multiItemPercentBp);
                $durationDiscount += Money::percentOf($unit['duration'], $rules->durationCumulPercentBp);
            }
        }

        return [$priceDiscount, $durationDiscount];
    }

    /**
     * @param array{type:string, value:int, min_order_cents?:int}|null $coupon
     */
    private function computeCoupon(?array $coupon, int $base): int
    {
        if ($coupon === null || $base <= 0) {
            return 0;
        }
        if ($base < ($coupon['min_order_cents'] ?? 0)) {
            return 0;
        }

        $discount = match ($coupon['type']) {
            'percent' => Money::percentOf($base, $coupon['value']),
            'fixed' => $coupon['value'],
            default => 0,
        };

        // Le coupon ne peut pas dépasser la base.
        return max(0, min($discount, $base));
    }
}
