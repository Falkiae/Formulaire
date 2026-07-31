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

### Retouche d'une commande passée (`BookingEditService`)

Le devis initial vient de `CartPricer`, à partir du panier — il n'est plus
rejouable une fois le panier converti. Les totaux sont donc **recalculés** à
partir des lignes réellement présentes, avec la même arithmétique :

```
net    = sous-total − remise + supplément déplacement
TVA    = Money::vat(net, taux figé sur la commande)
total  = net + TVA
```

Le taux reste celui **figé sur la commande** : une commande passée sous un taux
donné ne change pas de taux parce qu'un réglage a bougé depuis.

Opérations : corriger prix et quantité d'une ligne, ajouter une prestation du
catalogue (au tarif du mode du rendez-vous), ajouter une **ligne sur mesure**
hors catalogue (`booking_items.service_id` NULL, migration 007), retirer une
ligne, **rattacher ou retirer un extra** sur une ligne, fixer une **remise** en
€ ou en %.

**Saisie et affichage en TVAC.** Comme dans le catalogue (`CatalogController`),
l'admin raisonne en prix client : les champs et les totaux du panneau sont TVA
comprise, la conversion se fait à l'affichage (`Money::addVat`) et à
l'enregistrement (`JobController::tvacToHtCents`). Le stockage reste en HT —
c'est lui qui porte la TVA jusqu'à l'UBL. Le taux appliqué est celui **figé sur
la commande**, jamais le réglage courant.

> Conséquence assumée de ce stockage HT, déjà présente dans le catalogue : un
> aller-retour peut décaler d'un centime (10,00 € saisis → 8,26 € HT → 9,99 €
> réaffichés). Le pied de tableau, lui, s'additionne toujours exactement : la
> remise affichée est déduite du total plutôt que convertie séparément.

Les extras font partie du prix de la ligne — `line_total_cents = (prix unitaire
+ extras) × quantité`, comme à la création. Leur tarif est **figé au
rattachement** : celui du catalogue peut bouger, celui d'une commande passée ne
doit pas. Une ligne du catalogue propose les extras de sa prestation (surcharge
de prix par service prise en compte) ; une ligne sur mesure propose tous les
extras actifs.

Trois garde-fous, tous côté serveur :

- une commande **facturée** n'est plus modifiable — la numérotation est
  séquentielle et sans trou, désynchroniser facture et commande créerait un
  écart comptable invisible (passer par un avoir) ;
- une commande **annulée** non plus ;
- la **dernière ligne** n'est pas supprimable : une commande sans prestation
  n'a pas de sens, c'est une annulation.

La remise saisie **remplace** la précédente (remise cumul et coupon d'origine
comprises) : c'est le montant décidé par l'admin, pas un cumul implicite.

Ajouter ou retirer une prestation (ou un extra) **change la durée** du
rendez-vous : `jobs.active_duration_min` et `scheduled_end` suivent, sans quoi
le moteur de disponibilité continuerait de croire le technicien libre.

Les durées sont ajustées **par écart**, pas recalculées depuis les lignes : le
tunnel public enregistre les lignes avec `unit_duration_min = 0` (BookingService
agrège les durées par mode au moment de créer les jobs, sans les reporter sur
les lignes). Un recalcul « somme des lignes » ramènerait donc à zéro la durée de
toutes les commandes existantes. Si la nouvelle durée
fait chevaucher un autre rendez-vous du même technicien, c'est **signalé sans
être bloqué** — allonger une prestation est légitime, c'est au planning de
suivre.

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
