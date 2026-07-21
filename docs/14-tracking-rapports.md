# Keepnew Booking — Phase 11 : tracking, rapports, documentation

## Tracking (`src/Tracking/`, widget)

Exigence de premier ordre — actif dès que les identifiants sont configurés,
sinon no-op.

- **GA4 `dataLayer`** (widget, page hôte) : `add_to_cart` (ajout au panier),
  `begin_checkout` (passage au tunnel), `purchase` (confirmation) avec `value`,
  `currency`, `items` et `transaction_id`.
- **Meta CAPI côté serveur** (`MetaCapiClient`) : événement `Purchase` envoyé
  depuis le serveur à la confirmation, avec **`event_id` partagé** avec le pixel
  navigateur → **déduplication** (un seul événement compté). Données
  utilisateur **hachées SHA-256** (`Hasher` : email normalisé, téléphone E.164).
- **Google Ads Enhanced Conversions** : mêmes données hachées (`Hasher`) prêtes à
  l'envoi (upload via l'API Ads à brancher).
- Chaque conversion serveur est journalisée (`notifications_log`,
  `event_key=tracking_purchase`) avec l'`event_id` (traçabilité + dédup).

Le widget génère l'`event_id`, le pousse dans `dataLayer.purchase` **et** l'envoie
dans le payload de réservation ; le serveur le réutilise pour Meta CAPI.

## Rapports (`src/Admin/ReportController`, `/admin/rapports`)

Tableau de bord : chiffre d'affaires, réservations, **panier moyen**, **taux de
conversion** du tunnel (paniers → commandes), **taux d'annulation**, **km
parcourus** ; CA **par prestation**, **par technicien**, **par mois**.

## Documentation

- `docs/12-installation.md` — installation pas à pas sur mutualisé, `.env`, cron,
  intégration du widget.
- `docs/13-qa-checklist.md` — checklist QA (parcours, erreurs, back-office, app
  technicien, responsive/accessibilité WCAG AA, performance, sécurité,
  conformité belge).
- `docs/15-guide-admin.md` — guide d'utilisation du back-office.

## Validation réelle (MariaDB 10.11 + PHP 8.4)

- `Hasher` : email normalisé+haché, téléphone belge → E.164 (`0470… → 32470…`).
- Réservation → conversion serveur journalisée (`tracking_purchase`, `skipped`
  car Meta non configuré en démo) avec l'`event_id` renvoyé au client.
- Widget : `dataLayer` reçoit `add_to_cart`, `begin_checkout`, `purchase` avec
  `event_id` et `value`.
- `/admin/rapports` : KPIs et ventilations rendus depuis les données réelles.

Tests : `tests/Tracking/HasherTest.php`.
