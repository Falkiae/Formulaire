<?php

declare(strict_types=1);

namespace Keepnew\Tech;

use Keepnew\Core\Database;
use Keepnew\Support\Clock;

/**
 * Pointage CP 121 (travailleurs itinérants).
 *
 * Les pointages sont IMMUABLES : on n'UPDATE jamais une ligne time_entries ;
 * une correction crée une nouvelle ligne (is_corrected + corrects_id) et une
 * entrée d'audit.
 *
 * Le calcul des indemnités de mobilité a été retiré de l'application : il est
 * assuré ailleurs. Les heures pointées restent, elles, la source de vérité.
 */
final class TimeEntryService
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Enregistre un pointage (start / stop / pause / resume) — insertion seule.
     */
    public function punch(int $technicianId, int $jobId, string $type, ?float $lat, ?float $lng): int
    {
        return $this->db->insert('time_entries', [
            'technician_id' => $technicianId,
            'job_id' => $jobId,
            'entry_type' => $type,
            'occurred_at' => Clock::nowUtc()->format('Y-m-d H:i:s'),
            'lat' => $lat,
            'lng' => $lng,
        ]);
    }

    /**
     * Corrige un pointage : nouvelle ligne + trace d'audit (jamais de modif).
     */
    public function correct(int $originalId, int $technicianId, int $jobId, string $type, string $occurredAtUtc, ?int $userId): int
    {
        return $this->db->transaction(function (Database $db) use ($originalId, $technicianId, $jobId, $type, $occurredAtUtc, $userId): int {
            $id = $db->insert('time_entries', [
                'technician_id' => $technicianId,
                'job_id' => $jobId,
                'entry_type' => $type,
                'occurred_at' => $occurredAtUtc,
                'is_corrected' => 1,
                'corrects_id' => $originalId,
            ]);
            $db->insert('audit_log', [
                'user_id' => $userId,
                'action' => 'time_entry.correct',
                'entity_type' => 'time_entry',
                'entity_id' => $id,
                'old_values' => json_encode(['corrects_id' => $originalId], JSON_UNESCAPED_UNICODE),
            ]);

            return $id;
        });
    }

    /**
     * Dernier état de pointage d'un job (pour l'UI : afficher start ou stop).
     */
    public function lastType(int $jobId): ?string
    {
        $row = $this->db->selectOne(
            'SELECT entry_type FROM time_entries WHERE job_id = :j ORDER BY id DESC LIMIT 1',
            ['j' => $jobId],
        );

        return $row['entry_type'] ?? null;
    }
}
