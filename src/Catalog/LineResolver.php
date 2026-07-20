<?php

declare(strict_types=1);

namespace Keepnew\Catalog;

/**
 * Résout une configuration de ligne (service + mode + variante + extras) en
 * l'entrée attendue par Pricing\PriceCalculator, à partir du catalogue en base.
 *
 * Mutualisé entre le simulateur (une ligne) et le moteur de panier (n lignes),
 * pour garantir des prix identiques partout.
 */
final class LineResolver
{
    public function __construct(private readonly CatalogRepository $catalog)
    {
    }

    /**
     * @param list<int> $extraIds
     * @return array<string, mixed> Entrée pour PriceCalculator::calculateLine
     * @throws \InvalidArgumentException si la configuration est invalide
     */
    public function resolve(int $serviceId, string $mode, ?int $variantId, array $extraIds, int $quantity = 1): array
    {
        $service = $this->catalog->findService($serviceId);
        if ($service === null) {
            throw new \InvalidArgumentException('Prestation inconnue.');
        }

        $modeRow = $this->catalog->serviceMode($serviceId, $mode);
        if ($modeRow === null) {
            throw new \InvalidArgumentException("Mode « {$mode} » indisponible pour cette prestation.");
        }

        $basePrice = $modeRow['price_cents'] !== null
            ? (int) $modeRow['price_cents']
            : (int) $service['base_price_cents'];
        $baseDuration = $modeRow['active_duration_min'] !== null
            ? (int) $modeRow['active_duration_min']
            : (int) $service['base_duration_min'];

        $input = [
            'base_price_cents' => $basePrice,
            'base_duration_min' => $baseDuration,
            'base_label' => (string) $service['name'],
            'occupancy_duration_min' => (int) ($modeRow['occupancy_duration_min'] ?? 0),
            'extras' => [],
            'quantity' => max(1, $quantity),
        ];

        if ($variantId !== null) {
            $variant = $this->catalog->findVariant($variantId);
            if ($variant === null || (int) $variant['service_id'] !== $serviceId) {
                throw new \InvalidArgumentException('Variante invalide pour cette prestation.');
            }
            $input['variant'] = [
                'label' => (string) $variant['label'],
                'price_delta_cents' => (int) $variant['price_delta_cents'],
                'duration_delta_min' => (int) $variant['duration_delta_min'],
                'price_override_cents' => $variant['price_override_cents'] !== null ? (int) $variant['price_override_cents'] : null,
                'duration_override_min' => $variant['duration_override_min'] !== null ? (int) $variant['duration_override_min'] : null,
            ];
        }

        if ($extraIds !== []) {
            $available = [];
            foreach ($this->catalog->serviceExtras($serviceId) as $se) {
                $available[(int) $se['extra_id']] = $se;
            }
            foreach ($extraIds as $extraId) {
                if (isset($available[$extraId])) {
                    $se = $available[$extraId];
                    $input['extras'][] = [
                        'label' => (string) $se['label'],
                        'price_cents' => (int) $se['eff_price_cents'],
                        'duration_min' => (int) $se['eff_duration_min'],
                    ];
                }
            }
        }

        return $input;
    }
}
