<?php

declare(strict_types=1);

namespace Keepnew\Http\Api;

use Keepnew\Catalog\CatalogRepository;
use Keepnew\Core\Database;
use Keepnew\Core\Request;
use Keepnew\Core\Response;

/**
 * API publique du catalogue (lecture seule) — consommée par le widget.
 */
final class CatalogApiController
{
    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly Database $db,
    ) {
    }

    /**
     * GET /api/catalog — arborescence des catégories + prestations actives.
     */
    public function index(Request $request): Response
    {
        $modesByService = $this->catalog->allServiceModesByService();
        $services = array_map(
            static fn (array $s): array => [
                'id' => (int) $s['id'],
                'category_id' => (int) $s['category_id'],
                'name' => $s['name'],
                'slug' => $s['slug'],
                'short_description' => $s['short_description'],
                'variant_type' => $s['variant_type'],
                'base_price_cents' => (int) $s['base_price_cents'],
                'image_path' => $s['image_path'] ?? null,
                // Mise en avant (« Plus demandée », « La plus complète »…), texte
                // libre affiché tel quel sur la carte de la prestation.
                'badge_label' => $s['badge_label'] ?? null,
                // Modes ('onsite'/'workshop') que cette prestation supporte —
                // permet au widget de ne proposer que les prestations compatibles
                // avec le mode déjà choisi (ex. les véhicules ne sont qu'à domicile).
                'modes' => $modesByService[(int) $s['id']] ?? [],
            ],
            $this->catalog->allServices(onlyActive: true),
        );

        $contact = $this->db->select(
            "SELECT `key`, `value` FROM settings WHERE `key` IN ('company.phone', 'company.email', 'finance.vat_rate_bp')",
        );
        $contactByKey = [];
        foreach ($contact as $row) {
            $contactByKey[$row['key']] = $row['value'];
        }

        return Response::json([
            'categories' => $this->catalog->categoryTree(),
            'services' => $services,
            // Coordonnées publiques de l'entreprise — utilisées pour orienter vers
            // un devis sur mesure quand une adresse est hors zone de service.
            'contact' => [
                'phone' => $contactByKey['company.phone'] ?? null,
                'email' => $contactByKey['company.email'] ?? null,
            ],
            // Taux de TVA (points de base, ex. 2100 = 21 %) — permet au widget
            // d'afficher des prix TVAC avant même qu'un devis serveur existe
            // (même repli que CartPricingService::rules()).
            'vat_rate_bp' => (int) ($contactByKey['finance.vat_rate_bp'] ?? 2100),
        ]);
    }

    /**
     * GET /api/services/{id} — configuration d'une prestation (modes, variantes, extras).
     */
    public function service(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $service = $this->catalog->findService($id);
        if ($service === null || (int) $service['is_active'] !== 1) {
            return Response::json(['error' => 'Prestation inconnue.'], 404);
        }

        return Response::json([
            'id' => (int) $service['id'],
            'name' => $service['name'],
            'variant_type' => $service['variant_type'],
            'modes' => array_map(static fn (array $m): array => ['mode' => $m['mode']], $this->catalog->serviceModes($id)),
            'variants' => array_map(
                static fn (array $v): array => [
                    'id' => (int) $v['id'],
                    'label' => $v['label'],
                    'is_default' => (bool) $v['is_default'],
                ],
                $this->catalog->serviceVariants($id),
            ),
            'extras' => array_map(
                static fn (array $e): array => [
                    'id' => (int) $e['extra_id'],
                    'label' => $e['label'],
                    'price_cents' => (int) $e['eff_price_cents'],
                    'selection_type' => $e['selection_type'],
                    'exclusive_group' => $e['exclusive_group'],
                    'image_path' => $e['image_path'] ?? null,
                ],
                $this->catalog->serviceExtras($id),
            ),
        ]);
    }
}
