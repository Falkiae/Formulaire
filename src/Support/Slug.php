<?php

declare(strict_types=1);

namespace Keepnew\Support;

/**
 * Génération de slugs SEO (URL lisibles) à partir d'un libellé.
 *
 * Translittère les accents, met en minuscules, remplace tout ce qui n'est pas
 * alphanumérique par un tiret. L'unicité éventuelle est gérée par l'appelant
 * (le repository ajoute un suffixe -2, -3… si le slug existe déjà).
 */
final class Slug
{
    public static function make(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return 'element';
        }

        // Translittération des accents en ASCII si intl est disponible.
        if (class_exists(\Transliterator::class)) {
            $tr = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
            if ($tr !== null) {
                $text = (string) $tr->transliterate($text);
            }
        } else {
            $text = mb_strtolower($text);
        }

        // Tout caractère non [a-z0-9] devient un tiret ; on compacte les tirets.
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        $text = trim($text, '-');

        return $text === '' ? 'element' : $text;
    }
}
