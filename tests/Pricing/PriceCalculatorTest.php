<?php

declare(strict_types=1);

namespace Keepnew\Tests\Pricing;

use Keepnew\Pricing\PriceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests du calculateur de prix/durée sur les cas structurants du catalogue
 * Keepnew : base, variante (delta et override), extras, modificateurs
 * (fixed/percent/multiplier), quantité, et plancher à zéro.
 */
final class PriceCalculatorTest extends TestCase
{
    private PriceCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new PriceCalculator();
    }

    public function testBaseOnly(): void
    {
        $q = $this->calc->calculateLine([
            'base_price_cents' => 6900,
            'base_duration_min' => 90,
        ]);

        self::assertSame(6900, $q->unitPriceCents);
        self::assertSame(90, $q->unitDurationMin);
        self::assertSame(6900, $q->linePriceCents);
    }

    public function testVariantDelta(): void
    {
        // Canapé onsite 79€/75min + variante 4 places (+15€/+15min)
        $q = $this->calc->calculateLine([
            'base_price_cents' => 7900,
            'base_duration_min' => 75,
            'variant' => ['label' => '4 places', 'price_delta_cents' => 1500, 'duration_delta_min' => 15],
        ]);

        self::assertSame(9400, $q->unitPriceCents);
        self::assertSame(90, $q->unitDurationMin);
    }

    public function testVariantOverrideReplacesBase(): void
    {
        $q = $this->calc->calculateLine([
            'base_price_cents' => 7900,
            'base_duration_min' => 75,
            'variant' => ['label' => 'Forfait', 'price_override_cents' => 5000, 'duration_override_min' => 60],
        ]);

        self::assertSame(5000, $q->unitPriceCents);
        self::assertSame(60, $q->unitDurationMin);
    }

    public function testExtrasAdded(): void
    {
        // Canapé 4 places + désinfection ozone (25€/20min)
        $q = $this->calc->calculateLine([
            'base_price_cents' => 7900,
            'base_duration_min' => 75,
            'variant' => ['price_delta_cents' => 1500, 'duration_delta_min' => 15],
            'extras' => [['label' => 'Ozone', 'price_cents' => 2500, 'duration_min' => 20]],
        ]);

        self::assertSame(11900, $q->unitPriceCents); // = devis validé en Phase 1
        self::assertSame(110, $q->unitDurationMin);
    }

    public function testPercentDurationModifier(): void
    {
        // Niveau de salissure « marqué » : +15 % sur la durée uniquement.
        $q = $this->calc->calculateLine([
            'base_price_cents' => 11900,
            'base_duration_min' => 110,
            'modifiers' => [
                ['target' => 'duration', 'type' => 'percent', 'value' => 1500, 'label' => 'Salissure marquée'],
            ],
        ]);

        self::assertSame(11900, $q->unitPriceCents); // prix inchangé
        self::assertSame(127, $q->unitDurationMin);  // 110 + round(110*15%) = 110 + 17
    }

    public function testMultiplierPrice(): void
    {
        $q = $this->calc->calculateLine([
            'base_price_cents' => 11900,
            'base_duration_min' => 110,
            'modifiers' => [
                ['target' => 'price', 'type' => 'multiplier', 'value' => 1200, 'label' => 'Majoration'],
            ],
        ]);

        self::assertSame(14280, $q->unitPriceCents); // 11900 × 1,2
    }

    public function testQuantityMultipliesLine(): void
    {
        $q = $this->calc->calculateLine([
            'base_price_cents' => 5900,
            'base_duration_min' => 45,
            'quantity' => 3,
        ]);

        self::assertSame(5900, $q->unitPriceCents);
        self::assertSame(17700, $q->linePriceCents);
        self::assertSame(135, $q->lineDurationMin);
    }

    public function testNeverNegative(): void
    {
        $q = $this->calc->calculateLine([
            'base_price_cents' => 1000,
            'base_duration_min' => 30,
            'modifiers' => [
                ['target' => 'price', 'type' => 'fixed', 'value' => -5000, 'label' => 'Remise excessive'],
            ],
        ]);

        self::assertSame(0, $q->unitPriceCents); // plancher à 0, jamais négatif
    }

    public function testFixedThenPercentOrder(): void
    {
        // base 10000 ; +2000 fixed → 12000 ; +10% percent sur 12000 → +1200 = 13200
        $q = $this->calc->calculateLine([
            'base_price_cents' => 10000,
            'base_duration_min' => 60,
            'modifiers' => [
                ['target' => 'price', 'type' => 'percent', 'value' => 1000, 'label' => 'Pct'],
                ['target' => 'price', 'type' => 'fixed', 'value' => 2000, 'label' => 'Fixe'],
            ],
        ]);

        self::assertSame(13200, $q->unitPriceCents);
    }

    public function testComponentsBreakdownIsExposed(): void
    {
        $q = $this->calc->calculateLine([
            'base_price_cents' => 7900,
            'base_duration_min' => 75,
            'base_label' => 'Nettoyage canapé',
            'variant' => ['label' => '4 places', 'price_delta_cents' => 1500, 'duration_delta_min' => 15],
            'extras' => [['label' => 'Ozone', 'price_cents' => 2500, 'duration_min' => 20]],
        ]);

        $labels = array_map(static fn ($c) => $c->label, $q->components);
        self::assertContains('Nettoyage canapé', $labels);
        self::assertContains('4 places', $labels);
        self::assertContains('Ozone', $labels);
    }
}
