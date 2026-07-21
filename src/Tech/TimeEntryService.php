<?php

declare(strict_types=1);

namespace Keepnew\Tech;

use Keepnew\Core\Database;
use Keepnew\Support\Clock;

/**
 * Pointage CP 121 (travailleurs itinérants) et indemnité de mobilité.
 *
 * Les pointages sont IMMUABLES : on n'UPDATE jamais une ligne time_entries ;
 * une correction crée une nouvelle ligne (is_corrected + corrects_id) et une
 * entrée d'audit. L'indemnité de mobilité est calculée selon un barème
 * paramétrable (settings), jamais en dur.
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

    /**
     * Crée l'indemnité de mobilité d'un job (à sa complétion), selon le barème.
     *
     * Distance estimée depuis le trajet enregistré (travel_in_min) à vitesse
     * moyenne ; barème cents/km lu dans settings. Idempotent par job.
     */
    public function createMobilityForJob(int $jobId): void
    {
        $job = $this->db->selectOne('SELECT * FROM jobs WHERE id = :id', ['id' => $jobId]);
        if ($job === null || $job['technician_id'] === null || $job['mode'] !== 'onsite') {
            return;
        }
        $exists = (int) $this->db->scalar('SELECT COUNT(*) FROM mobility_allowances WHERE job_id = :j', ['j' => $jobId]);
        if ($exists > 0) {
            return;
        }

        $ratePerKm = (int) ($this->db->scalar("SELECT `value` FROM settings WHERE `key` = 'mobility.rate_cents_per_km'") ?? 0);
        $travelMin = (int) ($job['travel_in_min'] ?? 0);
        // Estimation km : trajet (min) à 45 km/h.
        $distanceKm = round($travelMin / 60 * 45, 2);
        $amount = (int) round($distanceKm * $ratePerKm);

        $workDate = $job['scheduled_start'] !== null
            ? Clock::format(new \DateTimeImmutable((string) $job['scheduled_start'], new \DateTimeZone('UTC')), 'Y-m-d')
            : Clock::format(Clock::nowUtc(), 'Y-m-d');

        $this->db->insert('mobility_allowances', [
            'technician_id' => (int) $job['technician_id'],
            'job_id' => $jobId,
            'work_date' => $workDate,
            'distance_km' => $distanceKm,
            'rate_cents_per_km' => $ratePerKm,
            'amount_cents' => $amount,
        ]);
    }

    /**
     * Export mensuel CSV des indemnités de mobilité (secrétariat social).
     */
    public function mobilityCsv(string $month): string
    {
        $rows = $this->db->select(
            "SELECT t.first_name, t.last_name, m.work_date, m.distance_km, m.rate_cents_per_km, m.amount_cents
             FROM mobility_allowances m JOIN technicians t ON t.id = m.technician_id
             WHERE DATE_FORMAT(m.work_date, '%Y-%m') = :m
             ORDER BY t.last_name, m.work_date",
            ['m' => $month],
        );
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Technicien', 'Date', 'Km', 'Barème (€/km)', 'Indemnité (€)'], ';');
        $total = 0;
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['first_name'] . ' ' . $r['last_name'],
                $r['work_date'],
                number_format((float) $r['distance_km'], 2, ',', ''),
                number_format(((int) $r['rate_cents_per_km']) / 100, 2, ',', ''),
                number_format(((int) $r['amount_cents']) / 100, 2, ',', ''),
            ], ';');
            $total += (int) $r['amount_cents'];
        }
        fputcsv($out, ['', '', '', 'TOTAL', number_format($total / 100, 2, ',', '')], ';');
        rewind($out);

        return (string) stream_get_contents($out);
    }
}
