# Keepnew Booking — Installation & configuration

Guide d'installation pas à pas sur hébergement mutualisé (PHP 8.2+ / MySQL 8 ou
MariaDB 10.6+), sans Node ni Docker en production.

## 1. Prérequis

- PHP **8.2+** avec les extensions : `pdo_mysql`, `mbstring`, `json`, `curl`,
  `openssl`, `gd`, `intl`.
- MySQL 8 / MariaDB 10.6+ (InnoDB, utf8mb4).
- Un cron CLI (ou, à défaut, un pseudo-cron par requête HTTP).
- Composer (recommandé, pour PHPMailer et les tests) — **facultatif** : un
  autoloader PSR-4 maison prend le relais si `vendor/` est absent.

## 2. Dépôt des fichiers

Deux options :

- **git** : `git clone` puis `git pull` pour les mises à jour ;
- **FTP/SFTP** : uploader le dossier.

**Racine web** : faire pointer le DocumentRoot sur `public/`. Si l'hébergeur
l'impose ailleurs, le `.htaccess` à la racine redirige déjà vers `public/` et
bloque l'accès à `src/`, `config/`, `storage/`, `.env`.

## 3. Base de données

```bash
mysql -u<user> -p -e "CREATE DATABASE keepnew CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u<user> -p keepnew < database/schema.sql
mysql -u<user> -p keepnew < database/seed.sql   # jeu de démonstration (optionnel)
```

## 4. Configuration (`.env`)

Copier `.env.example` vers `.env` **hors webroot** (ou à la racine, protégé par
`.htaccess`), puis renseigner :

| Bloc | Variables |
|---|---|
| App | `APP_ENV`, `APP_DEBUG=false`, `APP_URL`, `APP_KEY` (32+ octets aléatoires) |
| Base | `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` |
| Email | `MAIL_HOST`, `MAIL_PORT`, `MAIL_USER`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` |
| SMS | `SMS_PROVIDER` (`none`/`twilio`/`brevo`) + clés |
| Cartographie | `GEO_PROVIDER=ors`, `ORS_API_KEY` (ou `GOOGLE_MAPS_API_KEY`) |
| Anti-spam | `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY` |
| Tracking | `GA4_MEASUREMENT_ID`, `META_CAPI_PIXEL_ID`, `META_CAPI_TOKEN`, `GOOGLE_ADS_CONVERSION_ID` |
| Peppol | `PEPPOL_ACCESS_POINT_URL`, `PEPPOL_API_KEY` |
| Paiement | `PAYMENT_GATEWAY=null` (Mollie/Stripe ultérieurement) |

Tout ce qui n'est pas configuré reste **inactif proprement** : SMS → journalisé,
email → écrit dans `storage/logs`, tracking → no-op.

## 5. Dépendances (optionnel)

```bash
composer install --no-dev --optimize-autoloader   # PHPMailer, etc.
```

## 6. Permissions

Rendre `storage/` inscriptible (uploads, logs, cache) :

```bash
chmod -R 775 storage
```

`storage/` et `.env` doivent rester **hors accès web** (déjà couvert par les
`.htaccess` fournis).

## 7. Cron

Ajouter au cron (toutes les 5 minutes) :

```
*/5 * * * * php /chemin/vers/keepnew/cron/cron.php >> /chemin/vers/keepnew/storage/logs/cron.log 2>&1
```

Le cron expédie les notifications dues, purge les créneaux temporaires (holds) et
facture les commandes terminées. **Fallback pseudo-cron** : si l'hébergeur ne
fournit pas de cron CLI, appeler `cron/cron.php` via une URL protégée déclenchée
par un service externe (ex. cron-job.org).

## 8. Comptes

Le seed crée un admin `antoine@keepnew.be` / `keepnew-demo` (à changer
immédiatement). En production, créer les comptes réels et supprimer la démo.

## 9. Intégration du widget sur keepnew.be

```html
<div id="keepnew-booking" data-api="https://booking.keepnew.be"></div>
<script src="https://booking.keepnew.be/widget.js" async></script>
```

Le widget est isolé en Shadow DOM et cross-origin (CORS géré côté API).

## 10. Vérification

- `https://booking.keepnew.be/health` → JSON `status: ok`.
- `/admin/connexion` → back-office.
- `/tech` (compte technicien) → app terrain.
- `widget-demo.html` (à retirer en prod) → tunnel de démonstration.
