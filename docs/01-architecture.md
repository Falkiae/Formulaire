# Keepnew Booking — Choix d'architecture (Phase 1)

Ce document justifie les choix structurants du projet. Il accompagne le schéma
SQL (`database/schema.sql`) et le seed (`database/seed.sql`) livrés en Phase 1.

## 1. Framework : micro-MVC maison (front controller + PSR-4)

**Décision : pas de framework lourd, un micro-noyau MVC maison.** Slim 4 était
l'alternative acceptable ; nous ne le retenons pas, pour ces raisons :

| Critère | Micro-MVC maison | Slim 4 |
|---|---|---|
| Déploiement mutualisé (FTP / `git pull`, pas de Node) | ✅ natif | ✅ (via Composer) |
| Surface de dépendances à auditer/sécuriser | Minimale | PSR-7/PSR-15 + middlewares |
| Contrôle total du routage, des sessions, du CSRF | ✅ | Partiel (conventions Slim) |
| Courbe pour un mainteneur unique | Lisible bout en bout | Bonne mais « boîte » |
| Poids / perf sur hébergement modeste | Très léger | Léger |

Le périmètre (un tunnel de réservation + un back-office + une petite API REST)
ne justifie pas une abstraction HTTP complète. Un **front controller** unique
(`public/index.php`), un routeur simple, un conteneur de services minimal et des
contrôleurs POO couvrent le besoin sans dette de framework. On garde toutefois
**PSR-4 + Composer** pour l'autoloading et les rares libs tierces réellement
utiles (PHPMailer, un client HTTP pour les providers Geo/SMS).

> Réversibilité : les contrôleurs ne dépendent pas du routeur maison mais de
> `Request`/`Response` internes ; un passage ultérieur à Slim resterait local.

## 2. Arborescence cible

```
/
├── public/                 # SEUL dossier exposé par le serveur web
│   ├── index.php           # front controller (back-office + API)
│   ├── widget.js           # widget public autonome (Phase 6)
│   └── assets/             # CSS/JS/img buildless
├── src/
│   ├── Core/               # Router, Request, Response, Container, Db (PDO), Csrf, Session
│   ├── Support/            # Money (centimes), Clock (UTC↔Europe/Brussels), Str, Validation
│   ├── Catalog/            # Services, variantes, extras, modes (Phase 2)
│   ├── Pricing/            # Moteur de prix & durée (Phase 3)
│   ├── Availability/       # AvailabilityEngine + branches onsite/workshop (Phase 4)
│   ├── Booking/            # Panier, commande, découpage en jobs (Phase 5)
│   ├── Geo/                # GeoProviderInterface + OpenRouteService, Google, PostalMatrix
│   ├── Payment/            # PaymentGatewayInterface + NullGateway (Mollie/Stripe plus tard)
│   ├── Sms/                # SmsProviderInterface + Twilio, Brevo
│   ├── Notification/       # Moteur d'événements + templates
│   ├── Invoice/            # Factures, TVA, UBL/Peppol (Phase 9)
│   ├── Admin/              # Contrôleurs back-office
│   └── Http/               # Contrôleurs API REST + middlewares (CSRF, RateLimit, Auth)
├── database/
│   ├── schema.sql          # ✅ Phase 1
│   ├── seed.sql            # ✅ Phase 1
│   └── migrations/         # migrations incrémentales ultérieures
├── config/                 # config.php (lit .env), routes.php
├── views/                  # templates back-office (PHP natif + Alpine.js)
├── storage/                # HORS webroot : uploads, logs, cache, PDF/UBL
├── cron/                   # scripts CLI : rappels, purge holds, badges, exports
├── tests/                  # PHPUnit (moteurs prix & disponibilité)
├── docs/
├── .env.example
└── composer.json
```

**Principe de sécurité clé :** seul `public/` est servi. `src/`, `storage/`,
`.env` sont hors webroot (ou protégés par `.htaccess` si l'hébergeur impose une
racine unique). Les uploads sont stockés dans `storage/` et servis par un script
contrôlé, jamais en accès direct.

## 3. Flux d'une requête

```
Navigateur → public/index.php (front controller)
  → chargement config (.env) + ouverture session (régénérée au login)
  → Router : match méthode + chemin → [Middlewares] → Contrôleur
      Middlewares : Auth (back-office) · CSRF (POST) · RateLimit (public)
  → Contrôleur : valide les entrées (jamais $_POST brut), appelle un Service
  → Service métier : logique + accès Db via PDO préparé exclusivement
  → Réponse : HTML (back-office) ou JSON (API/widget)
```

Le **widget public** (Phase 6) parle uniquement à l'**API REST** (`/api/*`) en
JSON ; il n'a pas accès aux vues back-office. Les endpoints publics sensibles
(disponibilité, création de panier) sont rate-limités et protégés anti-spam.

## 4. Décisions de modélisation (schéma)

