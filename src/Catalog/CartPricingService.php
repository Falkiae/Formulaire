<?php

declare(strict_types=1);

namespace Keepnew\Catalog;

use Keepnew\Core\Database;
use Keepnew\Pricing\CartPricer;
use Keepnew\Pricing\CartQuote;
use Keepnew\Pricing\PricingRules;
use Keepnew\Support\Clock;
use Keepnew\Support\Money;

/**
 * Tarification d'un panier depuis la base : charge les règles (settings /
 * duration_rules), résout chaque ligne (LineResolver) et applique le moteur
 * CartPricer, coupon compris. Point d'entrée métier du prix du panier.
 */
final class CartPricingService
{
    public function __construct(
        private readonly Database $db,
        private readonly LineResolver $resolver,
        private readonly CartPricer $pricer,
    ) {
    }

    /**
     * Calcule le devis d'un panier.
     *
     * Chaque ligne : ['service_id','mode','variant_id'?,'extra_ids'?,'quantity'?,'address_key'?].
     * L'`address_key` regroupe les prestations pour la remise cumul ; par défaut
     * on regroupe par mode (onsite / workshop), ce qui correspond à « à la même
     * adresse » (le client a une adresse, l'atelier en a une autre).
     *
     * @param list<array<string, mixed>> $lines
     */
    public function price(array $lines, ?string $couponCode = null, int $travelSurchargeCents = 0): CartQuote
    {
        $rules = $this->loadRules();

        $resolved = [];
        foreach ($lines as $line) {
            $mode = (string) ($line['mode'] ?? 'onsite');
            $input = $this->resolver->resolve(
                (int) $line['service_id'],
                $mode,
                isset($line['variant_id']) && (int) $line['variant_id'] > 0 ? (int) $line['variant_id'] : null,
                array_map('intval', (array) ($line['extra_ids'] ?? [])),
                max(1, (int) ($line['quantity'] ?? 1)),
            );
            $resolved[] = [
                'input' => $input,
                'address_key' => (string) ($line['address_key'] ?? $mode),
            ];
        }

        $coupon = $couponCode !== null && $couponCode !== '' ? $this->loadCoupon($couponCode) : null;

        return $this->pricer->price($resolved, $rules, $coupon, $travelSurchargeCents);
    }

    /**
     * Charge les règles de tarification depuis la configuration (jamais en dur).
     */
    private function loadRules(): PricingRules
    {
        $settings = [];
        foreach ($this->db->select("SELECT `key`, `value` FROM settings WHERE `group` IN ('pricing','finance')") as $row) {
            $settings[$row['key']] = $row['value'];
        }

        // Décote de durée : lue depuis duration_rules (type cumul_discount actif).
        $durationCumulBp = (int) ($this->db->scalar(
            "SELECT calc_value FROM duration_rules
             WHERE rule_type = 'cumul_discount' AND calc_type = 'percent' AND is_active = 1
             ORDER BY priority LIMIT 1",
        ) ?? 0);

        return new PricingRules(
            multiItemPercentBp: (int) ($settings['discount.multi_item_percent_bp'] ?? 1000),
            multiItemMinItems: (int) ($settings['discount.multi_item_min_items'] ?? 2),
            multiItemAppliesFromItem: 2,
            durationCumulPercentBp: $durationCumulBp,
            vatRateBp: (int) ($settings['finance.vat_rate_bp'] ?? 2100),
        );
    }

    /**
     * Charge et valide un coupon (actif, dans sa fenêtre, quota non épuisé).
     *
     * @return array{type:string, value:int, min_order_cents:int}|null
     */
    private function loadCoupon(string $code): ?array
    {
        $coupon = $this->db->selectOne(
            'SELECT * FROM coupons WHERE code = :code AND is_active = 1',
            ['code' => $code],
        );
        if ($coupon === null) {
            return null;
        }

        $now = Clock::nowUtc()->format('Y-m-d H:i:s');
        if ($coupon['starts_at'] !== null && $coupon['starts_at'] > $now) {
            return null;
        }
        if ($coupon['ends_at'] !== null && $coupon['ends_at'] < $now) {
            return null;
        }
        if ($coupon['max_redemptions'] !== null && (int) $coupon['redeemed_count'] >= (int) $coupon['max_redemptions']) {
            return null;
        }

        return [
            'type' => (string) $coupon['discount_type'],
            'value' => (int) $coupon['discount_value'],
            'min_order_cents' => (int) $coupon['min_order_cents'],
        ];
    }

    /**
     * Habille un CartQuote pour l'affichage (montants formatés).
     *
     * @return array<string, mixed>
     */
    public function format(CartQuote $quote): array
    {
        $data = $quote->toArray();
        $data['lines_subtotal_formatted'] = Money::format($quote->linesSubtotalCents);
        $data['cumul_discount_formatted'] = Money::format($quote->cumulDiscountCents);
        $data['coupon_discount_formatted'] = Money::format($quote->couponDiscountCents);
        $data['travel_surcharge_formatted'] = Money::format($quote->travelSurchargeCents);
        $data['net_htva_formatted'] = Money::format($quote->netHtvaCents);
        $data['vat_formatted'] = Money::format($quote->vatCents);
        $data['total_tvac_formatted'] = Money::format($quote->totalTvacCents);

        return $data;
    }
}
