<?php

declare(strict_types=1);

namespace Keepnew\Auth;

use Keepnew\Core\Database;

/**
 * Accès aux comptes back-office et vérification du mot de passe (Argon2id).
 */
final class UserRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByEmail(string $email): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM users WHERE email = :email AND is_active = 1',
            ['email' => $email],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM users WHERE id = :id', ['id' => $id]);
    }

    /**
     * Vérifie l'identité. Renvoie l'utilisateur si le mot de passe correspond.
     *
     * Le hash est réhashé de façon transparente si les paramètres Argon2id ont
     * évolué (password_needs_rehash).
     *
     * @return array<string, mixed>|null
     */
    public function verifyCredentials(string $email, string $password): ?array
    {
        $user = $this->findActiveByEmail($email);
        if ($user === null) {
            // Comparaison factice pour égaliser le temps de réponse (anti-énumération).
            password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$YWJjZGVmZ2hpamtsbW5vcA$0000000000000000000000000000000000000000000');

            return null;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return null;
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_ARGON2ID)) {
            $newHash = password_hash($password, PASSWORD_ARGON2ID);
            $this->db->run(
                'UPDATE users SET password_hash = :h WHERE id = :id',
                ['h' => $newHash, 'id' => (int) $user['id']],
            );
        }

        $this->db->run(
            'UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => (int) $user['id']],
        );

        return $user;
    }
}
