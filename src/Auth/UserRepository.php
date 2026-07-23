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
     * Liste tous les comptes (back-office + techniciens) pour l'administration.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->select(
            'SELECT id, email, first_name, last_name, phone, role, is_active, last_login_at
               FROM users
              ORDER BY is_active DESC, last_name, first_name',
        );
    }

    /**
     * Indique si un e-mail est déjà pris (optionnellement hors d'un compte donné).
     */
    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE email = :email';
        $params = ['email' => $email];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) $this->db->scalar($sql, $params) > 0;
    }

    /**
     * Crée un compte. Le mot de passe est hashé en Argon2id.
     *
     * @param array{email:string,password:string,first_name:string,last_name:string,phone?:?string,role:string,is_active?:bool} $data
     */
    public function create(array $data): int
    {
        return $this->db->insert('users', [
            'email' => $data['email'],
            'password_hash' => password_hash($data['password'], PASSWORD_ARGON2ID),
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'phone' => ($data['phone'] ?? '') !== '' ? $data['phone'] : null,
            'role' => $data['role'],
            'is_active' => ($data['is_active'] ?? true) ? 1 : 0,
        ]);
    }

    /**
     * Met à jour l'identité et le rôle d'un compte (hors mot de passe).
     *
     * @param array{email:string,first_name:string,last_name:string,phone?:?string,role:string,is_active?:bool} $data
     */
    public function update(int $id, array $data): void
    {
        $this->db->run(
            'UPDATE users SET email = :email, first_name = :first_name, last_name = :last_name,
                    phone = :phone, role = :role, is_active = :is_active
              WHERE id = :id',
            [
                'email' => $data['email'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => ($data['phone'] ?? '') !== '' ? $data['phone'] : null,
                'role' => $data['role'],
                'is_active' => ($data['is_active'] ?? true) ? 1 : 0,
                'id' => $id,
            ],
        );
    }

    /**
     * Active ou désactive un compte (un compte inactif ne peut plus se connecter).
     */
    public function setActive(int $id, bool $active): void
    {
        $this->db->run(
            'UPDATE users SET is_active = :active WHERE id = :id',
            ['active' => $active ? 1 : 0, 'id' => $id],
        );
    }

    /**
     * Remplace le mot de passe (hashé Argon2id).
     */
    public function updatePassword(int $id, string $plainPassword): void
    {
        $this->db->run(
            'UPDATE users SET password_hash = :h WHERE id = :id',
            ['h' => password_hash($plainPassword, PASSWORD_ARGON2ID), 'id' => $id],
        );
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
