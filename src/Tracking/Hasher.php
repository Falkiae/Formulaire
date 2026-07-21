<?php

declare(strict_types=1);

namespace Keepnew\Tracking;

/**
 * Normalisation + hachage SHA-256 des données personnelles pour les conversions
 * améliorées (Meta CAPI, Google Ads Enhanced Conversions).
 *
 * Règles imposées par les plateformes : minuscules, sans espaces, e.164 pour le
 * téléphone. Pur et testable.
 */
final class Hasher
{
    /**
     * Hache un email : minuscules, trim, SHA-256.
     */
    public static function email(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    /**
     * Hache un téléphone au format E.164 (chiffres uniquement, indicatif inclus).
     * Un numéro belge « 0470 12 34 56 » → « 32470123456 ».
     */
    public static function phone(string $phone, string $defaultCountry = '32'): string
    {
        return hash('sha256', self::normalizePhone($phone, $defaultCountry));
    }

    public static function normalizePhone(string $phone, string $defaultCountry = '32'): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        // 0470… → indicatif pays + numéro sans le 0 initial.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = $defaultCountry . substr($digits, 1);
        }

        return $digits;
    }

    /**
     * Hache une chaîne simple (prénom, nom, ville) : minuscules, trim.
     */
    public static function plain(string $value): string
    {
        return hash('sha256', strtolower(trim($value)));
    }
}
