<?php

declare(strict_types=1);

namespace Keepnew\Core;

/**
 * Protection CSRF par jeton synchronisé (« synchronizer token »).
 *
 * Le jeton vit en session ; il est injecté dans chaque formulaire back-office
 * (champ caché) ou envoyé via l'en-tête X-CSRF-Token pour les requêtes AJAX.
 * La vérification utilise hash_equals() (comparaison à temps constant).
 *
 * Le widget public embarqué n'utilise PAS ce mécanisme cookie/session mais des
 * jetons signés dédiés (mis en place aux phases API) : il est cross-site par
 * nature et whitelisté séparément.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    /**
     * Jeton courant, généré et mémorisé s'il n'existe pas encore.
     */
    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /**
     * Vérifie un jeton candidat contre celui de la session.
     */
    public function verify(?string $candidate): bool
    {
        $expected = $this->session->get(self::SESSION_KEY);

        return is_string($expected)
            && is_string($candidate)
            && $candidate !== ''
            && hash_equals($expected, $candidate);
    }

    /**
     * Balise <input> cachée prête à insérer dans un formulaire.
     */
    public function field(): string
    {
        return sprintf(
            '<input type="hidden" name="_csrf" value="%s">',
            htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8'),
        );
    }
}
