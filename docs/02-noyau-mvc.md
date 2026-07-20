# Keepnew Booking — Le noyau micro-MVC maison

Ce document explique le **noyau applicatif** : à quoi sert chaque brique,
comment une requête le traverse, et **comment ajouter une route, un contrôleur
ou un middleware**. C'est la référence à lire avant de toucher au code des
phases suivantes.

Le choix « maison plutôt que Slim » est argumenté dans
[`01-architecture.md`](01-architecture.md). En résumé : périmètre cerné,
dépendances minimales, contrôle total de la sécurité, déploiement sur mutualisé
sans build. Le noyau fait ~environ 400 lignes, il est lisible de bout en bout.

---

## 1. Vue d'ensemble

```
Navigateur / widget
        │  (une requête HTTP)
        ▼
public/index.php              ← front controller : SEUL point d'entrée
        │
config/bootstrap.php          ← amorçage : autoload + config + conteneur + routeur → Kernel
        │
        ▼
   Kernel::handle(Request)
        │  1. Router::match()      → trouve la route, extrait les {paramètres}
        │  2. pile de middlewares  → CSRF, Auth, RateLimit… (modèle « oignon »)
        │  3. contrôleur            → logique, appelle des services (Database…)
        │  4. en-têtes de sécurité  → CSP/HSTS/X-Frame (sauf widget)
        ▼
   Response::send()           ← émet statut + en-têtes + corps
```

**Principe de sécurité central :** seul `public/` est exposé par le serveur web.
`src/`, `config/`, `storage/`, `.env` sont hors racine web (ou bloqués par les
`.htaccess` fournis). Les superglobales `$_GET`/`$_POST` ne sont lues qu'à un
seul endroit (`Request::fromGlobals()`) ; partout ailleurs on passe par des
accesseurs typés et filtrés.

---

## 2. Les briques, une par une

Tout est sous le namespace `Keepnew\` (PSR-4 → `src/`).

### `Core\Request` — la requête entrante (immuable)
Construite une seule fois depuis les superglobales. On ne lit **jamais**
`$_POST` ailleurs. Accesseurs **typés et filtrés** :

| Méthode | Usage |
|---|---|
| `string($key, $default='')` | chaîne nettoyée (trim + octets de contrôle retirés) |
| `int($key, $default=0)` | entier strict |
| `bool($key, $default=false)` | `"1"/"true"/"on"/"yes"` → `true` |
| `array($key, $default=[])` | tableau (choix multiples : extras, options) |
| `attribute($key)` | paramètre de route (`{id}`) |
| `header($name)`, `bearerToken()`, `ip()`, `isJson()`, `isSecure()` | métadonnées |

Le corps JSON (widget/API) est décodé et fusionné automatiquement, donc
`string()`/`int()` fonctionnent que la donnée vienne d'un formulaire ou d'un
appel JSON.

### `Core\Response` — la réponse sortante
Constructeurs nommés : `Response::html()`, `Response::json()`,
`Response::redirect()`, `Response::noContent()`. Immuable via `withHeader()` /
`withStatus()`. `send()` est le point de sortie unique.

### `Core\Router` — le routage
Associe `(méthode, chemin)` à un gestionnaire. Chemins paramétrés :
`/api/services/{id}`. Helpers `get/post/put/patch/delete`, plus `group()` pour
un préfixe + middlewares communs. Un gestionnaire est soit une closure, soit
`[MonController::class, 'methode']` (le contrôleur est résolu par le conteneur).

### `Core\Kernel` — l'orchestrateur
`handle(Request): Response`. Trouve la route, monte la pile de middlewares
autour du contrôleur, exécute, et transforme les exceptions en réponses
propres :

| Exception | Réponse |
|---|---|
| `NotFoundException` | 404 |
| `ValidationException` | 422 + `{ error, fields }` |
| `HttpException` (générique) | son code (401, 419, 429…) |
| toute autre `Throwable` | 500 — **sans trace en production** |

Ajoute enfin les en-têtes de sécurité globaux (voir §5).

### `Core\Container` — injection de dépendances
Minimal, sans réflexion « magique » : `bind()` (nouvelle instance à chaque
fois), `singleton()` (résolu une fois), `instance()` (objet déjà prêt). La base
de données est enregistrée en `singleton` **paresseux** : la connexion PDO n'est
ouverte que si un service la demande réellement.

### `Core\Database` — accès données (PDO préparé exclusivement)
Seul point d'accès à la base. **Aucune** méthode n'accepte de SQL concaténé avec
des données : on passe toujours des marqueurs `:x` + un tableau.

```php
$rows = $db->select('SELECT * FROM services WHERE category_id = :c', ['c' => 1]);
$one  = $db->selectOne('SELECT * FROM services WHERE id = :id', ['id' => 5]);
$id   = $db->insert('customers', ['email' => $email, 'type' => 'b2c']);
$db->transaction(fn(Database $db) => /* ... plusieurs écritures atomiques ... */);
```

### `Core\Session` — session sécurisée
Cookie `HttpOnly` + `Secure` + `SameSite=Lax`. `login()` **régénère l'ID** de
session (anti-fixation, exigence du cahier des charges). Messages flash inclus.

### `Core\Csrf` — protection CSRF
Jeton synchronisé en session, vérifié avec `hash_equals()`. `field()` produit
l'`<input>` caché ; `token()` alimente l'en-tête `X-CSRF-Token` pour l'AJAX.

### `Core\View` — vues PHP natives
Templates PHP + Alpine.js, sans build. **N'utilise pas `extract()`** (interdit) :
les données sont exposées via `$data` et l'échappement via la fonction locale
`$e()`. Dans un template : `<h1><?= $e($data['title']) ?></h1>`.

### `Support\Money`, `Support\Clock`, `Support\Env`
- **Money** — arithmétique en **centimes** entiers ; TVA/remises en **points de
  base** ; `format()` en locale `fr_BE`. Aucun float.
- **Clock** — stockage **UTC**, affichage **Europe/Brussels** ; conversions
  robustes au changement d'heure d'été.
- **Env** — parseur `.env` minimal, sans dépendance.

---

## 3. Comment faire… (recettes)

### Ajouter une route
Dans `config/routes.php` :

```php
$router->get('/api/services/{id}', [ServiceController::class, 'show']);
```

### Écrire un contrôleur
Une méthode reçoit la `Request`, renvoie une `Response`. Elle valide les entrées
via les accesseurs typés, appelle un service, ne parle jamais à `$_POST`.

```php
namespace Keepnew\Http\Controller;

