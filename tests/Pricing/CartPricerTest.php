<?php

declare(strict_types=1);

namespace Keepnew\Tests\Pricing;

use Keepnew\Pricing\CartPricer;
use Keepnew\Pricing\PriceCalculator;
use Keepnew\Pricing\PricingRules;
use PHPUnit\Framework\TestCase;

/**
 * Tests du moteur de tarification du panier : remise cumul multi-prestations,
 * décote de durée, coupons (pourcentage/fixe/plafond/seuil), supplément
 * déplacement, groupes d'adresses, et calcul de TVA.
 */
final class CartPricerTest extends TestCase
{
    private CartPricer $pricer;
    private PricingRules $rules;

    protected function setUp(): void
    {
        $this->pricer = new CartPricer(new PriceCalculator());
        $this->rules = new PricingRules(
            multiItemPercentBp: 1000,      // −10 %
            multiItemMinItems: 2,
            multiItemAppliesFromItem: 2,   // dès la 2ᵉ
            durationCumulPercentBp: 1000,  // −10 % durée
            vatRateBp: 2100,
        );
    }

    private function line(int $price, int $duration, string $address, int $qty = 1): array
    {
        return [
            'input' => ['base_price_cents' => $price, 'base_duration_min' => $duration, 'quantity' => $qty],
            'address_key' => $address,
        ];
    }

    public function testTwoItemsSameAddressGetCumulDiscount(): void
    {
        $quote = $this->pricer->price([
            $this->line(7900, 75, 'onsite:home'),
            $this->line(6900, 65, 'onsite:home'),
        ], $this->rules);

        self::assertSame(14800, $quote->linesSubtotalCents);
        self::assertSame(690, $quote->cumulDiscountCents);      // 10 % de la 2ᵉ (6900)
        self::assertSame(14110, $quote->netHtvaCents);
        self::assertSame(2963, $quote->vatCents);
        self::assertSame(17073, $quote->totalTvacCents);
        self::assertSame(133, $quote->totalActiveDurationMin);  // 140 − 10 % de 65 (=7)
    }

    public function testDifferentAddressesGetNoCumulDiscount(): void
    {
        $quote = $this->pricer->price([
            $this->line(7900, 75, 'onsite:home'),
            $this->line(6900, 65, 'workshop:atelier'),
        ], $this->rules);

        self::assertSame(0, $quote->cumulDiscountCents); // chaque groupe n'a qu'une prestation
        self::assertSame(14800, $quote->netHtvaCents);
    }

    public function testQuantityCountsAsMultiplePrestations(): void
    {
        $quote = $this->pricer->price([
            $this->line(5900, 45, 'onsite:home', 2),
        ], $this->rules);

        self::assertSame(11800, $quote->linesSubtotalCents);
        self::assertSame(590, $quote->cumulDiscountCents); // 2ᵉ unité remisée
    }

    public function testCouponPercent(): void
    {
        $quote = $this->pricer->price(
            [$this->line(10000, 60, 'onsite:home')],
            $this->rules,
            ['type' => 'percent', 'value' => 1000, 'min_order_cents' => 5000],
        );

        self::assertSame(1000, $quote->couponDiscountCents);
        self::assertSame(9000, $quote->netHtvaCents);
        self::assertSame(1890, $quote->vatCents);
        self::assertSame(10890, $quote->totalTvacCents);
    }

    public function testCouponBelowMinOrderIsIgnored(): void
    {
        $quote = $this->pricer->price(
            [$this->line(4000, 30, 'onsite:home')],
            $this->rules,
            ['type' => 'fixed', 'value' => 1500, 'min_order_cents' => 8000],
        );

        self::assertSame(0, $quote->couponDiscountCents);
    }

    public function testCouponCappedAtBase(): void
    {
        $quote = $this->pricer->price(
            [$this->line(1000, 30, 'onsite:home')],
            $this->rules,
            ['type' => 'fixed', 'value' => 5000, 'min_order_cents' => 0],
        );

        self::assertSame(1000, $quote->couponDiscountCents);
        self::assertSame(0, $quote->netHtvaCents);
        self::assertSame(0, $quote->vatCents);
    }

    public function testTravelSurchargeIsTaxed(): void
    {
        $quote = $this->pricer->price(
            [$this->line(10000, 60, 'onsite:home')],
            $this->rules,
            null,
            1500,
        );

        self::assertSame(1500, $quote->travelSurchargeCents);
        self::assertSame(11500, $quote->netHtvaCents);
        self::assertSame(2415, $quote->vatCents);
        self::assertSame(13915, $quote->totalTvacCents);
    }

    public function testAppliesFromThirdItem(): void
    {
        $rules = new PricingRules(1000, 2, 3, 1000, 2100); // remise à partir de la 3ᵉ
        $quote = $this->pricer->price([
            $this->line(5000, 30, 'onsite:home'),
            $this->line(5000, 30, 'onsite:home'),
            $this->line(5000, 30, 'onsite:home'),
        ], $rules);

        self::assertSame(500, $quote->cumulDiscountCents); // seule la 3ᵉ est remisée
    }

    public function testFullCombinationCumulPlusCoupon(): void
    {
        // 2 prestations même adresse (cumul) + coupon 10 %.
        $quote = $this->pricer->price(
            [
                $this->line(10000, 60, 'onsite:home'),
                $this->line(10000, 60, 'onsite:home'),
            ],
            $this->rules,
            ['type' => 'percent', 'value' => 1000, 'min_order_cents' => 0],
        );

        // sous-total 20000 ; cumul −1000 (10 % de la 2ᵉ) → 19000 ; coupon −1900 → 17100.
        self::assertSame(1000, $quote->cumulDiscountCents);
        self::assertSame(1900, $quote->couponDiscountCents);
        self::assertSame(17100, $quote->netHtvaCents);
    }
}
