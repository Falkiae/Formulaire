# Keepnew Booking — Phase 4 : moteur de disponibilité

Le cœur différenciant du produit : proposer des créneaux réservables en tenant
compte de l'occupation des techniciens, des postes d'atelier **et du temps de
trajet** entre interventions — ce que les concurrents ne font pas.

## Architecture

Le moteur (`Availability\AvailabilityEngine`) est **pur** : il reçoit des
contextes déjà construits (fenêtres UTC, blocs occupés) et produit des créneaux.
La lecture base est isolée dans `AvailabilityRepository`, l'orchestration dans
`AvailabilityService`. Tout est raisonné en **UTC**.

```
Panier + adresse
   │
AvailabilityService
   ├─ Étape 0 : découpe en jobs par mode (onsite / workshop)
   ├─ Domicile : ZoneResolver → filtrage techniciens (zone + compétences)
   │             → AvailabilityEngine::onsiteSlots (contrôle de trajet)
   └─ Atelier  : postes + techniciens → AvailabilityEngine::workshopSlots
```

## Abstraction cartographie (`src/Geo/`)

- `GeoProviderInterface` — `travelSeconds(from, to): ?int`.
- `OpenRouteServiceProvider` — défaut du projet (Matrix API, cURL).
- `PostalMatrixGeoProvider` — repli par la table `postal_travel_matrix`.
- `HaversineGeoProvider` — dernier repli hors ligne (distance × détour ÷ vitesse).
- `ChainGeoProvider` — essaie chaque fournisseur dans l'ordre (API → codes
  postaux → haversine).
- `CachingGeoProvider` — cache agressif (`geo_distance_cache`, clé =
  hash(origine, destination, mode), TTL 30 j) pour ne pas marteler l'API.

## Branche domicile

1. **Résolution de zone** (`ZoneResolver`) — rayon (haversine), codes postaux,
   ou polygone (point-dans-polygone). Hors zone → capture de lead ; agrège le
   supplément déplacement des zones correspondantes.
2. **Filtrage techniciens** — compétences requises par toutes les prestations,
   zone assignée, disponibilité récurrente, congés (retirés des fenêtres).
3. **Génération de créneaux** — pas configurable, horaires d'ouverture par jour,
   délai minimum, horizon.
4. **Contrôle de trajet** ⭐ — pour chaque créneau candidat, le trajet depuis le
   job précédent et vers le job suivant du technicien est calculé ; le créneau
   est rejeté si `trajet + job` dépasse le gap disponible.

## Branche atelier

- Pas de zone ni de trajet. La ressource limitante est le **poste (bay)** autant
  que le technicien : un créneau n'est proposé que si un poste **et** un
  technicien compétent sont libres simultanément.
- Sortie = heure de dépôt + **reprise estimée** (dépôt + immobilisation, séchage
  inclus : `occupancy_duration_min` distinct de `active_duration_min`).

## Robustesse au changement d'heure (DST)

`ScheduleBuilder::windowForDate()` convertit les horaires locaux
(Europe/Brussels) en UTC via `DateTimeZone`. En hiver 09:00 local = 08:00 UTC
(UTC+1) ; en été 09:00 local = 07:00 UTC (UTC+2). Le moteur ne manipule ensuite
que des instants UTC : aucun créneau fantôme ni décalé lors des bascules de mars
et octobre.

## Anti-double-réservation

Les `slot_holds` (réservations temporaires de 10 min du tunnel) sont injectés
comme blocs occupés, au même titre que les jobs planifiés : un créneau tenu par
un autre client en cours de réservation n'est pas proposé. Le verrou pessimiste
`SELECT … FOR UPDATE` à la confirmation (Phase 5) complète le dispositif.

## Tests (`tests/Availability/AvailabilityEngineTest.php`)

Cas limites imposés, tous couverts :

| Cas | Vérification |
|---|---|
| Chevauchement | aucun créneau ne recouvre un job existant |
| Trajet trop long | créneaux proches d'un job éloigné rejetés (trajet 40 min respecté) |
| Congé | `subtract` retire la plage de la fenêtre ; journée entière → aucune dispo |
| Réservation concurrente | un hold retire le créneau correspondant |
| Changement d'heure | 09:00 local → 08:00 UTC (hiver) / 07:00 UTC (été), jour de bascule inclus |
| Atelier | poste **et** technicien requis ; reprise = dépôt + immobilisation |

## Validation réelle (MariaDB 10.11 + PHP 8.4)

Contre le seed, avec repli haversine/matrice codes postaux (hors ligne) :

- **Domicile** (Ans 4432) : zone [1,2] résolue, 60 créneaux dès J+1 08:00,
  techniciens compétents en zone (Sofia exclue car dédiée atelier).
- **Hors zone** (Bruxelles 1000) : `out_of_zone`.
- **Atelier** (matelas) : dépôt 08:00 → reprise ~10:15 (immobilisation 135 min),
  poste 1 + technicien.
- **Découpe panier mixte** : job domicile 110 min + job atelier 75 min actif /
  195 min d'immobilisation.

## Ce que la Phase 4 laisse aux phases suivantes

L'exposition via l'API REST et le tunnel (choix effectif du créneau, pose du
hold, verrou de confirmation) relève des phases 5 et 6. L'optimisation de
tournée (tri « créneaux verts » par regroupement géographique) et la matrice
Google en production s'y branchent sans refonte.
