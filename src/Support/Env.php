<?php

declare(strict_types=1);

namespace Keepnew\Support;

/**
 * Chargement d'un fichier .env (clé=valeur) vers un tableau associatif.
 *
 * Volontairement minimal : pas de dépendance externe. Le fichier .env doit
 * rester HORS webroot. Aucun secret n'est écrit dans les superglobales ;
 * on retourne un tableau que la configuration consomme.
 *
 * Format supporté :
 *   - lignes `CLE=valeur`
 *   - commentaires débutant par `#`
 *   - valeurs entre guillemets simples ou doubles (les guillemets sont retirés)
 *   - lignes vides ignorées
 */
final class Env
{
    /**
     * Lit un fichier .env et renvoie ses paires clé/valeur.
     *
     * @return array<string, string>
     */
    public static function load(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $vars = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            // Ignore les commentaires et les lignes sans séparateur.
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Retire d'éventuels guillemets englobants.
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            if ($key !== '') {
                $vars[$key] = $value;
            }
        }

        return $vars;
    }
}
