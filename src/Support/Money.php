<?php

declare(strict_types=1);

namespace Keepnew\Support;

/**
 * Manipulation des montants — TOUJOURS en centimes d'euro (entiers).
 *
 * Règle d'or du projet : jamais de float pour de l'argent. Les taux (TVA,
 * remises) sont exprimés en POINTS DE BASE (bp) : 2100 = 21,00 %.
 *
 * Cette classe ne stocke rien : ce sont des fonctions pures et arrondies de
 * façon déterministe (arrondi « half up » sur l'entier de centimes).
 */
final class Money
{
    /**
     * Calcule le montant de TVA (en centimes) pour un montant HTVA donné.
     *
     * @param int $amountCents Montant hors TVA, en centimes.
     * @param int $rateBp      Taux en points de base (2100 = 21 %).
     */
    public static function vat(int $amountCents, int $rateBp): int
    {
        return self::roundHalfUp($amountCents * $rateBp, 10000);
    }

    /**
     * Ajoute la TVA à un montant HTVA et renvoie le TVAC (en centimes).
     */
    public static function addVat(int $amountCents, int $rateBp): int
    {
        return $amountCents + self::vat($amountCents, $rateBp);
    }

    /**
     * Applique un pourcentage (en points de base) à un montant.
     * Ex. remise de 10 % sur 11900 → percentOf(11900, 1000) = 1190.
     */
    public static function percentOf(int $amountCents, int $percentBp): int
    {
        return self::roundHalfUp($amountCents * $percentBp, 10000);
    }

    /**
     * Formate un montant en centimes pour l'affichage (locale fr-BE).
     * Ex. 14399 → "143,99 €".
     */
    public static function format(int $cents, string $currency = 'EUR'): string
    {
        // ext-intl est requise par le projet ; NumberFormatter gère la locale BE.
        $formatter = new \NumberFormatter('fr_BE', \NumberFormatter::CURRENCY);

        return $formatter->formatCurrency($cents / 100, $currency);
    }

    /**
     * Arrondi « half up » d'une division entière : round(numerator / denominator).
     * Reste en arithmétique entière pour éviter toute imprécision flottante.
     */
    private static function roundHalfUp(int $numerator, int $denominator): int
    {
        $sign = ($numerator < 0) ? -1 : 1;
        $numerator = abs($numerator);

        return $sign * intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
