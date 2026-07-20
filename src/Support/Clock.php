<?php

declare(strict_types=1);

namespace Keepnew\Support;

/**
 * Gestion du temps du projet.
 *
 * Convention stricte : on STOCKE en UTC, on AFFICHE en Europe/Brussels.
 * Toute la logique de disponibilité raisonne en UTC pour être robuste au
 * changement d'heure d'été (DST). La conversion n'intervient qu'à l'affichage.
 */
final class Clock
{
    public const STORAGE_TZ = 'UTC';
    public const DISPLAY_TZ = 'Europe/Brussels';

    /**
     * Instant présent en UTC (immuable).
     */
    public static function nowUtc(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::STORAGE_TZ));
    }

    /**
     * Convertit un instant (quel que soit son fuseau) vers l'affichage Bruxelles.
     */
    public static function toDisplay(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        return $instant->setTimezone(new \DateTimeZone(self::DISPLAY_TZ));
    }

    /**
     * Convertit un instant vers UTC (pour stockage).
     */
    public static function toUtc(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        return $instant->setTimezone(new \DateTimeZone(self::STORAGE_TZ));
    }

    /**
     * Parse une date « locale Bruxelles » saisie par un humain vers un instant UTC.
     * Ex. "2026-07-21 09:00" (heure belge) → l'instant UTC correspondant.
     */
    public static function fromDisplay(string $localDateTime): \DateTimeImmutable
    {
        $local = new \DateTimeImmutable($localDateTime, new \DateTimeZone(self::DISPLAY_TZ));

        return self::toUtc($local);
    }

    /**
     * Formate un instant UTC pour l'affichage belge.
     * Ex. format(instant, 'd/m/Y H:i') → "21/07/2026 11:00".
     */
    public static function format(\DateTimeImmutable $utcInstant, string $format = 'd/m/Y H:i'): string
    {
        return self::toDisplay($utcInstant)->format($format);
    }
}
