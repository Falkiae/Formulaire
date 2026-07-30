# Keepnew Booking — Phase 10 : app technicien (PWA)

L'outil terrain des techniciens, installable et responsive.

## Accès

Les comptes `technician` se connectent via le même login ; ils sont
automatiquement redirigés vers `/tech` (les autres rôles vers le back-office).
Chaque technicien ne voit que ses propres rendez-vous (`technicians.user_id`).

**Deux objets distincts, un lien obligatoire.** Le rôle `technician` ouvre le
droit d'accès, mais c'est la **fiche technicien** (table `technicians`) qui
porte compétences, zones, disponibilités et planning. L'app terrain résout la
fiche du connecté via `technicians.user_id` : sans ce lien, il n'y a rien à
afficher.

Ce rattachement se pilote depuis les deux côtés :

- **Utilisateurs → le compte** : le bloc « Fiche technicien » propose de créer
  une fiche (option par défaut) ou d'en rattacher une existante encore libre.
  La liste des comptes signale d'un badge « ⚠ sans fiche technicien » ceux qui
  resteraient orphelins.
- **Techniciens → la fiche** : champ « Compte de connexion (app terrain) », qui
  ne liste que les comptes `technician` encore libres.

Un compte non rattaché est refusé **à la connexion**, avec un message explicite
plutôt qu'une redirection vers une page d'erreur. Si le lien est retiré pendant
une session ouverte, `/tech` affiche `views/tech/no-profile.php` (403) au lieu
de l'erreur brute du Kernel.

Le lien est **unique** : un compte ne pilote qu'une fiche (contrainte
`uq_technicians_user`, migration 004). Rattacher un compte déjà pris délie
automatiquement l'ancienne fiche — l'app terrain ne lisant qu'une ligne, un
doublon rendrait le planning affiché imprévisible.

## Fonctionnalités (`src/Tech/`, `views/tech/`)

- **Planning du jour** — les rendez-vous du technicien, triés par heure, avec
  client, mode, prestations et adresse.
- **Fiche job** — détails, notes d'accès, téléphone cliquable, et **navigation**
  (liens Google Maps / Waze vers l'adresse géolocalisée).
- **Pointage CP 121** (`TimeEntryService`) — start/stop **immuables** (jamais
  d'UPDATE ; une correction crée une nouvelle ligne + audit), horodatés en UTC et
  **géolocalisés**.
- **Statut** — en route / en cours / terminé : déclenche `technician_en_route` et
  `job_completed`, et calcule l'**indemnité de mobilité** à la complétion.
- **Photos avant/après** — capture directe par l'appareil photo ; upload
  **sécurisé** (`ImageUpload` : MIME réel via finfo, taille, **ré-encodage GD**,
  stockage **hors webroot**, nom aléatoire) ; servies par un endpoint contrôlé.
- **Signature client** — capture au doigt sur `<canvas>`, enregistrée en PNG.
- **Encaissement sur place** — espèces / Bancontact : crée un paiement `paid`
  (via `NullGateway`, `collected_by`) et passe la commande à `payment_status=paid`.

## Indemnité de mobilité (CP 121)

À la complétion d'un job à domicile, une `mobility_allowance` est créée :
distance estimée depuis le trajet enregistré (`travel_in_min`), **barème
cents/km lu dans `settings`** (jamais en dur). Export mensuel CSV pour le
secrétariat social : `GET /admin/mobilite?month=YYYY-MM`.

## PWA

- `public/tech.webmanifest` — nom, `start_url` `/tech`, `display: standalone`,
  couleurs de marque, icône SVG maskable.
- `public/tech-sw.js` — service worker : coquille statique en cache-first,
  données en réseau-first (pas de planning périmé sur le terrain).
- Installable sur l'écran d'accueil, plein écran, thème `#586FF3`.

## Validation réelle (MariaDB 10.11 + PHP 8.4 + navigateur)

- Connexion technicien → redirection `/tech` ; planning affiche le RDV du jour.
- Fiche job rendue (pointage, statut, photos, signature, encaissement).
- Pointage `start` → `time_entries` horodaté + **géolocalisé** (lat 50.66).
- Statut `completed` → `mobility_allowances` (20 min → 15 km × 0,42 € = **6,30 €**).
- Encaissement espèces → `payments` `paid` 95,59 €, `collected_by`, commande
  `payment_status=paid`.
- PWA : manifest servi (`application/manifest+json`), service worker et icône OK,
  `<link rel=manifest>` présent, `theme-color` `#586FF3`.

## Ce que la Phase 10 laisse aux phases suivantes

Synchronisation hors ligne des actions (file d'attente en IndexedDB), floutage
automatique des éléments identifiants sur les photos vitrine, et notifications
push natives.
