<?php

declare(strict_types=1);

namespace Keepnew\Pricing;

use Keepnew\Support\Money;

/**
 * Calculateur de prix et de durée d'UNE ligne de prestation.
 *
 * Volontairement PUR : il ne touche pas à la base de données. Il reçoit des
 * valeurs déjà résolues (prix de base du mode, variante, extras, modificateurs)
 * et applique un ORDRE D'APPLICATION EXPLICITE ET DOCUMENTÉ. C'est ce qui rend
 * la grille tarifaire testable et débogable.
 *
 * Le chargement depuis le catalogue est fait par Catalog\SimulatorService, qui
 * appelle ce calculateur. Les moteurs des phases 3 (panier, remise cumul,
 * coupons, TVA) et 4 (disponibilité) s'appuient dessus sans le dupliquer.
 *
 * ── Ordre d'application (prix comme durée) ──────────────────────────────────
 *   1. BASE            : prix/durée du mode d'exécution (ou du service à défaut)
 *   2. VARIANTE        : `override` REMPLACE la base ; sinon `delta` s'AJOUTE
 *   3. EXTRAS          : chaque montant/durée s'AJOUTE
 *        → on obtient un sous-total S
 *   4. MODIFICATEURS (champs/options du formulaire), dans cet ordre :
 *        a. 'fixed'       : S += valeur (centimes / minutes)
 *        b. 'percent'     : S += percent(S_après_fixed, valeur_bp)  (cumul additif)
 *        c. 'multiplier'  : S = S × (valeur / 1000)   (1000 = ×1,0)
 *   5. UNITÉ arrondie à l'entier ; LIGNE = unité × quantité
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Représentations :
 *   - pourcentages en POINTS DE BASE (1000 = 10,00 %)
 *   - multiplicateurs en MILLIÈMES (1200 = ×1,2)
 */
final class PriceCalculator
{
    /**
     * @param array{
     *     base_price_cents:int,
     *     base_duration_min:int,
     *     base_label?:string,
     *     occupancy_duration_min?:int,
     *     variant?: array{label?:string, price_delta_cents?:int, duration_delta_min?:int, price_override_cents?:int|null, duration_override_min?:int|null}|null,
     *     extras?: list<array{label?:string, price_cents?:int, duration_min?:int}>,
     *     modifiers?: list<array{target:string, type:string, value:int, label?:string}>,
     *     quantity?:int
     * } $input
     */
    public function calculateLine(array $input): LineQuote
    {
        $quantity = max(1, (int) ($input['quantity'] ?? 1));
        $components = [];

        // 1. BASE ---------------------------------------------------------------
        $price = (int) $input['base_price_cents'];
        $duration = (int) $input['base_duration_min'];
        $components[] = new PriceComponent(
            $input['base_label'] ?? 'Prestation',
            $price,
            $duration,
            'base',
        );

        // 2. VARIANTE -----------------------------------------------------------
        $variant = $input['variant'] ?? null;
        if (is_array($variant)) {
            $override = $variant['price_override_cents'] ?? null;
            $durOverride = $variant['duration_override_min'] ?? null;

            $priceContribution = 0;
            $durationContribution = 0;

            if ($override !== null) {
                $priceContribution = (int) $override - $price; // remplace la base
            } else {
                $priceContribution = (int) ($variant['price_delta_cents'] ?? 0);
            }
            if ($durOverride !== null) {
                $durationContribution = (int) $durOverride - $duration;
            } else {
                $durationContribution = (int) ($variant['duration_delta_min'] ?? 0);
            }

            $price += $priceContribution;
            $duration += $durationContribution;

            $components[] = new PriceComponent(
                $variant['label'] ?? 'Variante',
                $priceContribution,
                $durationContribution,
                'variant',
            );
        }

        // 3. EXTRAS -------------------------------------------------------------
        foreach ($input['extras'] ?? [] as $extra) {
            $extraPrice = (int) ($extra['price_cents'] ?? 0);
            $extraDuration = (int) ($extra['duration_min'] ?? 0);
            $price += $extraPrice;
            $duration += $extraDuration;
            $components[] = new PriceComponent(
                $extra['label'] ?? 'Extra',
                $extraPrice,
                $extraDuration,
                'extra',
            );
        }

        // 4. MODIFICATEURS ------------------------------------------------------
        $modifiers = $input['modifiers'] ?? [];
        [$price, $priceModComponents] = $this->applyModifiers($price, $modifiers, 'price');
        [$duration, $durationModComponents] = $this->applyModifiers($duration, $modifiers, 'duration');

        // Fusionne les composants « modificateur » (prix + durée par libellé/ordre).
        foreach ($modifiers as $i => $mod) {
            $pc = $priceModComponents[$i] ?? 0;
            $dc = $durationModComponents[$i] ?? 0;
            if ($pc !== 0 || $dc !== 0) {
                $components[] = new PriceComponent(
                    $mod['label'] ?? 'Ajustement',
                    $pc,
                    $dc,
                    'modifier',
                );
            }
        }

        // Un prix/durée ne peut pas être négatif.
        $price = max(0, $price);
        $duration = max(0, $duration);

        // 5. LIGNE --------------------------------------------------------------
        return new LineQuote(
            unitPriceCents: $price,
            unitDurationMin: $duration,
            quantity: $quantity,
            linePriceCents: $price * $quantity,
            lineDurationMin: $duration * $quantity,
            occupancyDurationMin: (int) ($input['occupancy_duration_min'] ?? 0) * $quantity,
            components: $components,
        );
    }

    /**
     * Applique les modificateurs (fixed → percent → multiplier) sur un entier et
     * renvoie [valeur finale, contributions par index de modificateur].
     *
     * @param list<array{target:string, type:string, value:int, label?:string}> $modifiers
     * @return array{0:int, 1:array<int,int>}
     */
    private function applyModifiers(int $subtotal, array $modifiers, string $target): array
    {
        $contributions = [];
        $start = $subtotal;

        // a. fixed
        foreach ($modifiers as $i => $mod) {
            if (($mod['target'] ?? null) === $target && $mod['type'] === 'fixed') {
                $delta = (int) $mod['value'];
                $subtotal += $delta;
                $contributions[$i] = ($contributions[$i] ?? 0) + $delta;
            }
        }

        // b. percent (calculés sur le sous-total APRÈS les fixes, cumul additif)
        $afterFixed = $subtotal;
        foreach ($modifiers as $i => $mod) {
            if (($mod['target'] ?? null) === $target && $mod['type'] === 'percent') {
                $delta = Money::percentOf($afterFixed, (int) $mod['value']);
                $subtotal += $delta;
                $contributions[$i] = ($contributions[$i] ?? 0) + $delta;
            }
        }

        // c. multiplier (millièmes ; appliqués en dernier, en chaîne)
        foreach ($modifiers as $i => $mod) {
            if (($mod['target'] ?? null) === $target && $mod['type'] === 'multiplier') {
                $before = $subtotal;
                $subtotal = (int) round($subtotal * ((int) $mod['value']) / 1000);
                $contributions[$i] = ($contributions[$i] ?? 0) + ($subtotal - $before);
            }
        }

        // Cohérence : la somme des contributions doit refléter l'écart total.
        unset($start);

        return [$subtotal, $contributions];
    }
}
