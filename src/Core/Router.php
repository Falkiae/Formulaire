<?php

declare(strict_types=1);

namespace Keepnew\Core;

use Keepnew\Core\Exception\NotFoundException;

/**
 * Routeur maison : associe (méthode HTTP + chemin) à un gestionnaire.
 *
 * - Les chemins acceptent des paramètres nommés : « /api/services/{id} ».
 *   Ils sont extraits et injectés dans la requête (Request::attribute()).
 * - Chaque route peut porter une liste de middlewares (par nom de service
 *   dans le conteneur, ou instance directe).
 * - group() applique un préfixe et des middlewares communs à un lot de routes.
 *
 * Un gestionnaire est soit une Closure, soit [ClasseControleur::class, 'methode'].
 * Le contrôleur est résolu via le conteneur.
 */
final class Router
{
    /**
     * @var list<array{method:string, regex:string, params:list<string>, handler:mixed, middleware:list<mixed>}>
     */
    private array $routes = [];

    /** @var array{prefix:string, middleware:list<mixed>} */
    private array $groupStack = ['prefix' => '', 'middleware' => []];

    public function __construct(private readonly Container $container)
    {
    }

    public function get(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /**
     * Groupe de routes partageant un préfixe et des middlewares.
     *
     * @param list<mixed> $middleware
     */
    public function group(string $prefix, array $middleware, callable $callback): void
    {
        $previous = $this->groupStack;
        $this->groupStack = [
            'prefix' => $previous['prefix'] . $prefix,
            'middleware' => array_merge($previous['middleware'], $middleware),
        ];

        $callback($this);

        $this->groupStack = $previous;
    }

    /**
     * @param list<mixed> $middleware
     */
    public function add(string $method, string $path, mixed $handler, array $middleware = []): void
    {
        $fullPath = $this->groupStack['prefix'] . $path;
        $fullPath = rtrim($fullPath, '/');
        $fullPath = $fullPath === '' ? '/' : $fullPath;

        // Compile « /a/{id}/b » en une regex, en collectant les noms de paramètres.
        $params = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];

                return '([^/]+)';
            },
            $fullPath,
        );

        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => $handler,
            'middleware' => array_merge($this->groupStack['middleware'], $middleware),
        ];
    }

    /**
     * Recherche la route correspondant à la requête et renvoie une requête
     * enrichie des paramètres + le gestionnaire + les middlewares.
     *
     * @return array{0: Request, 1: mixed, 2: list<mixed>}
     * @throws NotFoundException si aucune route ne correspond.
     */
    public function match(Request $request): array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method()) {
                continue;
            }

            if (preg_match($route['regex'], $request->path(), $matches) === 1) {
                array_shift($matches); // retire la correspondance complète
                $attributes = array_combine($route['params'], $matches) ?: [];

                return [
                    $request->withAttributes($attributes),
                    $route['handler'],
                    $route['middleware'],
                ];
            }
        }

        throw new NotFoundException("Aucune route pour {$request->method()} {$request->path()}.");
    }

    /**
     * Exécute un gestionnaire résolu (Closure ou [Classe, méthode]).
     */
    public function runHandler(mixed $handler, Request $request): Response
    {
        if ($handler instanceof \Closure) {
            return $handler($request);
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $controller = $this->container->has($class)
                ? $this->container->get($class)
                : new $class();

            return $controller->{$method}($request);
        }

        throw new \RuntimeException('Gestionnaire de route invalide.');
    }
}
