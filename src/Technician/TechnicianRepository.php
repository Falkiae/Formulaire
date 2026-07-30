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
     * Fiche rattachée à un compte de connexion — c'est ce lien qui conditionne
     * l'accès à l'app terrain (`/tech`). Sans lui, le technicien se connecte
     * mais n'a aucun planning à afficher.
     *
     * @return array<string, mixed>|null
     */
    public function findByUserId(int $userId): ?array
    {
        return $this->db->selectOne('SELECT * FROM technicians WHERE user_id = :u', ['u' => $userId]);
    }

    public function existsForUser(int $userId): bool
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM technicians WHERE user_id = :u', ['u' => $userId]) > 0;
    }

    /**
     * Fiches sans compte de connexion — candidates au rattachement depuis la
     * fiche utilisateur.
     *
     * @return list<array<string, mixed>>
     */
    public function unlinked(): array
    {
        return $this->db->select(
            'SELECT id, first_name, last_name, is_active
               FROM technicians
              WHERE user_id IS NULL
              ORDER BY is_active DESC, last_name, first_name',
        );
    }

    /**
     * Rattache une fiche à un compte, en garantissant l'unicité du lien : un
     * compte ne peut piloter qu'une seule fiche (TechController::technician()
     * ne lit qu'une ligne, un doublon rendrait le planning imprévisible).
     */
    public function linkUser(int $technicianId, int $userId): void
    {
        $this->db->transaction(function (Database $db) use ($technicianId, $userId): void {
            $this->releaseAccountFromOtherProfiles($technicianId, $userId);
            $db->run(
                'UPDATE technicians SET user_id = :u WHERE id = :id',
                ['u' => $userId, 'id' => $technicianId],
            );
        });
    }

    /**
     * Crée une fiche minimale à partir d'un compte de connexion et la rattache.
     * Compétences, zones et disponibilités restent à compléter — sans elles le
     * technicien voit un planning vide, mais l'app terrain s'ouvre.
     *
     * @param array<string, mixed> $user
     */
    public function createForUser(array $user): int
    {
        return $this->create([
            'user_id' => (int) $user['id'],
            'first_name' => (string) $user['first_name'],
            'last_name' => (string) $user['last_name'],
            'phone' => (string) ($user['phone'] ?? ''),
            'email' => (string) $user['email'],
            'is_active' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $cols = $this->columns($data);
        $id = $this->db->insert('technicians', $cols);
        $this->releaseAccountFromOtherProfiles($id, $cols['user_id']);

        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $cols = $this->columns($data);
        $set = implode(', ', array_map(static fn (string $c): string => "$c = :$c", array_keys($cols)));
        $userId = $cols['user_id'];
        $cols['id'] = $id;
        $this->db->run("UPDATE technicians SET $set WHERE id = :id", $cols);
        $this->releaseAccountFromOtherProfiles($id, $userId);
    }

    /**
     * Un compte ne pilote qu'une fiche : délie les éventuelles autres fiches
     * pointant sur le même compte (TechController::technician() ne lit qu'une
     * ligne — un doublon rendrait le planning affiché imprévisible).
     */
    private function releaseAccountFromOtherProfiles(int $technicianId, ?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        $this->db->run(
            'UPDATE technicians SET user_id = NULL WHERE user_id = :u AND id <> :id',
            ['u' => $userId, 'id' => $technicianId],
        );
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

    /**
     * Supprimable seulement si jamais assigné à un job (passé ou futur) —
     * même critère que CatalogRepository::serviceDeletable() : préserve
     * l'historique plutôt que de délier silencieusement des jobs passés.
     */
    public function technicianDeletable(int $id): bool
    {
        $jobs = (int) $this->db->scalar('SELECT COUNT(*) FROM jobs WHERE technician_id = :id', ['id' => $id]);

        return $jobs === 0;
    }

    /**
     * Supprime si possible (cascade sur skills/zones/disponibilités/congés,
     * déjà ON DELETE CASCADE), sinon désactive pour préserver l'historique.
     * Renvoie l'action réalisée.
     */
    public function deleteOrDeactivateTechnician(int $id): string
    {
        if ($this->technicianDeletable($id)) {
            $this->db->run('DELETE FROM technicians WHERE id = :id', ['id' => $id]);

            return 'deleted';
        }

        $this->db->run('UPDATE technicians SET is_active = 0 WHERE id = :id', ['id' => $id]);

        return 'deactivated';
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

    /**
     * Supprimable seulement si plus utilisée nulle part (aucun technicien,
     * aucune prestation) — pas de colonne is_active sur skills, donc pas de
     * repli « désactiver » possible, blocage sec sinon.
     */
    public function skillDeletable(int $id): bool
    {
        $techs = (int) $this->db->scalar('SELECT COUNT(*) FROM technician_skills WHERE skill_id = :id', ['id' => $id]);
        $services = (int) $this->db->scalar('SELECT COUNT(*) FROM service_skills WHERE skill_id = :id', ['id' => $id]);

        return $techs === 0 && $services === 0;
    }

    public function deleteSkill(int $id): bool
    {
        if (!$this->skillDeletable($id)) {
            return false;
        }
        $this->db->run('DELETE FROM skills WHERE id = :id', ['id' => $id]);

        return true;
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
     * Comptes de connexion « technician » rattachables (pour l'app terrain) :
     * ceux qui ne pilotent encore aucune fiche, plus celui déjà rattaché à la
     * fiche en cours d'édition. Proposer un compte déjà pris volerait
     * silencieusement l'accès terrain de l'autre technicien.
     *
     * @return list<array<string, mixed>>
     */
    public function linkableUsers(?int $currentTechnicianId = null): array
    {
        return $this->db->select(
            "SELECT u.id, u.first_name, u.last_name, u.email
               FROM users u
               LEFT JOIN technicians t ON t.user_id = u.id
              WHERE u.role = 'technician'
                AND (t.id IS NULL OR t.id = :current)
              ORDER BY u.last_name, u.first_name",
            ['current' => $currentTechnicianId ?? 0],
        );
    }
}
