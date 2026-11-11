<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Support\Clock;

/**
 * Plan de rendez-vous fictifs : quel technicien, quel jour, quelle heure.
 *
 * Classe PURE — aucune base, aucune horloge implicite. Toute la logique qui
 * peut se tromper (chevauchements, heures ouvrées, statuts cohérents avec la
 * date) vit ici et se teste donc partout, y compris là où les tests
 * d'intégration sont ignorés faute de base.
 *
 * Le plan est DÉTERMINISTE à graine donnée : deux exécutions avec les mêmes
 * paramètres produisent exactement le même jeu, ce qui rend un bogue
 * reproductible au lieu d'être « parfois ».
 */
final class DemoPlanner
{
    /** Journée de travail, en heure belge. */
    private const FIRST_HOUR = 8;
    private const LAST_START_HOUR = 16;

    /** Durées possibles d'une intervention (minutes). */
    private const DURATIONS = [60, 90, 120];

    /**
     * @param list<int> $technicianIds
     * @return list<array{start_utc:string, start_local:string, technician_id:int, duration_min:int, status:string}>
     */
    public static function plan(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $technicianIds,
        \DateTimeImmutable $now,
        int $seed = 20261101,
    ): array {
        if ($technicianIds === [] || $from > $to) {
            return [];
        }

        // Générateur à graine : reproductible, et sans toucher au rand() global
        // du processus (qui sert ailleurs, notamment aux jetons).
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937($seed));

        $tz = new \DateTimeZone(Clock::DISPLAY_TZ);
        $day = new \DateTimeImmutable($from->format('Y-m-d') . ' 00:00:00', $tz);
        $last = new \DateTimeImmutable($to->format('Y-m-d') . ' 00:00:00', $tz);

        $out = [];
        while ($day <= $last) {
            $weekday = (int) $day->format('N'); // 1 = lundi … 7 = dimanche
            if ($weekday === 7) {
                $day = $day->modify('+1 day');
                continue;
            }

            foreach ($technicianIds as $techId) {
                // Le samedi tourne à effectif réduit : un technicien sur deux.
                if ($weekday === 6 && $rng->getInt(0, 1) === 0) {
                    continue;
                }

                // Curseur par technicien : garantit l'absence de chevauchement,
                // puisqu'on n'avance jamais en arrière.
                $cursor = (int) (self::FIRST_HOUR * 60);
                $perDay = $rng->getInt(1, $weekday === 6 ? 2 : 4);

                for ($i = 0; $i < $perDay; $i++) {
                    // Décalage aléatoire entre deux interventions : trajet,
                    // pause, remise en route.
                    $cursor += $rng->getInt(0, 3) * 30;
                    if ($cursor > self::LAST_START_HOUR * 60) {
                        break;
                    }
                    $duration = self::DURATIONS[$rng->getInt(0, count(self::DURATIONS) - 1)];

                    $start = $day->modify('+' . $cursor . ' minutes');
                    $out[] = [
                        'start_utc' => Clock::toUtc($start)->format('Y-m-d H:i:s'),
                        'start_local' => $start->format('Y-m-d H:i'),
                        'technician_id' => $techId,
                        'duration_min' => $duration,
                        'status' => self::statusFor($start, $now, $rng),
                    ];

                    $cursor += $duration;
                }
            }

            $day = $day->modify('+1 day');
        }

        return $out;
    }

    /**
     * Statut cohérent avec la date : on ne « planifie » pas une intervention
     * d'il y a trois semaines, et on ne la marque pas « terminée » si elle est
     * dans quinze jours. Un back-office rempli de statuts impossibles ne
     * prouve rien.
     */
    private static function statusFor(
        \DateTimeImmutable $start,
        \DateTimeImmutable $now,
        \Random\Randomizer $rng,
    ): string {
        $startUtc = Clock::toUtc($start);
        $nowUtc = Clock::toUtc($now);

        if ($startUtc < $nowUtc->modify('-2 hours')) {
            // Passé : très majoritairement terminé, quelques ratés.
            return match ($rng->getInt(1, 20)) {
                1 => 'cancelled',
                2 => 'no_show',
                default => 'completed',
            };
        }

        if ($startUtc <= $nowUtc->modify('+4 hours')) {
            // La journée en cours : de quoi voir « en route » et « en cours »
            // dans la vue Jour, qui sont les deux états qu'on ne voit jamais
            // sur un jeu de données figé.
            return $rng->getInt(0, 1) === 0 ? 'en_route' : 'in_progress';
        }

        return $rng->getInt(1, 25) === 1 ? 'cancelled' : 'scheduled';
    }
}
