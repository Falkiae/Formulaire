# Keepnew Booking — Phase 5 : API REST, panier & logique de commande

Rend tout le back-end consommable par le widget (Phase 6) : catalogue, panier
persistant, disponibilité, création de commande avec découpage en jobs, et
gestion tokenisée sans compte.

## Services métier (`src/Booking/`)

- **`CartService`** — panier persistant (`carts`) à token anonyme. Prix/durées
  FIGÉS à l'ajout (snapshot), mais devis TOUJOURS recalculé par
  `CartPricingService` (source de vérité) → tout écart est détectable à la
  soumission.
- **`HoldService`** — réservation temporaire (10 min) et vérifications de
  conflit sous **verrou pessimiste** (`SELECT … FOR UPDATE`) contre les jobs
  planifiés et les holds actifs. Purge par cron.
- **`BookingService`** — transforme un panier en commande :
  - recalcul autoritatif à la soumission ;
  - **mode unique par commande** (domicile **ou** atelier, jamais les deux) :
    le tunnel ne fait choisir qu'un seul créneau, un panier mixte produirait un
    second rendez-vous jamais planifié. La règle est tenue par
    `CartService::addItem()` (refus dès l'ajout, 422) et re-vérifiée à la
    soumission par `BookingService::assertSingleMode()` pour les paniers
    constitués avant son entrée en vigueur. Une commande donne donc **un job**,
    quel que soit le nombre de prestations ;
  - vérification des créneaux sous verrou, création client/adresse/commande/
    lignes/jobs, réponses au formulaire, historique de statut ;
  - conversion du panier et libération des holds ;
  - référence séquentielle `KN-AAAA-NNNNNN`, `manage_token` signé.
  - **Prestation sans date** : `createDateless()` crée des jobs `unscheduled` ;
    `schedule()` laisse le client choisir son créneau via le lien de gestion.
  - Annulation via token (`cancel()`), sans compte.

## API REST (`/api/*`, `src/Http/Api/`)

Cross-origin par nature : pas de CSRF cookie (le panier est authentifié par son
token) ; endpoints sensibles protégés par **rate limiting**.

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/api/catalog` | Catégories + prestations actives |
| GET | `/api/services/{id}` | Modes, variantes, extras |
| POST | `/api/cart` | Crée un panier → token |
| GET | `/api/cart/{token}` | Instantané (lignes + devis recalculé) |
| POST | `/api/cart/{token}/items` | Ajoute une prestation |
| PATCH | `/api/cart/{token}/items/{id}` | Modifie la quantité |
| DELETE | `/api/cart/{token}/items/{id}` | Retire une ligne |
| POST | `/api/cart/{token}/coupon` | Applique un coupon |
| POST | `/api/availability` | Créneaux domicile/atelier (rate-limité) |
| POST | `/api/bookings` | Confirme la commande (rate-limité, honeypot) |
| GET | `/api/bookings/{token}` | Consultation (token de gestion) |
| POST | `/api/bookings/{token}/schedule` | Choix de créneau (prestation sans date) |
| POST | `/api/bookings/{token}/cancel` | Annulation |

Sécurité : honeypot anti-spam et consentement CGV obligatoire à la création ;
rate limiting sur disponibilité (40/min) et réservation (15/min).

## Validation réelle (MariaDB 10.11 + PHP 8.4)

Flux complet exercé via HTTP :

1. Catalogue → panier → 2 canapés domicile : devis 148 € → remise cumul −6,90 €
   → **TVAC 170,73 €** (cohérent avec le moteur de prix).
2. Disponibilité (Ans 4432) → 60 créneaux → choix du premier.
3. Réservation → `KN-2026-000001`, `status=confirmed`,
   `payment_status=not_required`, totaux figés, **2 lignes reliées à 1 job**,
   réponses d'intake stockées, panier `converted`.
4. **Double-réservation** du même créneau/technicien → **HTTP 409** (verrou
   pessimiste effectif).
5. Consultation puis **annulation** via token → `cancelled`.
6. **Prestation sans date** : `createDateless` → job `unscheduled` → le client
   consulte, choisit un créneau via `/schedule` → `confirmed`.

Test d'intégration : `tests/Booking/BookingFlowTest.php` (ignoré proprement si
`KN_TEST_DSN` n'est pas défini).

## Ce que la Phase 5 laisse aux phases suivantes

Le rendu du tunnel et du widget (Phase 6) consomme cette API. La pose explicite
du hold pendant le tunnel, le tracking GA4/Meta CAPI, et l'optimisation
« créneaux verts » s'y branchent. Les notifications (confirmation, rappels) sont
Phase 9.
