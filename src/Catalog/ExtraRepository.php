<?php

declare(strict_types=1);

namespace Keepnew\Catalog;

use Keepnew\Core\Database;
use Keepnew\Support\Slug;

/**
 * Catalogue central des extras (mutualisés) et gestion du pivot service_extras.
 *
 * Principe métier : un extra est créé UNE fois puis rattaché à plusieurs
 * services, avec surcharge optionnelle du prix/durée, sélection radio/checkbox
 * et groupe d'exclusivité. Aucune duplication de données.
 */
final class ExtraRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(bool $onlyActive = false): array
    {
        $where = $onlyActive ? 'WHERE is_active = 1' : '';

        return $this->db->select("SELECT * FROM extras {$where} ORDER BY label");
    }

    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM extras WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array{code:?string, label:string, description:?string, default_price_cents:int, default_duration_min:int, is_active:int} $data
     */
    public function create(array $data): int
    {
        return $this->db->insert('extras', [
            'code' => $this->uniqueCode($data['code'] ?? Slug::make($data['label'])),
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'default_price_cents' => $data['default_price_cents'],
            'default_duration_min' => $data['default_duration_min'],
            'is_active' => $data['is_active'],
        ]);
    }

    /**
     * @param array{label:string, description:?string, default_price_cents:int, default_duration_min:int, is_active:int} $data
     */
    public function update(int $id, array $data): void
    {
        $this->db->run(
            'UPDATE extras
             SET label = :label, description = :desc,
                 default_price_cents = :p, default_duration_min = :d, is_active = :a
             WHERE id = :id',
            [
                'label' => $data['label'],
                'desc' => $data['description'] ?? null,
                'p' => $data['default_price_cents'],
                'd' => $data['default_duration_min'],
                'a' => $data['is_active'],
                'id' => $id,
            ],
        );
    }

    /**
     * Définit (ou retire, avec null) l'image d'un extra.
     */
    public function setImage(int $id, ?string $path): void
    {
        $this->db->run('UPDATE extras SET image_path = :p WHERE id = :id', ['p' => $path, 'id' => $id]);
    }

    /**
     * Un extra n'est supprimable que s'il n'est utilisé dans aucune commande.
     * Sinon on le désactive.
     */
    public function deletable(int $id): bool
    {
        $inCartExtras = (int) $this->db->scalar('SELECT COUNT(*) FROM cart_item_extras WHERE extra_id = :id', ['id' => $id]);
        $inBookingExtras = (int) $this->db->scalar('SELECT COUNT(*) FROM booking_item_extras WHERE extra_id = :id', ['id' => $id]);

        return $inCartExtras === 0 && $inBookingExtras === 0;
    }

    public function deleteOrDeactivate(int $id): string
    {
        if ($this->deletable($id)) {
            // Cascade sur service_extras ; sécurisé car non commandé.
            $this->db->run('DELETE FROM extras WHERE id = :id', ['id' => $id]);

            return 'deleted';
        }
        $this->db->run('UPDATE extras SET is_active = 0 WHERE id = :id', ['id' => $id]);

        return 'deactivated';
    }

    // --- Pivot service_extras --------------------------------------------------

    /**
     * Rattache un extra à un service (idempotent). Surcharge prix/durée
     * facultative (null = valeur par défaut de l'extra).
     */
    public function attach(
        int $serviceId,
        int $extraId,
        ?int $priceCents,
        ?int $durationMin,
        string $selectionType,
        ?string $exclusiveGroup,
    ): void {
        $existing = $this->db->selectOne(
            'SELECT id FROM service_extras WHERE service_id = :s AND extra_id = :e',
            ['s' => $serviceId, 'e' => $extraId],
        );

        if ($existing !== null) {
            $this->db->run(
                'UPDATE service_extras
                 SET price_cents = :p, duration_min = :d, selection_type = :st,
                     exclusive_group = :g, is_active = 1
                 WHERE id = :id',
                [
                    'p' => $priceCents,
                    'd' => $durationMin,
                    'st' => $selectionType,
                    'g' => $exclusiveGroup,
                    'id' => (int) $existing['id'],
                ],
            );

            return;
        }

        $nextOrder = (int) $this->db->scalar(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM service_extras WHERE service_id = :s',
            ['s' => $serviceId],
        );

        $this->db->insert('service_extras', [
            'service_id' => $serviceId,
            'extra_id' => $extraId,
            'price_cents' => $priceCents,
            'duration_min' => $durationMin,
            'selection_type' => $selectionType,
            'exclusive_group' => $exclusiveGroup,
            'is_active' => 1,
            'sort_order' => $nextOrder,
        ]);
    }

    public function detach(int $serviceId, int $extraId): void
    {
        $this->db->run(
            'DELETE FROM service_extras WHERE service_id = :s AND extra_id = :e',
            ['s' => $serviceId, 'e' => $extraId],
        );
    }

    private function uniqueCode(string $base): string
    {
        $code = $base;
        $suffix = 1;
        while ((int) $this->db->scalar('SELECT COUNT(*) FROM extras WHERE code = :code', ['code' => $code]) > 0) {
            $suffix++;
            $code = $base . '-' . $suffix;
        }

        return $code;
    }
}
