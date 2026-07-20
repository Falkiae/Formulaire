<?php

declare(strict_types=1);

namespace Keepnew\Core;

/**
 * Moteur de vues minimal : templates PHP natifs.
 *
 * Choix assumé : pas de moteur de templates tiers. Les vues back-office sont
 * du PHP + Alpine.js, sans étape de build.
 *
 * IMPORTANT — sécurité : on N'UTILISE PAS extract() (interdit par le cahier des
 * charges et vecteur d'écrasement de variables). Les données sont exposées au
 * template via la variable $data et l'échappement se fait avec la fonction
 * locale $e(). Ex. dans un template :
 *
 *     <h1><?= $e($data['title']) ?></h1>
 */
final class View
{
    public function __construct(private readonly string $viewsPath)
    {
    }

    /**
     * Rend un template en une réponse HTML.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->capture($template, $data), $status);
    }

    /**
     * Rend un template et renvoie la chaîne (utile pour l'imbrication de vues).
     *
     * @param array<string, mixed> $data
     */
    public function capture(string $template, array $data = []): string
    {
        $file = $this->viewsPath . '/' . ltrim($template, '/') . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Template introuvable : {$template}");
        }

        // Fonction d'échappement mise à disposition du template.
        $e = static fn (mixed $value): string => htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );

        ob_start();
        // $data et $e sont les seules variables visibles dans le template.
        (static function (string $__file, array $data, callable $e): void {
            require $__file;
        })($file, $data, $e);

        return (string) ob_get_clean();
    }
}