Les choix ci-dessous sont matérialisés dans `schema.sql` et validés par
chargement réel sur MariaDB 10.11 (61 tables, 81 clés étrangères, 223 index).

- **Argent en centimes (entiers), jamais de float.** Toutes les colonnes
  monétaires sont en `INT`/centimes ; les taux (TVA, remises) en **points de
  base** (`2100` = 21,00 %). Aucune imprécision flottante possible.
- **Dates en UTC** (`DATETIME`), affichage `Europe/Brussels` côté application —
  robuste au changement d'heure d'été (cas de test explicite en Phase 4).
- **Durées en minutes** (entiers).
- **Modes d'exécution = dimension de premier niveau**, pas une option :
  `service_delivery_modes` porte prix **et** durée propres à `onsite`/`workshop`,
  et distingue `active_duration_min` (travail) de `occupancy_duration_min`
  (immobilisation du poste atelier après séchage). C'est ce qui permet au moteur
  atelier de traiter le **poste (bay)** comme ressource limitante.
- **Extras mutualisés** : `extras` (catalogue central) + pivot `service_extras`
  avec surcharge optionnelle de prix/durée, sélection `radio`/`checkbox`, groupe
  exclusif et condition sur variante. Zéro duplication (ex. « désinfection
  ozone » créée une fois, rattachée à 4 services).
- **Réservation à trois niveaux strict** : `bookings` (commande) →
  `booking_items` (lignes) → `jobs` (interventions planifiables). Une commande
  mixte domicile+atelier génère **deux jobs liés** à une seule commande / une
  seule facture. `booking_items.job_id` relie chaque ligne à son job.
- **Prix/durée figés** à l'ajout au panier (`cart_items`, snapshots) puis
  recopiés dans `booking_items` ; le serveur recalcule et revalide à la
  soumission (moteur Phase 3). L'historisation (`price_history`) évite de
  fausser les commandes passées.
- **Anti-double-réservation** : `slot_holds` (blocage 10 min, purgé par cron) en
  complément du verrou pessimiste `SELECT ... FOR UPDATE` au moment de confirmer.
- **Trajet** : `geo_distance_cache` (clé = hash origin/destination/mode, TTL
  30 j) + `postal_travel_matrix` de repli. Indispensable au « contrôle trajet »
  du moteur, sans marteler l'API OpenRouteService.
- **Index posés** conformément aux besoins du moteur et du dispatch :
  `jobs(technician_id, scheduled_start)`, `jobs(status, scheduled_start)`,
  `jobs(bay_id, scheduled_start)`, plus les codes postaux de zone.
- **Conformité belge intégrée dès le schéma** : `time_entries` (CP 121,
  immuables — correction = nouvelle ligne + `audit_log`), `mobility_allowances`
  (barème via `settings`, jamais en dur), `invoices` (numérotation séquentielle,
  facture simplifiée < 250 € TVAC, champs Peppol/UBL), `consents` (RGPD
  horodaté/versionné), `terms_versions` (CGV archivées avec la commande).
- **Paiement prêt mais inactif** : `payments` présente, `bookings.payment_status`
  par défaut `not_required`, passerelle `NullGateway`. Activer Mollie/Stripe =
  configuration, pas refonte.

## 5. Décisions issues des réponses de cadrage

- **Atelier** : `locations` multi-site supporté ; seed = 1 atelier actif
  (Alleur). Le tunnel public **saute l'étape « choix de l'atelier »** tant qu'un
  seul atelier est actif.
- **Techniciens** : 5 au seed, compétences et zones différenciées, un travaillant
  surtout à l'atelier (Sofia). Dispatch multi-colonnes et regroupement
  géographique pertinents dès le départ.
- **Mode de réservation** : `instant` par défaut — seuls les créneaux réellement
  disponibles (occupation des techniciens + adresse) sont proposés. En parallèle,
  le schéma supporte la **prestation sans date** : un `job` peut rester
  `status='unscheduled'`, et `bookings.manage_token` (token signé) permet
  d'envoyer au client un **lien de choix de créneau** sur une prestation
  préconfigurée pour lui, sans compte.
- **Cartographie** : `geo.provider = ors` (OpenRouteService) par défaut dans
  `settings` ; Google activable par clé API ; repli `postal_matrix`.
- **Remise multi-prestations** : règle `multi_item_10` = −10 % dès la 2ᵉ
  prestation à la même adresse (paramétrable via `settings` + `pricing_rules`).
- **Hébergement** : mutualisé, pas de Node/Docker/Redis en prod ; tâches par cron
  PHP CLI avec repli pseudo-cron HTTP.

## 6. Ce que la Phase 1 NE fait pas encore

Aucune ligne de code applicatif PHP n'est livrée en Phase 1 (conforme à la
méthode : Phase 1 = fondations données). Les moteurs, l'API, le widget et le
back-office arrivent aux phases suivantes, dans l'ordre défini par le prompt.
