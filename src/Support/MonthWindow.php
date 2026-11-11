<?php

declare(strict_types=1);

namespace Keepnew\Support;

/**
 * Fenêtre d'un mois civil belge, pour le calendrier de l'étape Rendez-vous.
 *
 * Le tunnel interrogeait la disponibilité sur une fenêtre glissante de N jours
 * depuis maintenant : le client ne pouvait pas regarder « mars » depuis
 * novembre. Il navigue désormais de mois en mois, et chaque mois affiché est
 * une requête bornée par cette classe.
 *
 * Deux pièges, d'où le calcul isolé et testé ici : un mois « belge » ne
 * commence pas à minuit UTC (et le décalage change deux fois par an), et le
 * mois courant ne doit jamais proposer des heures déjà passées.
 */
final class MonthWindow
{
    /**
     * Plafond dur, quel que soit l'horizon configuré : une requête forgée ne
     * doit pas faire calculer l'agenda sur dix ans.
     */
    public const MAX_MONTHS_AHEAD = 12;

    /** Horizon par défaut, aligné sur `availability.horizon_days`. */
    public const DEFAULT_HORIZON_DAYS = 90;

    /** Mois en cours à Bruxelles, au format AAAA-MM. */
    public static function firstMonth(\DateTimeImmutable $now): string
    {
        return Clock::format($now, 'Y-m');
    }

    /**
     * Dernier mois consultable, au format AAAA-MM.
     *
     * Il suit l'horizon de réservation réellement configuré
     * (`availability.horizon_days`), et non une constante : le calendrier du
     * tunnel s'arrête ainsi exactement là où le moteur cesse de proposer des
     * créneaux. Auparavant il laissait avancer sur douze mois alors que le
     * moteur s'arrêtait à quatre-vingt-dix jours — le client tournait les
     * pages sur des mois structurellement vides.
     */
    public static function lastMonth(\DateTimeImmutable $now, int $horizonDays = self::DEFAULT_HORIZON_DAYS): string
    {
        $local = Clock::toDisplay($now);
        $horizon = $local->modify('+' . max(1, $horizonDays) . ' days')->format('Y-m');
        $plafond = $local->modify('first day of this month')
            ->modify('+' . self::MAX_MONTHS_AHEAD . ' months')
            ->format('Y-m');

        return min($horizon, $plafond);
    }

    public static function isValidKey(string $month): bool
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1;
    }

    /**
     * Ramène un mois dans la période consultable plutôt que de le refuser : le
     * client peut être dans un autre fuseau que Bruxelles et demander, en toute
     * bonne foi, le mois d'à côté. C'est le mois retourné qui fait foi, et
     * l'interface affiche celui-là.
     */
    public static function clamp(string $month, \DateTimeImmutable $now, int $horizonDays = self::DEFAULT_HORIZON_DAYS): string
    {
        return max(self::firstMonth($now), min(self::lastMonth($now, $horizonDays), $month));
    }

    /**
     * Bornes UTC à interroger pour ce mois, et la fenêtre à renvoyer au client.
     *
     * @return array{from: \DateTimeImmutable, to: \DateTimeImmutable, window: array<string, string>}
     */
    public static function resolve(string $month, \DateTimeImmutable $now, int $horizonDays = self::DEFAULT_HORIZON_DAYS): array
    {
        $month = self::clamp($month, $now, $horizonDays);

        // Bornes locales : un client qui regarde « novembre » attend le 1er au
        // 30 chez lui, pas une fenêtre UTC décalée d'une heure ou deux.
        $start = Clock::fromDisplay($month . '-01 00:00:00');
        $end = Clock::fromDisplay(
            (new \DateTimeImmutable($month . '-01'))->modify('last day of this month')->format('Y-m-d') . ' 23:59:59'
        );

        return [
            // Dans le mois courant, on ne propose jamais les heures passées.
            'from' => $start < $now ? $now : $start,
            'to' => $end,
            'window' => [
                'month' => $month,
                'first_month' => self::firstMonth($now),
                'last_month' => self::lastMonth($now, $horizonDays),
            ],
        ];
    }
}
