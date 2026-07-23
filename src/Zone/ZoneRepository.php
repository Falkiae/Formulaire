<?php

declare(strict_types=1);

namespace Keepnew\Zone;

use Keepnew\Core\Database;

/**
 * Accès en lecture et écriture aux zones de service (chalandise) et à leurs
 * sous-ressources : codes postaux, règles tarifaires, techniciens couvrants.
 *
 * Ces tables (service_zones, zone_postal_codes, zone_pricing_modifiers,
 * technician_zones) étaient jusqu'ici seulement lues par ZoneResolver
 * (src/Availability) via AvailabilityRepository::zones(). Ce repository
 * fournit l'écriture pour l'administration — la logique de résolution
 * (ZoneResolver) n'est pas modifiée.
 *
 * L'UI n'expose que les types 'radius' et 'postal_codes' (le type 'polygon'
 * reste supporté par le schéma et le moteur, simplement pas géré ici).
 */
final class ZoneRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    // --- Zones -------------------------------------------------------------

    /**
     * Zones avec un résumé (nb de codes postaux, nb de techniciens couvrants).
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->select(
            'SELECT z.*,
                    (SELECT COUNT(*) FROM zone_postal_codes zp WHERE zp.zone_id = z.id) AS postal_count,
                    (SELECT COUNT(*) FROM technician_zones tz WHERE tz.zone_id = z.id) AS technician_count
               FROM service_zones z
              ORDER BY z.priority, z.name',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM service_zones WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return $this->db->insert('service_zones', $this->columns($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $cols = $this->columns($data);
        $set = implode(', ', array_map(static fn (string $c): string => "$c = :$c", array_keys($cols)));
        $cols['id'] = $id;
        $this->db->run("UPDATE service_zones SET $set WHERE id = :id", $cols);
    }

    public function delete(int $id): void
    {
        // ON DELETE CASCADE nettoie zone_postal_codes / zone_pricing_modifiers /
        // technician_zones ; aucune commande ne référence zone_id.
        $this->db->run('DELETE FROM service_zones WHERE id = :id', ['id' => $id]);
    }

    /**
     * Normalise le sous-ensemble de colonnes autorisées en écriture.
     * L'UI ne propose que 'radius' et 'postal_codes' (le type 'polygon' reste
     * possible en base mais n'est pas piloté depuis cette interface).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        $type = in_array($data['zone_type'] ?? '', ['radius', 'postal_codes'], true)
            ? $data['zone_type']
            : 'postal_codes';

        return [
            'name' => (string) ($data['name'] ?? ''),
            'zone_type' => $type,
            'center_lat' => $type === 'radius' && ($data['center_lat'] ?? '') !== '' ? (float) $data['center_lat'] : null,
            'center_lng' => $type === 'radius' && ($data['center_lng'] ?? '') !== '' ? (float) $data['center_lng'] : null,
            'radius_km' => $type === 'radius' && ($data['radius_km'] ?? '') !== '' ? (float) $data['radius_km'] : null,
            'priority' => (int) ($data['priority'] ?? 100),
            'is_active' => ($data['is_active'] ?? false) ? 1 : 0,
        ];
    }

    // --- Codes postaux -------------------------------------------------------

    /**
     * @return list<string>
     */
    public function postalCodesFor(int $zoneId): array
    {
        $rows = $this->db->select(
            'SELECT postal_code FROM zone_postal_codes WHERE zone_id = :z ORDER BY postal_code',
            ['z' => $zoneId],
        );

        return array_map(static fn (array $r): string => (string) $r['postal_code'], $rows);
    }

    /**
     * Remplace l'ensemble des codes postaux d'une zone.
     *
     * @param list<string> $postalCodes
     */
    public function syncPostalCodes(int $zoneId, array $postalCodes): void
    {
        $codes = array_values(array_unique(array_filter(array_map(
            static fn (string $c): string => trim($c),
            $postalCodes,
        ), static fn (string $c): bool => $c !== '')));

        $this->db->transaction(function (Database $db) use ($zoneId, $codes): void {
            $db->run('DELETE FROM zone_postal_codes WHERE zone_id = :z', ['z' => $zoneId]);
            foreach ($codes as $code) {
                $db->run(
                    'INSERT INTO zone_postal_codes (zone_id, postal_code) VALUES (:z, :c)',
                    ['z' => $zoneId, 'c' => $code],
                );
            }
        });
    }

    // --- Règles tarifaires (modificateurs) ------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function modifiersFor(int $zoneId): array
    {
        return $this->db->select(
            'SELECT * FROM zone_pricing_modifiers WHERE zone_id = :z ORDER BY id',
            ['z' => $zoneId],
        );
    }

    public function addModifier(int $zoneId, string $type, ?string $calcType, ?int $calcValue, string $label): void
    {
        $this->db->insert('zone_pricing_modifiers', [
            'zone_id' => $zoneId,
            'modifier_type' => in_array($type, ['refuse', 'surcharge', 'discount'], true) ? $type : 'refuse',
            'calc_type' => in_array($calcType, ['fixed', 'percent'], true) ? $calcType : null,
            'calc_value' => $calcValue,
            'label' => $label !== '' ? $label : null,
        ]);
    }

    public function deleteModifier(int $id, int $zoneId): void
    {
        $this->db->run(
            'DELETE FROM zone_pricing_modifiers WHERE id = :id AND zone_id = :z',
            ['id' => $id, 'z' => $zoneId],
        );
    }

    // --- Techniciens couvrant la zone -----------------------------------------

    /**
     * @return list<int>
     */
    public function technicianIdsFor(int $zoneId): array
    {
        $rows = $this->db->select(
            'SELECT technician_id FROM technician_zones WHERE zone_id = :z',
            ['z' => $zoneId],
        );

        return array_map(static fn (array $r): int => (int) $r['technician_id'], $rows);
    }

    /**
     * Remplace l'ensemble des techniciens couvrant une zone.
     *
     * @param list<int> $technicianIds
     */
    public function syncTechnicians(int $zoneId, array $technicianIds): void
    {
        $this->db->transaction(function (Database $db) use ($zoneId, $technicianIds): void {
            $db->run('DELETE FROM technician_zones WHERE zone_id = :z', ['z' => $zoneId]);
            foreach (array_unique($technicianIds) as $techId) {
                $db->run(
                    'INSERT INTO technician_zones (technician_id, zone_id) VALUES (:t, :z)',
                    ['t' => (int) $techId, 'z' => $zoneId],
                );
            }
        });
    }

    /**
     * Techniciens actifs (pour la checklist de couverture d'une zone).
     *
     * @return list<array<string, mixed>>
     */
    public function activeTechnicians(): array
    {
        return $this->db->select(
            "SELECT id, first_name, last_name FROM technicians WHERE is_active = 1 ORDER BY last_name, first_name",
        );
    }
}
