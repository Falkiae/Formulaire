<?php

declare(strict_types=1);

namespace Keepnew\Http\Api;

use Keepnew\Catalog\CatalogRepository;
use Keepnew\Core\Request;
use Keepnew\Core\Response;

/**
 * API publique du catalogue (lecture seule) — consommée par le widget.
 */
final class CatalogApiController
{
    public function __construct(private readonly CatalogRepository $catalog)
    {
    }

    /**
     * GET /api/catalog — arborescence des catégories + prestations actives.
     */
    public function index(Request $request): Response
    {
        $services = array_map(
            static fn (array $s): array => [
                'id' => (int) $s['id'],
                'category_id' => (int) $s['category_id'],
                'name' => $s['name'],
                'slug' => $s['slug'],
                'short_description' => $s['short_description'],
                'variant_type' => $s['variant_type'],
                'base_price_cents' => (int) $s['base_price_cents'],
            ],
            $this->catalog->allServices(onlyActive: true),
        );

        return Response::json([
            'categories' => $this->catalog->categoryTree(),
            'services' => $services,
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
                static fn (array $v): array => ['id' => (int) $v['id'], 'label' => $v['label']],
                $this->catalog->serviceVariants($id),
            ),
            'extras' => array_map(
                static fn (array $e): array => [
                    'id' => (int) $e['extra_id'],
                    'label' => $e['label'],
                    'price_cents' => (int) $e['eff_price_cents'],
                    'selection_type' => $e['selection_type'],
                    'exclusive_group' => $e['exclusive_group'],
                ],
                $this->catalog->serviceExtras($id),
            ),
        ]);
    }
}
