# Keepnew Booking — Phase 9 : notifications, facturation, Peppol

Le volet communication client et conformité finance belge.

## Notifications (`src/Notification/`)

Moteur d'événements configurable : `booking_created`, `booking_confirmed`,
`reminder_48h`, `reminder_2h`, `technician_en_route`, `job_completed`,
`review_request`, `cart_abandoned`, `booking_cancelled`.

- **`NotificationService`** — sur un événement, résout les templates par canal,
  calcule l'échéance (`scheduled_at`) : immédiate pour la confirmation, **relative
  au rendez-vous** pour les rappels (offset négatif sur `scheduled_start`).
  Envoi immédiat si l'échéance est atteinte, sinon file d'attente expédiée par
  cron. Toute tentative est journalisée dans `notifications_log`.
- **`TemplateRenderer`** (pur, testé) — variables `{{booking.reference}}`,
  `{{customer.first_name}}`, `{{job.date}}`, `{{technician.first_name}}`… ;
  échappement HTML pour l'email, brut pour le SMS.
- **SMS** — `SmsProviderInterface` + `NullSmsProvider` (défaut, journalise),
  `TwilioSmsProvider`, `BrevoSmsProvider`, activés par configuration.
- **Email** — `MailerInterface` + `LogMailer` (défaut, écrit dans
  `storage/logs`), `SmtpMailer` (PHPMailer) activé par configuration.

Déclenché depuis l'API : à la confirmation d'une réservation →
`booking_confirmed` (immédiat) + `reminder_48h` + `reminder_2h` (programmés).

`booking_cancelled` fait exception : il est déclenché par **`BookingService`
lui-même**, dans `cancelBookingRow()`, et non par les contrôleurs. Les trois
chemins d'annulation (back-office, page client `/rdv/{token}`, API JSON)
convergent vers cette méthode ; le déclencher au niveau des contrôleurs
revenait à l'oublier dans deux d'entre eux — ce qui était le cas.

`job_completed` est déclenché par l'app technicien (`TechController::status`)
quand le technicien marque l'intervention terminée.

`review_request` est le seul événement **déclenché à la main** : bouton
« Envoyer une demande d'avis » sur la fiche du rendez-vous, visible uniquement
si le job est `completed`, et une seule fois par commande
(`JobController::requestReview` vérifie les deux). C'est un geste commercial
qu'on ne veut poser qu'après une intervention réussie, jamais en masse. Le
message s'appuie sur `{{review.url}}`, alimentée par le réglage
`company.review_url` (`/admin/reglages`).

> **Un événement sans modèle est muet.** `trigger()` sort immédiatement si
> l'événement n'a aucun `notification_template` actif, et `/admin/notifications`
> ne sait qu'**éditer** des modèles existants, jamais en créer. Un événement
> dépourvu de ligne en base est donc à la fois silencieux et invisible dans
> l'interface. C'était le cas de `booking_cancelled` (migration 005) puis de
> `job_completed` et `review_request` (migration 006) : les trois ont désormais
> un modèle e-mail, éditable dans le back-office.

> **Asymétrie connue** : `job_completed` n'est déclenché que depuis l'app
> technicien. Un rendez-vous passé à « Terminé » depuis le back-office
> (`JobController::updateStatus`) ne notifie pas le client et ne crée pas
> l'indemnité de mobilité — contrairement à `TechController::status`.

## Facturation (`src/Invoice/`)

- **`InvoiceService`** — génère la facture d'une commande :
  - **numérotation séquentielle SANS TROU** (compteur par année verrouillé
    `FOR UPDATE`) au format `AAAA-NNNNNN` ;
  - **facture simplifiée** si total TVAC < seuil (250 € par défaut, configurable),
    sinon complète ;
  - lignes issues des lignes de commande, TVA 21 % ; idempotent (une commande
    déjà facturée renvoie sa facture).
- **`JournalExporter`** — journal des recettes en CSV (HTVA / TVA / TVAC +
  totaux), obligation TVA belge.

## Peppol / UBL BIS 3.0 (`src/Invoice/UblGenerator.php`)

Génération de la facture au format **UBL BIS 3.0** pour le B2B (obligatoire en
Belgique depuis janvier 2026) : CustomizationID/ProfileID Peppol, parties
fournisseur/client avec numéro de TVA, TaxTotal, LegalMonetaryTotal, lignes. Le
statut `peppol_status` passe `pending → sent` lors de l'export (hook d'access
point à brancher).

## Tâches planifiées (`cron/cron.php`)

À lancer par cron CLI (ex. toutes les 5 min), avec repli pseudo-cron HTTP :
- expédie les notifications dues ;
- purge les holds de créneaux expirés ;
- facture les commandes terminées non facturées.

## Validation réelle (MariaDB 10.11 + PHP 8.4)

- Réservation → `notifications_log` : `booking_confirmed` (email, **sent**),
  `reminder_48h` (email, sent car échéance passée), `reminder_2h` (SMS, **queued**
  pour J-2h). Email rendu avec variables dans `storage/logs/mail.log`.
- `cron/cron.php` : expédie les dues, purge les holds, facture les commandes
  terminées.
- Facture générée `2026-000001`, **simplifiée** (9559 < 25000), HTVA 7900 / TVA
  1659 / TVAC 9559, Peppol `pending` (B2B).
- **UBL** téléchargé : XML bien formé, CustomizationID Peppol, TVA 21 %, total
  143,99… (selon la facture).
- **Journal CSV** : lignes + totaux HTVA/TVA/TVAC.

Tests : `tests/Notification/TemplateRendererTest.php`,
`tests/Invoice/UblGeneratorTest.php`.

## Ce que la Phase 9 laisse aux phases suivantes

Envoi réel via un access point Peppol (le hook est prêt), génération PDF de la
facture, panier abandonné (relance par email avec lien de reprise), et l'app
technicien (Phase 10) qui déclenchera `technician_en_route` et `job_completed`.
