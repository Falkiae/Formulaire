<?php

declare(strict_types=1);

namespace Keepnew\Technician;

use Keepnew\Core\Database;
use Keepnew\Support\Clock;

/**
 * Accès en lecture ET écriture aux techniciens et à leurs ressources
 * (compétences, disponibilités récurrentes, absences).
 *
 * Ces tables étaient jusqu'ici seulement lues par le moteur de disponibilité
 * (src/Availability). Ce repository fournit l'écriture pour l'administration.
 *
 * Rappel fuseaux : technician_availability.start_time/end_time sont en heure
 * locale Bruxelles ; technician_time_off.starts_at/ends_at sont en UTC.
 */
final class TechnicianRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    // --- Techniciens -----------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->select(
            'SELECT t.*, u.email AS account_email
               FROM technicians t
               LEFT JOIN users u ON u.id = t.user_id
              ORDER BY t.is_active DESC, t.last_name, t.first_name',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM technicians WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return $this->db->insert('technicians', $this->columns($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $cols = $this->columns($data);
        $set = implode(', ', array_map(static fn (string $c): string => "$c = :$c", array_keys($cols)));
        $cols['id'] = $id;
        $this->db->run("UPDATE technicians SET $set WHERE id = :id", $cols);
    }

    /**
     * Normalise le sous-ensemble de colonnes autorisées en écriture.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        return [
            'user_id' => ($data['user_id'] ?? 0) > 0 ? (int) $data['user_id'] : null,
            'first_name' => (string) ($data['first_name'] ?? ''),
            'last_name' => (string) ($data['last_name'] ?? ''),
            'phone' => ($data['phone'] ?? '') !== '' ? (string) $data['phone'] : null,
            'email' => ($data['email'] ?? '') !== '' ? (string) $data['email'] : null,
            'home_lat' => ($data['home_lat'] ?? '') !== '' ? (float) $data['home_lat'] : null,
            'home_lng' => ($data['home_lng'] ?? '') !== '' ? (float) $data['home_lng'] : null,
            'home_postal' => ($data['home_postal'] ?? '') !== '' ? (string) $data['home_postal'] : null,
            'max_jobs_per_day' => max(1, (int) ($data['max_jobs_per_day'] ?? 6)),
            'is_active' => ($data['is_active'] ?? false) ? 1 : 0,
        ];
    }

    // --- Compétences (skills) --------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function allSkills(): array
    {
        return $this->db->select('SELECT * FROM skills ORDER BY label');
    }

    /**
     * @return list<int>
     */
    public function skillIdsFor(int $technicianId): array
    {
        $rows = $this->db->select(
            'SELECT skill_id FROM technician_skills WHERE technician_id = :t',
            ['t' => $technicianId],
        );

        return array_map(static fn (array $r): int => (int) $r['skill_id'], $rows);
    }

    /**
     * Remplace l'ensemble des compétences d'un technicien.
     *
     * @param list<int> $skillIds
     */
    public function syncSkills(int $technicianId, array $skillIds): void
    {
        $this->db->transaction(function (Database $db) use ($technicianId, $skillIds): void {
            $db->run('DELETE FROM technician_skills WHERE technician_id = :t', ['t' => $technicianId]);
            foreach (array_unique($skillIds) as $skillId) {
                $db->run(
                    'INSERT INTO technician_skills (technician_id, skill_id) VALUES (:t, :s)',
                    ['t' => $technicianId, 's' => (int) $skillId],
                );
            }
        });
    }

    public function createSkill(string $code, string $label): void
    {
        $this->db->run(
            'INSERT INTO skills (code, label) VALUES (:code, :label)',
            ['code' => $code, 'label' => $label],
        );
    }

    public function updateSkill(int $id, string $code, string $label): void
    {
        $this->db->run(
            'UPDATE skills SET code = :code, label = :label WHERE id = :id',
            ['code' => $code, 'label' => $label, 'id' => $id],
        );
    }

    // --- Disponibilités récurrentes -------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function availabilityFor(int $technicianId): array
    {
        return $this->db->select(
            'SELECT ta.*, l.name AS location_name
               FROM technician_availability ta
               LEFT JOIN locations l ON l.id = ta.location_id
              WHERE ta.technician_id = :t
              ORDER BY ta.weekday, ta.start_time',
            ['t' => $technicianId],
        );
    }

    /**
     * @param array{weekday:int,start_time:string,end_time:string,location_id?:?int,valid_from?:?string,valid_until?:?string} $data
     */
    public function addAvailability(int $technicianId, array $data): void
    {
        $this->db->insert('technician_availability', [
            'technician_id' => $technicianId,
            'weekday' => $data['weekday'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'location_id' => ($data['location_id'] ?? 0) > 0 ? (int) $data['location_id'] : null,
            'valid_from' => ($data['valid_from'] ?? '') !== '' ? $data['valid_from'] : null,
            'valid_until' => ($data['valid_until'] ?? '') !== '' ? $data['valid_until'] : null,
        ]);
    }

    public function deleteAvailability(int $id, int $technicianId): void
    {
        $this->db->run(
            'DELETE FROM technician_availability WHERE id = :id AND technician_id = :t',
            ['id' => $id, 't' => $technicianId],
        );
    }

    // --- Absences / congés (UTC) ----------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function timeOffFor(int $technicianId): array
    {
        return $this->db->select(
            'SELECT * FROM technician_time_off WHERE technician_id = :t ORDER BY starts_at DESC',
            ['t' => $technicianId],
        );
    }

    /**
     * Les entrées sont saisies en heure belge et converties en UTC pour le stockage.
     */
    public function addTimeOff(int $technicianId, string $startLocal, string $endLocal, string $reason): void
    {
        $this->db->insert('technician_time_off', [
            'technician_id' => $technicianId,
            'starts_at' => Clock::fromDisplay($startLocal)->format('Y-m-d H:i:s'),
            'ends_at' => Clock::fromDisplay($endLocal)->format('Y-m-d H:i:s'),
            'reason' => $reason !== '' ? $reason : null,
        ]);
    }

    public function deleteTimeOff(int $id, int $technicianId): void
    {
        $this->db->run(
            'DELETE FROM technician_time_off WHERE id = :id AND technician_id = :t',
            ['id' => $id, 't' => $technicianId],
        );
    }

    // --- Zones de service (chalandise) -----------------------------------------

    /**
     * @return list<int>
     */
    public function zoneIdsFor(int $technicianId): array
    {
        $rows = $this->db->select(
            'SELECT zone_id FROM technician_zones WHERE technician_id = :t',
            ['t' => $technicianId],
        );

        return array_map(static fn (array $r): int => (int) $r['zone_id'], $rows);
    }

    /**
     * Remplace l'ensemble des zones couvertes par un technicien (même table
     * technician_zones que ZoneRepository::syncTechnicians, sens inverse).
     *
     * @param list<int> $zoneIds
     */
    public function syncZones(int $technicianId, array $zoneIds): void
    {
        $this->db->transaction(function (Database $db) use ($technicianId, $zoneIds): void {
            $db->run('DELETE FROM technician_zones WHERE technician_id = :t', ['t' => $technicianId]);
            foreach (array_unique($zoneIds) as $zoneId) {
                $db->run(
                    'INSERT INTO technician_zones (technician_id, zone_id) VALUES (:t, :z)',
                    ['t' => $technicianId, 'z' => (int) $zoneId],
                );
            }
        });
    }

    /**
     * Zones actives (pour la checklist « zones couvertes » de la fiche technicien).
     *
     * @return list<array<string, mixed>>
     */
    public function activeZones(): array
    {
        return $this->db->select(
            'SELECT id, name FROM service_zones WHERE is_active = 1 ORDER BY priority, name',
        );
    }

    // --- Ressources annexes ----------------------------------------------------

    /**
     * Ateliers actifs (pour rattacher une disponibilité à un atelier).
     *
     * @return list<array<string, mixed>>
     */
    public function activeLocations(): array
    {
        return $this->db->select(
            'SELECT id, name FROM locations WHERE is_active = 1 ORDER BY sort_order, name',
        );
    }

    /**
     * Comptes de connexion « technician » rattachables (pour l'app terrain).
     *
     * @return list<array<string, mixed>>
     */
    public function linkableUsers(): array
    {
        return $this->db->select(
            "SELECT id, first_name, last_name, email FROM users WHERE role = 'technician' ORDER BY last_name, first_name",
        );
    }
}
