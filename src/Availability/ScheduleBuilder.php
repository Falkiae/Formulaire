<?php

declare(strict_types=1);

namespace Keepnew\Availability;

use Keepnew\Support\Clock;

/**
 * Construit les fenêtres de disponibilité UTC à partir d'horaires récurrents
 * exprimés en heure locale (Europe/Brussels).
 *
 * Point crucial : la conversion local → UTC passe par DateTimeZone, donc elle
 * est correcte au CHANGEMENT D'HEURE D'ÉTÉ. En hiver, 09:00 Bruxelles = 08:00
 * UTC (UTC+1) ; en été, 09:00 Bruxelles = 07:00 UTC (UTC+2). Le moteur raisonne
 * ensuite exclusivement en UTC.
 */
final class ScheduleBuilder
{
    /**
     * Fenêtre UTC pour une date locale et des heures locales « HH:MM ».
     *
     * @param string $date       Date locale « Y-m-d » (Europe/Brussels)
     * @param string $localStart Heure locale de début « HH:MM »
     * @param string $localEnd   Heure locale de fin « HH:MM »
     */
    public static function windowForDate(string $date, string $localStart, string $localEnd): Interval
    {
        $tz = new \DateTimeZone(Clock::DISPLAY_TZ);
        $start = new \DateTimeImmutable("{$date} {$localStart}", $tz);
        $end = new \DateTimeImmutable("{$date} {$localEnd}", $tz);

        return new Interval(
            $start->setTimezone(new \DateTimeZone('UTC')),
            $end->setTimezone(new \DateTimeZone('UTC')),
        );
    }

    /**
     * Retire une plage (ex. un congé) d'une fenêtre et renvoie les fenêtres
     * restantes. Une plage couvrant toute la fenêtre renvoie [] (journée off).
     *
     * @return list<Interval>
     */
    public static function subtract(Interval $window, Interval $off): array
    {
        // Pas de recoupement : la fenêtre est intacte.
        if (!$window->overlaps($off)) {
            return [$window];
        }

        $result = [];
        if ($off->start > $window->start) {
            $result[] = new Interval($window->start, $off->start);
        }
        if ($off->end < $window->end) {
            $result[] = new Interval($off->end, $window->end);
        }

        return $result;
    }

    /**
     * Retire plusieurs plages d'une fenêtre (congés multiples).
     *
     * @param list<Interval> $offs
     * @return list<Interval>
     */
    public static function subtractAll(Interval $window, array $offs): array
    {
        $windows = [$window];
        foreach ($offs as $off) {
            $next = [];
            foreach ($windows as $w) {
                foreach (self::subtract($w, $off) as $piece) {
                    $next[] = $piece;
                }
            }
            $windows = $next;
        }

        return $windows;
    }

    /**
     * Retire des blocs occupés d'une fenêtre et renvoie les créneaux libres,
     * chacun accompagné de ses voisins (pour le calcul de trajet domicile).
     *
     * Voisin gauche/droite = bloc occupé adjacent (avec son point), ou les bords
     * de la fenêtre (voisin = null → on utilisera le point de départ du technicien).
     *
     * @param list<BusyBlock> $busy
     * @return list<array{gap:Interval, left:?BusyBlock, right:?BusyBlock}>
     */
    public static function freeGaps(Interval $window, array $busy): array
    {
        // Ne garde que les blocs qui recoupent la fenêtre, triés par début.
        $relevant = array_values(array_filter(
            $busy,
            static fn (BusyBlock $b): bool => $b->interval->overlaps($window),
        ));
        usort($relevant, static fn (BusyBlock $a, BusyBlock $b): int => $a->interval->start <=> $b->interval->start);

        $gaps = [];
        $cursor = $window->start;
        $leftNeighbor = null;

        foreach ($relevant as $block) {
            $blockStart = $block->interval->start;
            if ($blockStart > $cursor) {
                $gaps[] = ['gap' => new Interval($cursor, $blockStart), 'left' => $leftNeighbor, 'right' => $block];
            }
            // Avance le curseur après le bloc (les blocs peuvent se chevaucher).
            if ($block->interval->end > $cursor) {
                $cursor = $block->interval->end;
            }
            $leftNeighbor = $block;
        }

        if ($cursor < $window->end) {
            $gaps[] = ['gap' => new Interval($cursor, $window->end), 'left' => $leftNeighbor, 'right' => null];
        }

        return $gaps;
    }
}
