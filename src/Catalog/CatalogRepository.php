<?php

declare(strict_types=1);

namespace Keepnew\Catalog;

use Keepnew\Core\Database;
use Keepnew\Support\Slug;

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

    /**
     * Compétences (skill_id) requises par un service.
     *
     * @return list<int>
     */
    public function serviceSkillIds(int $serviceId): array
    {
        $rows = $this->db->select('SELECT skill_id FROM service_skills WHERE service_id = :id', ['id' => $serviceId]);

        return array_map(static fn (array $r): int => (int) $r['skill_id'], $rows);
    }

    // --- Écriture (édition back-office) ---------------------------------------

    /**
     * Met à jour le prix/durée de base et l'activation d'un service, en
     * historisant l'ancien prix (price_history) pour ne pas fausser le passé.
     *
     * @param array{base_price_cents:int, base_duration_min:int, is_active:int} $data
     */
    /**
     * Met à jour l'identité d'une prestation : nom, catégorie, description
     * courte (le slug unique reste inchangé pour ne pas casser les liens).
     *
     * @param array{name:string, category_id:int, short_description?:string} $data
     */
    public function updateServiceMeta(int $serviceId, array $data): void
    {
        $this->db->run(
            'UPDATE services SET name = :name, category_id = :cat, short_description = :sd WHERE id = :id',
            [
                'name' => $data['name'],
                'cat' => $data['category_id'],
                'sd' => ($data['short_description'] ?? '') !== '' ? $data['short_description'] : null,
                'id' => $serviceId,
            ],
        );
    }

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

    // =========================================================================
    //  CATÉGORIES — création / modification / suppression / réordonnancement
    // =========================================================================

    public function findCategory(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM service_categories WHERE id = :id', ['id' => $id]);
    }

    /**
     * Crée une catégorie. Le slug est dérivé du nom et rendu unique.
     *
     * @param array{name:string, parent_id:?int, description:?string, icon:?string, is_visible:int} $data
     */
    public function createCategory(array $data): int
    {
        $nextOrder = (int) $this->db->scalar(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM service_categories
             WHERE parent_id <=> :parent',
            ['parent' => $data['parent_id']],
        );

        return $this->db->insert('service_categories', [
            'parent_id' => $data['parent_id'],
            'name' => $data['name'],
            'slug' => $this->uniqueSlug('service_categories', Slug::make($data['name'])),
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'is_visible' => $data['is_visible'],
            'sort_order' => $nextOrder,
        ]);
    }

    /**
     * @param array{name:string, parent_id:?int, description:?string, icon:?string, is_visible:int} $data
     */
    public function updateCategory(int $id, array $data): void
    {
        $this->db->run(
            'UPDATE service_categories
             SET name = :name, parent_id = :parent, description = :desc, icon = :icon, is_visible = :vis
             WHERE id = :id',
            [
                'name' => $data['name'],
                'parent' => $data['parent_id'],
                'desc' => $data['description'] ?? null,
                'icon' => $data['icon'] ?? null,
                'vis' => $data['is_visible'],
                'id' => $id,
            ],
        );
    }

    /**
     * Une catégorie n'est supprimable que si elle n'a ni sous-catégorie ni
     * service. Sinon on invite à la masquer (désactivation) plutôt qu'à casser
     * des références.
     */
    public function categoryDeletable(int $id): bool
    {
        $children = (int) $this->db->scalar('SELECT COUNT(*) FROM service_categories WHERE parent_id = :id', ['id' => $id]);
        $services = (int) $this->db->scalar('SELECT COUNT(*) FROM services WHERE category_id = :id', ['id' => $id]);

        return $children === 0 && $services === 0;
    }

    public function deleteCategory(int $id): void
    {
        if ($this->categoryDeletable($id)) {
            $this->db->run('DELETE FROM service_categories WHERE id = :id', ['id' => $id]);
        }
    }

    // =========================================================================
    //  SERVICES — création / duplication / suppression
    // =========================================================================

    /**
     * Crée un service minimal (nom + catégorie + prix/durée de base) et ses
     * deux modes potentiels désactivés, prêts à être configurés.
     *
     * @param array{category_id:int, name:string, base_price_cents:int, base_duration_min:int} $data
     */
    public function createService(array $data): int
    {
        return $this->db->transaction(function (Database $db) use ($data): int {
            $nextOrder = (int) $db->scalar(
                'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM services WHERE category_id = :c',
                ['c' => $data['category_id']],
            );

            $id = $db->insert('services', [
                'category_id' => $data['category_id'],
                'name' => $data['name'],
                'slug' => $this->uniqueSlug('services', Slug::make($data['name'])),
                'variant_type' => 'none',
                'base_price_cents' => $data['base_price_cents'],
                'base_duration_min' => $data['base_duration_min'],
                'booking_mode' => 'instant',
                'is_active' => 0, // inactif tant qu'il n'est pas configuré
                'sort_order' => $nextOrder,
            ]);

            // Mode « à domicile » activé par défaut, « atelier » présent mais inactif.
            $db->insert('service_delivery_modes', [
                'service_id' => $id,
                'mode' => 'onsite',
                'price_cents' => $data['base_price_cents'],
                'active_duration_min' => $data['base_duration_min'],
                'is_active' => 1,
            ]);

            return $id;
        });
    }

    /**
     * Duplique un service (avec ses modes, variantes et extras rattachés) sous
     * un nouveau nom « … (copie) », inactif. Permet de créer une variante
     * d'offre sans repartir de zéro.
     */
    public function duplicateService(int $serviceId): ?int
    {
        $service = $this->findService($serviceId);
        if ($service === null) {
            return null;
        }

        return $this->db->transaction(function (Database $db) use ($service, $serviceId): int {
            $newName = $service['name'] . ' (copie)';
            $newId = $db->insert('services', [
                'category_id' => $service['category_id'],
                'name' => $newName,
                'slug' => $this->uniqueSlug('services', Slug::make($newName)),
                'short_description' => $service['short_description'],
                'description' => $service['description'],
                'variant_type' => $service['variant_type'],
                'base_price_cents' => $service['base_price_cents'],
                'base_duration_min' => $service['base_duration_min'],
                'booking_mode' => $service['booking_mode'],
                'is_active' => 0,
                'sort_order' => (int) $service['sort_order'] + 1,
            ]);

            foreach ($db->select('SELECT * FROM service_delivery_modes WHERE service_id = :id', ['id' => $serviceId]) as $m) {
                $db->insert('service_delivery_modes', [
                    'service_id' => $newId,
                    'mode' => $m['mode'],
                    'price_cents' => $m['price_cents'],
                    'active_duration_min' => $m['active_duration_min'],
                    'occupancy_duration_min' => $m['occupancy_duration_min'],
                    'travel_surcharge_cents' => $m['travel_surcharge_cents'],
                    'is_active' => $m['is_active'],
                ]);
            }
            foreach ($db->select('SELECT * FROM service_variants WHERE service_id = :id', ['id' => $serviceId]) as $v) {
                $db->insert('service_variants', [
                    'service_id' => $newId,
                    'code' => $v['code'],
                    'label' => $v['label'],
                    'price_delta_cents' => $v['price_delta_cents'],
                    'duration_delta_min' => $v['duration_delta_min'],
                    'price_override_cents' => $v['price_override_cents'],
                    'duration_override_min' => $v['duration_override_min'],
                    'is_default' => $v['is_default'],
                    'is_active' => $v['is_active'],
                    'sort_order' => $v['sort_order'],
                ]);
            }
            foreach ($db->select('SELECT * FROM service_extras WHERE service_id = :id', ['id' => $serviceId]) as $se) {
                $db->insert('service_extras', [
                    'service_id' => $newId,
                    'extra_id' => $se['extra_id'],
                    'price_cents' => $se['price_cents'],
                    'duration_min' => $se['duration_min'],
                    'selection_type' => $se['selection_type'],
                    'exclusive_group' => $se['exclusive_group'],
                    'requires_variant_id' => null, // les variantes ont de nouveaux ID
                    'is_active' => $se['is_active'],
                    'sort_order' => $se['sort_order'],
                ]);
            }

            return $newId;
        });
    }

    /**
     * Un service n'est réellement supprimable que s'il n'a jamais été commandé
     * (ni panier ni ligne de commande). Sinon, on désactive.
     */
    public function serviceDeletable(int $serviceId): bool
    {
        $inCarts = (int) $this->db->scalar('SELECT COUNT(*) FROM cart_items WHERE service_id = :id', ['id' => $serviceId]);
        $inBookings = (int) $this->db->scalar('SELECT COUNT(*) FROM booking_items WHERE service_id = :id', ['id' => $serviceId]);

        return $inCarts === 0 && $inBookings === 0;
    }

    /**
     * Supprime un service si possible (cascade sur modes/variantes/extras),
     * sinon le désactive pour préserver l'historique. Renvoie l'action réalisée.
     */
    public function deleteOrDeactivateService(int $serviceId): string
    {
        if ($this->serviceDeletable($serviceId)) {
            $this->db->run('DELETE FROM services WHERE id = :id', ['id' => $serviceId]);

            return 'deleted';
        }

        $this->setServiceActive($serviceId, false);

        return 'deactivated';
    }

    // =========================================================================
    //  VARIANTES — création / modification / suppression
    // =========================================================================

    /**
     * @param array{label:string, price_delta_cents:int, duration_delta_min:int, is_active:int} $data
     */
    public function createVariant(int $serviceId, array $data): int
    {
        $nextOrder = (int) $this->db->scalar(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM service_variants WHERE service_id = :s',
            ['s' => $serviceId],
        );

        return $this->db->insert('service_variants', [
            'service_id' => $serviceId,
            'code' => $this->uniqueVariantCode($serviceId, Slug::make($data['label'])),
            'label' => $data['label'],
            'price_delta_cents' => $data['price_delta_cents'],
            'duration_delta_min' => $data['duration_delta_min'],
            'is_active' => $data['is_active'],
            'sort_order' => $nextOrder,
        ]);
    }

    /**
     * @param array{label:string, price_delta_cents:int, duration_delta_min:int, is_active:int} $data
     */
    public function updateVariant(int $variantId, array $data): void
    {
        $this->db->run(
            'UPDATE service_variants
             SET label = :label, price_delta_cents = :pd, duration_delta_min = :dd, is_active = :a
             WHERE id = :id',
            [
                'label' => $data['label'],
                'pd' => $data['price_delta_cents'],
                'dd' => $data['duration_delta_min'],
                'a' => $data['is_active'],
                'id' => $variantId,
            ],
        );
    }

    public function variantDeletable(int $variantId): bool
    {
        $inCarts = (int) $this->db->scalar('SELECT COUNT(*) FROM cart_items WHERE variant_id = :id', ['id' => $variantId]);
        $inBookings = (int) $this->db->scalar('SELECT COUNT(*) FROM booking_items WHERE variant_id = :id', ['id' => $variantId]);

        return $inCarts === 0 && $inBookings === 0;
    }

    public function deleteVariant(int $variantId): bool
    {
        if (!$this->variantDeletable($variantId)) {
            $this->db->run('UPDATE service_variants SET is_active = 0 WHERE id = :id', ['id' => $variantId]);

            return false;
        }
        $this->db->run('DELETE FROM service_variants WHERE id = :id', ['id' => $variantId]);

        return true;
    }

    // =========================================================================
    //  RÉORDONNANCEMENT (drag & drop) — liste ordonnée d'IDs
    // =========================================================================

    /**
     * Applique un nouvel ordre à un ensemble de lignes. Le nom de table et de
     * colonne est whitelisté (jamais issu de l'entrée utilisateur brute).
     *
     * @param list<int> $orderedIds
     */
    public function reorder(string $entity, array $orderedIds): void
    {
        $table = match ($entity) {
            'categories' => 'service_categories',
            'services' => 'services',
            'variants' => 'service_variants',
            'extras_pivot' => 'service_extras',
            default => throw new \InvalidArgumentException('Entité non réordonnable.'),
        };

        $this->db->transaction(function (Database $db) use ($table, $orderedIds): void {
            $position = 0;
            foreach ($orderedIds as $id) {
                $db->run(
                    "UPDATE `{$table}` SET sort_order = :pos WHERE id = :id",
                    ['pos' => $position, 'id' => (int) $id],
                );
                $position++;
            }
        });
    }

    // =========================================================================
    //  Helpers internes
    // =========================================================================

    /**
     * Garantit l'unicité d'un slug sur une table donnée (suffixe -2, -3…).
     */
    private function uniqueSlug(string $table, string $base): string
    {
        $whitelist = ['service_categories', 'services'];
        if (!in_array($table, $whitelist, true)) {
            throw new \InvalidArgumentException('Table non autorisée pour un slug.');
        }

        $slug = $base;
        $suffix = 1;
        while ((int) $this->db->scalar("SELECT COUNT(*) FROM `{$table}` WHERE slug = :slug", ['slug' => $slug]) > 0) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }

    private function uniqueVariantCode(int $serviceId, string $base): string
    {
        $code = $base;
        $suffix = 1;
        while ((int) $this->db->scalar(
            'SELECT COUNT(*) FROM service_variants WHERE service_id = :s AND code = :code',
            ['s' => $serviceId, 'code' => $code],
        ) > 0) {
            $suffix++;
            $code = $base . '-' . $suffix;
        }

        return $code;
    }
}
