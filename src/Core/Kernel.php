<?php

declare(strict_types=1);

namespace Keepnew\Core;

use Keepnew\Core\Exception\HttpException;
use Keepnew\Core\Exception\ValidationException;

/**
 * Cœur applicatif : reçoit une Request, produit une Response.
 *
 * Étapes :
 *   1. Recherche de la route (Router) → 404 si aucune.
 *   2. Construction de la « pile oignon » de middlewares autour du contrôleur.
 *   3. Exécution ; les exceptions HTTP/validation deviennent des réponses
 *      propres. En production, aucune trace n'est divulguée.
 *   4. Ajout des en-têtes de sécurité globaux (sauf pour les routes widget,
 *      whitelistées pour l'embarquement en iframe/Shadow DOM).
 */
final class Kernel
{
    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly Config $config,
    ) {
    }

    public function handle(Request $request): Response
    {
        // Préflight CORS pour l'API (widget cross-origin).
        if ($request->method() === 'OPTIONS' && str_starts_with($request->path(), '/api')) {
            return $this->withSecurityHeaders(Response::noContent(), $request);
        }

        try {
            [$request, $handler, $middleware] = $this->router->match($request);
            $response = $this->runPipeline($request, $handler, $middleware);
        } catch (ValidationException $e) {
            $response = Response::json(
                ['error' => $e->getMessage(), 'fields' => $e->errors()],
                $e->statusCode(),
            );
        } catch (HttpException $e) {
            $response = $this->errorResponse($e->statusCode(), $e->getMessage(), $request);
        } catch (\Throwable $e) {
            $response = $this->handleUnexpected($e, $request);
        }

        return $this->withSecurityHeaders($response, $request);
    }

    /**
     * Assemble et exécute la pile de middlewares (modèle oignon).
     *
     * @param list<mixed> $middleware
     */
    private function runPipeline(Request $request, mixed $handler, array $middleware): Response
    {
        $core = fn (Request $req): Response => $this->router->runHandler($handler, $req);

        $pipeline = array_reduce(
            array_reverse($middleware),
            function (callable $next, mixed $mw): callable {
                return function (Request $req) use ($mw, $next): Response {
                    $instance = $this->resolveMiddleware($mw);

                    return $instance->handle($req, $next);
                };
            },
            $core,
        );

        return $pipeline($request);
    }

    private function resolveMiddleware(mixed $mw): Middleware
    {
        if ($mw instanceof Middleware) {
            return $mw;
        }

        if (is_string($mw)) {
            $instance = $this->container->has($mw) ? $this->container->get($mw) : new $mw();
            if ($instance instanceof Middleware) {
                return $instance;
            }
        }

        throw new \RuntimeException('Middleware invalide.');
    }

    private function handleUnexpected(\Throwable $e, Request $request): Response
    {
        // En debug on montre le détail ; en production, message générique.
        if ($this->config->bool('app.debug')) {
            $message = $e->getMessage() . "\n\n" . $e->getTraceAsString();

            return $request->isJson()
                ? Response::json(['error' => $message], 500)
                : Response::html('<pre>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</pre>', 500);
        }

        // Pas de logger d'audit dédié pour l'instant : au minimum, tracer dans le
        // journal d'erreurs PHP du serveur pour ne jamais perdre une exception
        // silencieusement (une 500 muette était auparavant invisible même côté
        // hébergeur).
        error_log(sprintf(
            '[500] %s %s — %s in %s:%d',
            $request->method(),
            $request->path(),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
        ));

        return $this->errorResponse(500, 'Une erreur inattendue est survenue.', $request);
    }

    private function errorResponse(int $status, string $message, Request $request): Response
    {
        if ($request->isJson()) {
            return Response::json(['error' => $message], $status);
        }

        return Response::html(
            '<h1>' . $status . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>',
            $status,
        );
    }

    /**
     * En-têtes de sécurité globaux. Les routes commençant par le préfixe widget
     * (configurable) sont exemptées de X-Frame-Options pour rester embarquables.
     */
    private function withSecurityHeaders(Response $response, Request $request): Response
    {
        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        // L'API est cross-origin (widget embarqué) : en-têtes CORS.
        if (str_starts_with($request->path(), '/api')) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', (string) $this->config->get('app.cors_origin', '*'))
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, DELETE, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'Content-Type')
                ->withHeader('Access-Control-Max-Age', '600');

            return $response; // pas de X-Frame-Options sur l'API
        }

        $widgetPrefix = (string) $this->config->get('app.widget_prefix', '/widget');
        $isWidget = str_starts_with($request->path(), $widgetPrefix);

        if (!$isWidget) {
            $response = $response->withHeader('X-Frame-Options', 'SAMEORIGIN');
        }

        if ($request->isSecure()) {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return $response;
    }
}
