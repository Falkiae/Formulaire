<?php

declare(strict_types=1);

namespace Keepnew\Core;

/**
 * Session sécurisée.
 *
 * Cookie HttpOnly + Secure + SameSite=Lax (exigence du cahier des charges).
 * La session est RÉGÉNÉRÉE au login (login()) pour prévenir la fixation de
 * session — autre exigence explicite.
 *
 * Fournit aussi les messages « flash » (affichés une seule fois) utilisés par
 * le back-office.
 */
final class Session
{
    private bool $started = false;

    public function __construct(private readonly bool $secureCookie = true)
    {
    }

    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;

            return;
        }

        session_set_cookie_params([
            'httponly' => true,
            'secure' => $this->secureCookie,
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        session_name('kn_session');
        session_start();
        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    /**
     * Ouvre une session authentifiée : régénère l'ID puis mémorise l'utilisateur.
     */
    public function login(int $userId): void
    {
        $this->regenerate();
        $this->set('user_id', $userId);
        $this->set('logged_in_at', time());
    }

    public function logout(): void
    {
        $this->start();
        $_SESSION = [];
        session_destroy();
        $this->started = false;
    }

    public function userId(): ?int
    {
        $id = $this->get('user_id');

        return is_int($id) ? $id : null;
    }

    public function isAuthenticated(): bool
    {
        return $this->userId() !== null;
    }

    /**
     * Dépose un message flash (lu et effacé au prochain affichage).
     */
    public function flash(string $key, mixed $value): void
    {
        $this->set('_flash_' . $key, $value);
    }

    public function pullFlash(string $key, mixed $default = null): mixed
    {
        $value = $this->get('_flash_' . $key, $default);
        $this->forget('_flash_' . $key);

        return $value;
    }
}
