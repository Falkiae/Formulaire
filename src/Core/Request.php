<?php

declare(strict_types=1);

namespace Keepnew\Core;

/**
 * Représentation immuable d'une requête HTTP entrante.
 *
 * SÉCURITÉ — point central du projet : les superglobales $_GET / $_POST ne
 * sont JAMAIS lues ailleurs que dans self::fromGlobals(). Le reste du code
 * passe par des accesseurs TYPÉS (string(), int(), bool(), array()) qui
 * filtrent et convertissent. C'est ce qui garantit « aucun $_GET/$_POST non
 * filtré » exigé par le cahier des charges.
 */
final class Request
{
    /**
     * @param array<string, mixed> $query      Paramètres d'URL (?a=b)
     * @param array<string, mixed> $body        Corps de formulaire (POST)
     * @param array<string, mixed> $attributes  Paramètres de route ({id}) + valeurs injectées
     * @param array<string, string> $headers    En-têtes normalisés en minuscules
     * @param array<string, mixed> $server       Sous-ensemble utile de $_SERVER
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private array $query = [],
        private array $body = [],
        private array $attributes = [],
        private array $headers = [],
        private array $server = [],
        private readonly ?string $rawBody = null,
        private array $files = [],
    ) {
    }

    /**
     * Construit la requête à partir des superglobales (seul endroit autorisé).
     */
    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = rtrim(parse_url($uri, PHP_URL_PATH) ?: '/', '/');
        $path = $path === '' ? '/' : $path;

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $raw = file_get_contents('php://input') ?: '';
        $body = $_POST;

        // Corps JSON (widget / API) : on le fusionne dans $body après décodage.
        if (str_contains($headers['content-type'] ?? '', 'application/json') && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        return new self(
            method: $method,
            path: $path,
            query: $_GET,
            body: $body,
            attributes: [],
            headers: $headers,
            server: [
                'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? null,
                'HTTPS' => $_SERVER['HTTPS'] ?? null,
            ],
            rawBody: $raw,
            files: $_FILES,
        );
    }

    /**
     * Métadonnées d'un fichier uploadé (seul accès à $_FILES, centralisé).
     *
     * @return array{name:string, type:string, tmp_name:string, error:int, size:int}|null
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return $file;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isJson(): bool
    {
        return str_contains($this->headers['content-type'] ?? '', 'application/json');
    }

    // --- Accès brut interne (utilisé par les accesseurs typés uniquement) ------

    /**
     * Cherche une clé dans le corps puis dans la query. Renvoie la valeur brute.
     */
    private function raw(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    // --- Accesseurs typés & filtrés (à utiliser partout ailleurs) --------------

    /**
     * Chaîne nettoyée (trim + suppression des octets de contrôle).
     */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->raw($key, $default);
        if (!is_scalar($value)) {
            return $default;
        }
        $value = (string) $value;
        // Retire les caractères de contrôle (hors tab/newline) — anti-injection basique.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

        return trim($value);
    }

    /**
     * Entier strict (les valeurs non numériques renvoient le défaut).
     */
    public function int(string $key, int $default = 0): int
    {
        $value = $this->raw($key);
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    /**
     * Booléen tolérant : "1", "true", "on", "yes" → true.
     */
    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->raw($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Tableau (utile pour les choix multiples : extras, options).
     *
     * @return array<int|string, mixed>
     */
    public function array(string $key, array $default = []): array
    {
        $value = $this->raw($key, $default);

        return is_array($value) ? $value : $default;
    }

    /**
     * Indique la présence d'une clé (corps ou query).
     */
    public function has(string $key): bool
    {
        return $this->raw($key) !== null;
    }

    /**
     * Corps décodé complet (JSON déjà fusionné). À valider avant usage.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    // --- Paramètres de route & en-têtes ---------------------------------------

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Renvoie une COPIE avec des attributs ajoutés (immuabilité préservée).
     *
     * @param array<string, mixed> $attributes
     */
    public function withAttributes(array $attributes): self
    {
        $clone = clone $this;
        $clone->attributes = array_merge($this->attributes, $attributes);

        return $clone;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * Jeton Bearer d'un en-tête Authorization, s'il existe.
     */
    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization', '');
        if ($auth !== null && preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Adresse IP du client (telle que vue par le serveur).
     */
    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function isSecure(): bool
    {
        return !empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off';
    }
}
