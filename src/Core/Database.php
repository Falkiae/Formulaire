<?php

declare(strict_types=1);

namespace Keepnew\Core;

/**
 * Fine surcouche PDO — requêtes PRÉPARÉES exclusivement.
 *
 * Interdit implicite : aucune méthode n'accepte de SQL concaténé avec des
 * données utilisateur. On passe toujours par des marqueurs nommés (:x) et un
 * tableau de paramètres. C'est le seul point d'accès à la base du projet.
 */
final class Database
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * Construit l'instance depuis la configuration (DSN MySQL, utf8mb4).
     */
    public static function fromConfig(Config $config): self
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            (string) $config->get('db.host', '127.0.0.1'),
            (string) $config->get('db.port', '3306'),
            (string) $config->get('db.name', ''),
            (string) $config->get('db.charset', 'utf8mb4'),
        );

        $pdo = new \PDO(
            $dsn,
            (string) $config->get('db.user', ''),
            (string) $config->get('db.password', ''),
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false, // vraies requêtes préparées
                \PDO::ATTR_STRINGIFY_FETCHES => false,
            ],
        );

        return new self($pdo);
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * Exécute une requête préparée et renvoie le statement.
     *
     * @param array<string, mixed> $params
     */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * Renvoie toutes les lignes.
     *
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * Renvoie la première ligne ou null.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Renvoie une valeur scalaire (première colonne de la première ligne).
     *
     * @param array<string, mixed> $params
     */
    public function scalar(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * Insère une ligne à partir d'un tableau colonne => valeur ; renvoie l'ID.
     *
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            '`' . implode('`, `', $columns) . '`',
            implode(', ', $placeholders),
        );

        $this->run($sql, $data);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Exécute un bloc dans une transaction ; rollback automatique en cas d'erreur.
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
