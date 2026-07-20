<?php

declare(strict_types=1);

namespace Keepnew\Catalog;

use Keepnew\Core\Database;

/**
 * Accès en lecture/écriture au catalogue : catégories, services, modes,
 * variantes, extras. Toutes les requêtes sont préparées (via Database).
 *
 * Cette couche ne fait AUCUN calcul de prix : elle expose des données brutes du
 * catalogue. Le calcul est la responsabilité de Pricing\PriceCalculator.
 */
final class CatalogRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    // --- Catégories ------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function allCategories(): array
    {
        return $this->db->select(
            'SELECT * FROM service_categories ORDER BY COALESCE(parent_id, 0), sort_order, name',
        );
    }

    /**
     * Arborescence des catégories : chaque nœud reçoit une clé `children`.
     *
     * @return list<array<string, mixed>>
     */
    public function categoryTree(): array
    {
        $flat = $this->allCategories();
        $byParent = [];
        foreach ($flat as $row) {
            $byParent[$row['parent_id'] ?? 0][] = $row;
        }

        $build = static function (int $parent) use (&$build, $byParent): array {
            $nodes = [];
            foreach ($byParent[$parent] ?? [] as $row) {
                $row['children'] = $build((int) $row['id']);
                $nodes[] = $row;
            }

            return $nodes;
        };

        return $build(0);
    }

    // --- Services --------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function allServices(bool $onlyActive = false): array
    {
        $where = $onlyActive ? 'WHERE s.is_active = 1' : '';

        return $this->db->select(
            "SELECT s.*, c.name AS category_name
             FROM services s
             JOIN service_categories c ON c.id = s.category_id
             {$where}
             ORDER BY c.sort_order, s.sort_order, s.name",
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findService(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM services WHERE id = :id', ['id' => $id]);
    }

    /**
     * Modes d'exécution (onsite/workshop) d'un service.
     *
     * @return list<array<string, mixed>>
     */
    public function serviceModes(int $serviceId): array
    {
        return $this->db->select(
            'SELECT * FROM service_delivery_modes WHERE service_id = :id ORDER BY mode',
            ['id' => $serviceId],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function serviceMode(int $serviceId, string $mode): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM service_delivery_modes WHERE service_id = :id AND mode = :mode',
            ['id' => $serviceId, 'mode' => $mode],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function serviceVariants(int $serviceId, bool $onlyActive = true): array
    {
        $where = $onlyActive ? 'AND is_active = 1' : '';

        return $this->db->select(
            "SELECT * FROM service_variants WHERE service_id = :id {$where} ORDER BY sort_order, label",
            ['id' => $serviceId],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findVariant(int $variantId): ?array
    {
        return $this->db->selectOne('SELECT * FROM service_variants WHERE id = :id', ['id' => $variantId]);
    }

    /**
     * Extras rattachés à un service, avec prix/durée EFFECTIFS (surcharge du
     * pivot service_extras si présente, sinon valeur par défaut de l'extra).
     *
     * @return list<array<string, mixed>>
     */
    public function serviceExtras(int $serviceId, bool $onlyActive = true): array
    {
        $where = $onlyActive ? 'AND se.is_active = 1 AND e.is_active = 1' : '';

        return $this->db->select(
            "SELECT
                se.id            AS pivot_id,
                e.id             AS extra_id,
                e.code,
                e.label,
                COALESCE(se.price_cents, e.default_price_cents)   AS eff_price_cents,
                COALESCE(se.duration_min, e.default_duration_min) AS eff_duration_min,
                se.selection_type,
                se.exclusive_group,
                se.requires_variant_id,
                se.sort_order
             FROM service_extras se
             JOIN extras e ON e.id = se.extra_id
             WHERE se.service_id = :id {$where}
             ORDER BY se.sort_order, e.label",
            ['id' => $serviceId],
        );
    }

    // --- Écriture (édition back-office) ---------------------------------------

    /**
     * Met à jour le prix/durée de base et l'activation d'un service, en
     * historisant l'ancien prix (price_history) pour ne pas fausser le passé.
     *
     * @param array{base_price_cents:int, base_duration_min:int, is_active:int} $data
     */
    public function updateServiceBase(int $serviceId, array $data, ?int $userId): void
    {
        $this->db->transaction(function (Database $db) use ($serviceId, $data, $userId): void {
            $current = $db->selectOne('SELECT * FROM services WHERE id = :id FOR UPDATE', ['id' => $serviceId]);
            if ($current === null) {
                return;
            }

            if ((int) $current['base_price_cents'] !== $data['base_price_cents']
                || (int) $current['base_duration_min'] !== $data['base_duration_min']) {
                $db->insert('price_history', [
                    'entity_type' => 'service',
                    'entity_id' => $serviceId,
                    'old_price_cents' => (int) $current['base_price_cents'],
                    'new_price_cents' => $data['base_price_cents'],
                    'old_duration_min' => (int) $current['base_duration_min'],
                    'new_duration_min' => $data['base_duration_min'],
                    'changed_by' => $userId,
                ]);
            }

            $db->run(
                'UPDATE services
                 SET base_price_cents = :p, base_duration_min = :d, is_active = :a
                 WHERE id = :id',
                [
                    'p' => $data['base_price_cents'],
                    'd' => $data['base_duration_min'],
                    'a' => $data['is_active'],
                    'id' => $serviceId,
                ],
            );
        });
    }

    /**
     * Met à jour le prix/durée d'un mode d'exécution donné, avec historisation.
     */
    public function updateModePricing(
        int $serviceId,
        string $mode,
        ?int $priceCents,
        ?int $activeDurationMin,
        ?int $occupancyDurationMin,
        ?int $userId,
    ): void {
        $this->db->transaction(function (Database $db) use (
            $serviceId,
            $mode,
            $priceCents,
            $activeDurationMin,
            $occupancyDurationMin,
            $userId,
        ): void {
            $current = $db->selectOne(
                'SELECT * FROM service_delivery_modes WHERE service_id = :id AND mode = :m FOR UPDATE',
                ['id' => $serviceId, 'm' => $mode],
            );
            if ($current === null) {
                return;
            }

            if ((int) $current['price_cents'] !== (int) $priceCents) {
                $db->insert('price_history', [
                    'entity_type' => 'service_delivery_mode',
                    'entity_id' => (int) $current['id'],
                    'old_price_cents' => $current['price_cents'] !== null ? (int) $current['price_cents'] : null,
                    'new_price_cents' => $priceCents,
                    'old_duration_min' => $current['active_duration_min'] !== null ? (int) $current['active_duration_min'] : null,
                    'new_duration_min' => $activeDurationMin,
                    'changed_by' => $userId,
                ]);
            }

            $db->run(
                'UPDATE service_delivery_modes
                 SET price_cents = :p, active_duration_min = :ad, occupancy_duration_min = :oc
                 WHERE service_id = :id AND mode = :m',
                [
                    'p' => $priceCents,
                    'ad' => $activeDurationMin,
                    'oc' => $occupancyDurationMin,
                    'id' => $serviceId,
                    'm' => $mode,
                ],
            );
        });
    }

    /**
     * Active/désactive un service sans le supprimer (historique préservé).
     */
    public function setServiceActive(int $serviceId, bool $active): void
    {
        $this->db->run(
            'UPDATE services SET is_active = :a WHERE id = :id',
            ['a' => $active ? 1 : 0, 'id' => $serviceId],
        );
    }
}
