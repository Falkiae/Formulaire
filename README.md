# Keepnew Booking

Plateforme de prise de rendez-vous en ligne pour **Keepnew SRL**
(BE 1009.875.116) — nettoyage mobile de véhicules, canapés et matelas, à domicile
ou en atelier, principalement en province de Liège.

Trois différences avec les SaaS type Zenbooker / BookingKoala :

1. **Auto-hébergé** sur mutualisé classique (PHP 8.2+ / MySQL), sans Docker ni Node en prod.
2. **Moteur de disponibilité intégrant le temps de trajet** entre interventions.
3. **Widget embarquable** sur keepnew.be sans casser design ni SEO.

## État d'avancement (par phases)

| Phase | Contenu | Statut |
|---|---|---|
| **1** | Architecture + schéma SQL + seed | ✅ Livrée |
| **1b** | Noyau micro-MVC maison (routeur, requête, PDO, session, CSRF, vues) | ✅ Livré |
| **2** | Back-office catalogue (CRUD complet) + simulateur de prix ([doc](docs/03-back-office-catalogue.md)) | ✅ Livrée |
| **3** | Moteur de tarification & durée du panier + tests ([doc](docs/04-moteur-tarification.md)) | ✅ Livrée |
| **4** | Moteur de disponibilité (domicile/atelier) + tests ([doc](docs/05-moteur-disponibilite.md)) | ✅ Livrée |
| **5** | API REST + panier + découpage en jobs ([doc](docs/06-api-panier-commande.md)) | ✅ Livrée |
| **6** | Widget public & tunnel ([doc](docs/07-widget-tunnel.md)) | ✅ Livrée |
| **7** | Dispatch, ateliers, clients ([doc](docs/08-back-office-operationnel.md)) | ✅ Livrée |
| **8** | Form builder ([doc](docs/09-form-builder.md)) | ✅ Livrée |
| **9** | Notifications, facturation, Peppol ([doc](docs/10-notifications-facturation-peppol.md)) | ✅ Livrée |
| **10** | App technicien PWA ([doc](docs/11-app-technicien-pwa.md)) | ✅ Livrée |
| **11** | Tracking, rapports, documentation ([doc](docs/14-tracking-rapports.md)) | ✅ Livrée |
| 12 | Activation du paiement en ligne | ultérieure |

## Contenu de la Phase 1

- `docs/01-architecture.md` — choix d'architecture argumenté, arborescence, flux.
- `database/schema.sql` — schéma complet commenté (61 tables, InnoDB, utf8mb4).
- `database/seed.sql` — jeu de données Keepnew réaliste (catalogue, 5 techniciens,
  1 atelier, zones province de Liège, formulaire d'intake, notifications).

Le schéma et le seed ont été **validés par chargement réel sur MariaDB 10.11**
(intégrité des clés étrangères, JSON, encodage utf8mb4, simulation de devis).

## Noyau applicatif (micro-MVC maison)

Le socle de code sur lequel s'appuient toutes les phases suivantes est en place
et **documenté en détail dans [`docs/02-noyau-mvc.md`](docs/02-noyau-mvc.md)** :
front controller unique, routeur (routes paramétrées + groupes + middlewares),
requête immuable à accesseurs typés, réponse, conteneur DI, accès base PDO
100 % préparé, session sécurisée, protection CSRF, rate limiting, moteur de vues
sans `extract()`, et les utilitaires `Money` (centimes) / `Clock` (UTC↔Bruxelles).

Validé en exécution réelle (PHP 8.4) : routage, 404, en-têtes de sécurité,
accesseurs filtrés, TVA en centimes — 11 assertions au vert.

```bash
php -S 127.0.0.1:8080 -t public public/index.php
curl -s http://127.0.0.1:8080/health
```

## Installation locale (validation du schéma)

Prérequis : MySQL 8 ou MariaDB 10.6+.

```bash
# Créer la base
mysql -uroot -e "CREATE DATABASE keepnew CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Charger le schéma puis le seed
mysql -uroot keepnew < database/schema.sql
mysql -uroot keepnew < database/seed.sql
```

## Conventions transverses

- **Montants** : entiers en **centimes** d'euro, jamais de float. Taux (TVA,
  remises) en **points de base** (`2100` = 21 %).
- **Dates** : stockées en **UTC**, affichées en `Europe/Brussels`.
- **Durées** : en minutes (entiers).
- **SQL** : PDO préparé exclusivement, jamais de requête concaténée.
- **Réglages** : dans la table `settings` (barèmes, seuils, clés API), jamais en dur.

## Convention de branche

Développement sur `claude/session-czmr0w`.

## Documentation complète

- [01 — Architecture](docs/01-architecture.md) · [02 — Noyau MVC](docs/02-noyau-mvc.md)
- [03 — Back-office catalogue](docs/03-back-office-catalogue.md) · [04 — Moteur de tarification](docs/04-moteur-tarification.md)
- [05 — Moteur de disponibilité](docs/05-moteur-disponibilite.md) · [06 — API, panier, commande](docs/06-api-panier-commande.md)
- [07 — Widget & tunnel](docs/07-widget-tunnel.md) · [08 — Back-office opérationnel](docs/08-back-office-operationnel.md)
- [09 — Form builder](docs/09-form-builder.md) · [10 — Notifications, facturation, Peppol](docs/10-notifications-facturation-peppol.md)
- [11 — App technicien PWA](docs/11-app-technicien-pwa.md) · [14 — Tracking & rapports](docs/14-tracking-rapports.md)
- **Exploitation** : [16 — Déploiement OVH pas à pas](docs/16-deploiement-ovh.md) · [12 — Installation générique](docs/12-installation.md) · [13 — Checklist QA](docs/13-qa-checklist.md) · [15 — Guide admin](docs/15-guide-admin.md)

## Périmètre de lancement

Les **11 phases du périmètre de lancement sont livrées**. La phase 12 (activation
du paiement en ligne via Mollie/Stripe) est prévue **ultérieurement** :
l'architecture est déjà prête (interface `PaymentGatewayInterface`, table
`payments`, `payment_status`, `NullGateway` actif) — activable par configuration,
sans refonte.
