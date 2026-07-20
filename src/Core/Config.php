<?php

declare(strict_types=1);

namespace Keepnew\Core;

/**
 * Configuration applicative en lecture seule, avec accès « pointé ».
 *
 * Alimentée par config/config.php (qui lit le .env). Ex. :
 *   $config->get('db.host')
 *   $config->get('app.debug', false)
 */
final class Config
{
    /**
     * @param array<string, mixed> $items Tableau de configuration (imbriqué).
     */
    public function __construct(private array $items = [])
    {
    }

    /**
     * Récupère une valeur via une clé pointée (« a.b.c »).
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }
}
