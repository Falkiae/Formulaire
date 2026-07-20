<?php

declare(strict_types=1);

namespace Keepnew\Core;

/**
 * Conteneur de services minimal (injection de dépendances).
 *
 * Trois façons d'enregistrer un service :
 *   - bind()      : fabrique appelée à CHAQUE résolution (nouvelle instance)
 *   - singleton() : fabrique appelée UNE fois, puis instance mémorisée
 *   - instance()  : objet déjà construit
 *
 * Pas d'auto-wiring par réflexion : on reste explicite et lisible, ce qui
 * suffit largement au périmètre du projet et évite toute « magie ».
 */
final class Container
{
    /** @var array<string, callable> */
    private array $factories = [];

    /** @var array<string, bool> */
    private array $shared = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /**
     * Enregistre une fabrique produisant une nouvelle instance à chaque appel.
     */
    public function bind(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        $this->shared[$id] = false;
        unset($this->instances[$id]);
    }

    /**
     * Enregistre une fabrique partagée (résolue une seule fois).
     */
    public function singleton(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        $this->shared[$id] = true;
        unset($this->instances[$id]);
    }

    /**
     * Enregistre un objet déjà construit.
     */
    public function instance(string $id, mixed $object): void
    {
        $this->instances[$id] = $object;
        $this->shared[$id] = true;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    /**
     * Résout un service par son identifiant.
     *
     * @throws \RuntimeException si l'identifiant est inconnu.
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new \RuntimeException("Service non enregistré dans le conteneur : {$id}");
        }

        $object = ($this->factories[$id])($this);

        if ($this->shared[$id] ?? false) {
            $this->instances[$id] = $object;
        }

        return $object;
    }
}
