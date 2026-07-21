# Keepnew Booking — Phase 7 : back-office opérationnel

Les outils quotidiens de l'équipe : dispatch, fiche job, clients, ateliers.

## Dispatch (`src/Admin/DispatchService`, `DispatchController`)

- **Agenda du jour**, une colonne par technicien + une colonne « à assigner »
  (jobs non planifiés / non assignés). Navigation jour précédent / suivant.
- **Réassignation par glisser-déposer** d'un job vers une autre colonne :
  - **recalcul du trajet en temps réel** (trajet depuis le job précédent et vers
    le job suivant du technicien, via le fournisseur Geo) ;
  - **détection de conflit** sous verrou (`SELECT … FOR UPDATE`) : un
    chevauchement avec un autre job du technicien est **refusé (HTTP 409)** ;
  - un trajet qui ne « tient » pas est **autorisé mais signalé** (le dispatcher
    garde la main) ;
  - chaque réassignation est tracée dans `audit_log`.
- Chaque carte job affiche l'heure, le trajet, le client, le mode, les
  prestations et l'adresse ; lien vers la fiche job.

## Fiche job (`JobController`)

Client + accès, prestations, **réponses au formulaire d'intake**, photos
avant/après, **changement de statut** (avec historisation), **note interne**, et
l'**historique de statut** complet.

## Clients (`CustomerController`)

- Liste avec **LTV** (somme des commandes non annulées), **fréquence** (nombre de
  commandes), **segment B2B/B2C**, recherche.
- Fiche client : coordonnées, TVA, historique de commandes, adresses, notes.

## Ateliers (`LocationController`)

Adresse, horaires, **postes de travail** (ajout), **fermetures exceptionnelles**
(plages de dates). Prêt pour le multi-site.

## Validation réelle (MariaDB 10.11 + PHP 8.4)

Deux réservations créées via l'API pour le 22/07, puis :

- **GET /admin/dispatch** : colonnes des 5 techniciens, jobs placés sous le bon
  technicien avec heure / mode / adresse.
- **Réassignation** job → technicien : `{ok:true, travel_fits:true,
  travel_in_min:5}` — trajet recalculé (5 min via matrice codes postaux),
  persisté, **audit_log** écrit (`dispatch.reassign`).
- **Conflit** (déplacer un job sur le créneau d'un autre du même technicien) :
  `{ok:false, conflict:true}` → **HTTP 409**.
- **Fiche job** : référence, réponses d'intake, historique rendus.
- **Clients** : LTV calculée et affichée.
- **Ateliers** : atelier Alleur avec ses 2 postes.

## Ce que la Phase 7 laisse aux phases suivantes

Carte du jour géolocalisée + itinéraire optimisé et export Waze/Google Maps
(données déjà disponibles : lat/lng par job), édition fine des horaires
d'atelier, et l'app technicien PWA (Phase 10) qui consommera la fiche job pour le
pointage et les photos.