use Keepnew\Core\{Request, Response};

final class ServiceController
{
    public function __construct(private readonly \Keepnew\Core\Database $db) {}

    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $service = $this->db->selectOne('SELECT * FROM services WHERE id = :id', ['id' => $id]);
        if ($service === null) {
            throw new \Keepnew\Core\Exception\NotFoundException('Prestation inconnue.');
        }
        return Response::json($service);
    }
}
```

Enregistrer sa construction (avec ses dépendances) dans le conteneur, au sein de
`config/routes.php` ou `config/bootstrap.php` :

```php
$container->singleton(
    ServiceController::class,
    fn(Container $c) => new ServiceController($c->get(Database::class)),
);
```

### Protéger un groupe de routes (back-office)
```php
$router->group('/admin', [AuthMiddleware::class, CsrfMiddleware::class], function (Router $r) {
    $r->get('/catalogue', [CatalogController::class, 'index']);
    $r->post('/catalogue/service', [CatalogController::class, 'store']);
});
```

### Écrire un middleware
Implémenter `Core\Middleware` : agir avant, appeler `$next($request)`, agir
après — ou court-circuiter en renvoyant sa propre `Response`.

```php
final class ExampleMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        // ... avant ...
        $response = $next($request);
        // ... après ...
        return $response;
    }
}
```

### Signaler une erreur métier
Lever une exception : le Kernel la traduit en réponse HTTP propre.

```php
throw new ValidationException(['email' => 'Adresse email invalide.']);
// → 422 { "error": "...", "fields": { "email": "..." } }
```

---

## 4. Arborescence livrée (noyau)

```
public/
  index.php               front controller
  .htaccess               réécriture → index.php, blocage fichiers sensibles
.htaccess                 (racine) redirige vers public/ si DocumentRoot figé
config/
  bootstrap.php           amorçage (autoload maison OU composer) → Kernel
  config.php              config applicative depuis .env (tableau pur)
  routes.php              déclaration des routes
src/
  Core/
    Request.php  Response.php  Router.php  Kernel.php  Container.php
    Config.php   Database.php  Session.php Csrf.php    View.php  Middleware.php
    Exception/   HttpException, NotFoundException, ValidationException
  Support/
    Money.php    Clock.php     Env.php
  Http/
    Controller/  HealthController.php    (exemple canonique)
    Middleware/  CsrfMiddleware, AuthMiddleware, RateLimitMiddleware
tests/
  Core/KernelTest.php     routage, 404, accesseurs, en-têtes, Money
```

---

## 5. Sécurité intégrée au noyau

- **Un seul point d'entrée** (`public/index.php`) ; code et données hors webroot.
- **Entrées filtrées** : `$_GET`/`$_POST` lus une seule fois ; accesseurs typés
  ailleurs.
- **SQL 100 % préparé** via `Database` ; aucune concaténation possible par l'API.
- **CSRF** sur toutes les requêtes mutantes du back-office (`CsrfMiddleware`).
- **Sessions** `HttpOnly`/`Secure`/`SameSite=Lax`, **régénérées au login**.
- **Rate limiting** par IP adossé à la table `rate_limits` (login, formulaire
  public, endpoint de disponibilité).
- **En-têtes** `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`
  (sauf routes `/widget`, whitelistées pour l'embarquement) et `HSTS` en HTTPS.
- **Pas de fuite en production** : les traces d'exception ne sont montrées que si
  `APP_DEBUG=true`.
- **Pas d'`extract()`, pas d'`eval()`** ; interdits respectés dans tout le noyau.

---

## 6. Démarrer et vérifier en local

```bash
# Serveur de développement (route le tout vers public/index.php)
php -S 127.0.0.1:8080 -t public public/index.php

curl -s http://127.0.0.1:8080/health
# → {"service":"keepnew-booking","status":"ok","time_utc":"...","time_brussels":"..."}

curl -s http://127.0.0.1:8080/health/echo/liege
# → {"echo":"liege"}
```

Tests unitaires (dans un environnement avec Composer) :

```bash
composer install
composer test          # exécute tests/ via PHPUnit
```

Le noyau a été **validé en exécution réelle** (PHP 8.4) : routage statique et
paramétré, 404, accesseurs typés de la requête, en-têtes de sécurité, et
arithmétique monétaire en centimes (TVA 21 %) — 11 assertions au vert.

---

## 7. Réversibilité

Les contrôleurs dépendent de `Core\Request` / `Core\Response`, **pas** du
routeur. Si un jour on souhaitait adopter Slim, on remplacerait le routeur et le
Kernel sans réécrire les contrôleurs ni les services métier. Le choix maison
n'enferme donc pas le projet.
