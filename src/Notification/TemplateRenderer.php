<?php

declare(strict_types=1);

namespace Keepnew\Notification;

/**
 * Rendu de templates à variables `{{chemin.pointé}}`.
 *
 * Pur : remplace chaque `{{a.b.c}}` par la valeur correspondante du contexte
 * (tableau imbriqué). Une variable absente est remplacée par une chaîne vide.
 *
 * Pour l'email (HTML), l'échappement des valeurs est activé afin d'éviter toute
 * injection via une donnée client. Pour le SMS (texte), il est désactivé.
 */
final class TemplateRenderer
{
    /**
     * @param array<string, mixed> $context
     */
    public function render(string $template, array $context, bool $escape = false): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            function (array $m) use ($context, $escape): string {
                $value = $this->lookup($context, $m[1]);
                $value = is_scalar($value) ? (string) $value : '';

                return $escape ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
            },
            $template,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function lookup(array $context, string $path): mixed
    {
        $value = $context;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
