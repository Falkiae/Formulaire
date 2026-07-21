# Keepnew Booking — Phase 8 : form builder

Rend le formulaire d'intake entièrement configurable en back-office, servi au
widget et **revalidé côté serveur**.

## Composants

- **`Form\FormRepository`** — versions, champs, options, conditions (CRUD),
  publication, et `publishedForm()` (le formulaire en ligne prêt pour le widget).
- **`Form\FormValidator`** (pur, testé) — évalue la logique conditionnelle et
  valide les réponses **côté serveur**. Règles : un champ masqué n'est jamais
  requis ; un champ rendu requis par une condition l'est même s'il ne l'était pas
  par défaut. Opérateurs : `eq`, `neq`, `in`, `gt`, `lt`, `filled`, `empty`.
- **`Admin\FormBuilderController`** + vues — gestion des versions (brouillon →
  publication), ajout/suppression/réordonnancement de champs (drag & drop),
  options avec modificateur de durée, et conditions `SI … ALORS`.
- **`Http\Api\FormApiController`** — `GET /api/form` renvoie la version publiée.
- **Widget** — l'étape « Questions » rend désormais les champs **dynamiquement**
  depuis `/api/form`, applique la logique conditionnelle et l'option
  `onsite_only` côté client, puis envoie les réponses.
- **Booking** — `POST /api/bookings` **revalide** les réponses via
  `FormValidator` avant de créer la commande ; un formulaire incomplet renvoie
  `422` avec les erreurs par champ.

## Types de champs supportés

texte, textarea, nombre, select, radio, checkbox, cartes, stepper, date, photo,
adresse, code promo, consentement.

## Flux d'édition

1. Créer un **brouillon** (nouvelle version, non publiée).
2. Ajouter des champs (type, étape, requis), leurs options, et des conditions.
3. **Publier** : la version devient le formulaire du tunnel, sans redéploiement.

## Validation réelle (MariaDB 10.11 + PHP 8.4 + navigateur)

- `GET /api/form` : renvoie la version publiée du seed (champs `water_access*`,
  `power_access*`, `parking`, `floor`, `pets`, `dirt_level*`, `dirt_photo` +
  1 condition).
- **Revalidation serveur** : réservation avec réponses manquantes → **HTTP 422**
  avec `fields: [water_access, power_access, dirt_level]` ; avec les réponses →
  **201**.
- **Admin builder** : création d'un brouillon v2, ajout d'un champ « Étage »,
  publication → `/api/form` sert la v2. Réordonnancement drag & drop opérationnel.
- **Widget** : l'intake rend les **7 champs dynamiques**, applique la logique,
  et le tunnel complet aboutit à une réservation (navigateur, zéro erreur).

Tests : `tests/Form/FormValidatorTest.php` (défaut requis, condition
`require`/`hide`, opérateurs `filled`/`in`).

## Ce que la Phase 8 laisse aux phases suivantes

L'application des modificateurs de durée portés par les options (ex. niveau de
salissure → +durée) au calcul de disponibilité, l'édition multi-étapes complète
du tunnel (au-delà de l'intake), et l'aperçu live du formulaire dans le builder.
