<?php

declare(strict_types=1);

namespace Keepnew\Catalog;

use Keepnew\Core\Database;
use Keepnew\Pricing\LineQuote;
use Keepnew\Pricing\PriceCalculator;
use Keepnew\Support\Money;

/**
 * Simulateur de prix du back-office : à partir d'une configuration (service,
 * mode, variante, extras), résout les données du catalogue et produit un devis
 * de ligne détaillé, avec TVA. C'est l'outil « indispensable pour déboguer une
 * grille tarifaire ».
 *
 * S'appuie sur CatalogRepository (données) et PriceCalculator (logique pure).
 */
final class SimulatorService
{
    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly PriceCalculator $calculator,
        private readonly Database $db,
        private readonly LineResolver $resolver,
    ) {
    }

    /**
     * Calcule un devis pour une configuration donnée.
     *
     * @param list<int> $extraIds
     * @return array<string, mixed>  Devis prêt à afficher (HTVA, TVA, TVAC, détail)
     */
    public function quote(int $serviceId, string $mode, ?int $variantId, array $extraIds, int $quantity = 1): array
    {
        $service = $this->catalog->findService($serviceId);
        if ($service === null) {
            throw new \InvalidArgumentException('Prestation inconnue.');
        }

        $input = $this->resolver->resolve($serviceId, $mode, $variantId, $extraIds, $quantity);
        $line = $this->calculator->calculateLine($input);

        return $this->format($line, $service, $mode);
    }

    /**
     * Habille un LineQuote pour l'affichage : ajoute TVA, TVAC et libellés
     * formatés. Le taux de TVA est lu dans settings (jamais en dur).
     *
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private function format(LineQuote $line, array $service, string $mode): array
    {
        $vatRateBp = (int) ($this->db->scalar(
            "SELECT `value` FROM settings WHERE `key` = 'finance.vat_rate_bp'",
        ) ?? 2100);

        $htva = $line->linePriceCents;
        $vat = Money::vat($htva, $vatRateBp);
        $tvac = $htva + $vat;

        $components = array_map(
            static fn ($c): array => [
                'label' => $c->label,
                'price_cents' => $c->priceCents,
                'price_formatted' => Money::format($c->priceCents),
                'duration_min' => $c->durationMin,
                'kind' => $c->kind,
            ],
            $line->components,
        );

        return [
            'service' => $service['name'],
            'mode' => $mode,
            'mode_label' => $mode === 'onsite' ? 'À domicile' : 'Atelier',
            'quantity' => $line->quantity,
            'unit_price_cents' => $line->unitPriceCents,
            'unit_price_formatted' => Money::format($line->unitPriceCents),
            'unit_duration_min' => $line->unitDurationMin,
            'occupancy_duration_min' => $line->occupancyDurationMin,
            'components' => $components,
            'htva_cents' => $htva,
            'htva_formatted' => Money::format($htva),
            'vat_rate_bp' => $vatRateBp,
            'vat_cents' => $vat,
            'vat_formatted' => Money::format($vat),
            'tvac_cents' => $tvac,
            'tvac_formatted' => Money::format($tvac),
            'total_duration_min' => $line->lineDurationMin,
        ];
    }
}
