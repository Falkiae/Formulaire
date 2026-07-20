# Keepnew Booking — Phase 2 : back-office catalogue + simulateur de prix

Livrée avant le moteur, conformément à la méthode : sans catalogue manipulable
et sans simulateur pour vérifier la grille, rien n'est testable.

## Ce qui est livré

### Calculateur de prix/durée (`src/Pricing/`)
Cœur réutilisé par les phases 3 (panier, remise cumul, coupons, TVA) et 4
(disponibilité). **Volontairement pur** (aucun accès base) → entièrement
testable.

- `PriceCalculator::calculateLine()` applique un **ordre d'application explicite
  et documenté** :
  1. **base** (prix/durée du mode, ou du service à défaut)
  2. **variante** (`override` remplace la base, sinon `delta` s'ajoute)
  3. **extras** (chaque montant/durée s'ajoute)
  4. **modificateurs** de champ/option, dans l'ordre `fixed → percent →
     multiplier` (pourcentages en points de base, multiplicateurs en millièmes)
  5. **unité** arrondie, **ligne** = unité × quantité ; jamais négatif
- `LineQuote` / `PriceComponent` : résultat + détail « ligne par ligne ».
- Tests : `tests/Pricing/PriceCalculatorTest.php` (9 scénarios : base, delta,
  override, extras, percent, multiplier, quantité, plancher zéro, ordre
  fixed→percent).

### Catalogue (`src/Catalog/`)
- `CatalogRepository` : lecture (arborescence des catégories, services, modes,
  variantes, extras avec **prix effectif** = surcharge du pivot ou défaut de
  l'extra) et écriture (prix/durée de base, prix/durée par mode, activation)
  avec **historisation** dans `price_history` (ne fausse pas les commandes
  passées).
- `SimulatorService` : résout une configuration depuis la base, appelle le
  calculateur, ajoute la **TVA** (taux lu dans `settings`, jamais en dur) et
  renvoie un devis prêt à afficher (HTVA / TVA / TVAC + détail).

### Authentification (`src/Auth/`, `src/Admin/AuthController.php`)
- `UserRepository::verifyCredentials()` : **Argon2id**, ré-hash transparent,
  comparaison factice anti-énumération, horodatage de connexion.
- Login régénère la session (anti-fixation), CSRF sur le POST.

### Back-office (`src/Admin/`, `views/admin/`)
- **Catalogue** : arborescence des catégories + liste des prestations
  (`/admin/catalogue`).
- **Fiche service éditable** (`/admin/catalogue/service/{id}`) : prix/durée de
  base, cases et champs **par mode** (à domicile / atelier) avec prix, durée
  active et immobilisation de poste, activation ; tableaux variantes et extras.
- **Simulateur de prix** (`/admin/simulateur`) : configuration live (prestation,
  mode, variante, extras, quantité) → **devis vivant** recalculé en direct par
  appel JSON, avec le détail ligne par ligne. Exclusivité des extras `radio`
  gérée côté client.

### Design (`public/assets/`)
- `design-tokens.css` : tokens de marque (un seul accent, typographie
  accessible, échelle d'espacement, rayons, mode sombre, `prefers-reduced-motion`).
- `admin.css` : back-office calme et discipliné ; le « ticket » du devis en
  chiffres tabulaires.

## Routes

| Méthode | Chemin | Rôle |
|---|---|---|
| GET/POST | `/admin/connexion` | Connexion (CSRF sur POST) |
| GET | `/admin/deconnexion` | Déconnexion |
| GET | `/admin/catalogue` | Catégories + prestations |
| GET/POST | `/admin/catalogue/service/{id}` | Fiche éditable (CSRF sur POST) |
| GET | `/admin/simulateur` | Page simulateur |
| GET | `/admin/simulateur/service/{id}` | Config JSON d'une prestation |
| POST | `/admin/simulateur/calcul` | Devis JSON (CSRF) |

Le groupe `/admin/*` est protégé par `AuthMiddleware` (302 → login en HTML,
401 en JSON) ; les POST par `CsrfMiddleware` (419 si jeton manquant/invalide).

## Validation réelle

Testé de bout en bout sur MariaDB 10.11 + serveur PHP 8.4 :

- Connexion : mauvais mot de passe → retour login ; bon mot de passe → catalogue.
- Catalogue : rend les prestations réelles du seed (utf8mb4 intact).
- Simulateur : canapé 4 places à domicile + ozone = **119,00 € HTVA / 24,99 € TVA
  / 143,99 € TVAC**, 110 min, détail ligne par ligne — cohérent avec le devis
  Phase 1 et les tests unitaires.
- Mode atelier : canapé 3 places = 69,00 € (vs 79,00 € à domicile), immobilisation
  195 min.
- Écriture : modification du prix matelas → base mise à jour **et** deux lignes
  `price_history` (service + mode) avec `changed_by`.
- Garde-fous : accès non authentifié bloqué, CSRF appliqué.

## Identifiants de démonstration

`antoine@keepnew.be` / `keepnew-demo` (rôle admin). À changer en production.

## CRUD catalogue complet

Ajouté au-dessus du socle : gestion complète depuis le back-office, sans
intervention technique.

- **Catégories** : création, édition (nom, parent, description, icône,
  visibilité), suppression **gardée** (refusée si sous-catégories ou services),
  réordonnancement (drag & drop → endpoint JSON), slug SEO auto-unique.
- **Services** : création (avec mode « à domicile » activé par défaut),
  **duplication en un clic** (recopie modes, variantes et extras rattachés sous
  « … (copie) », inactif), suppression **gardée** (désactivation si déjà
  commandé), réordonnancement drag & drop.
- **Variantes** : ajout, édition inline (libellé, Δ prix, Δ durée, activation),
  suppression gardée, réordonnancement.
- **Extras** : catalogue central (créer/éditer/supprimer), **rattachement à un
  service** avec surcharge de prix/durée, sélection `checkbox`/`radio` et groupe
  d'exclusivité ; détachement.

### Garde-fous métier (« Delete » ≠ effacement)

Conformément au cahier des charges, rien qui a un passé n'est réellement
effacé : la suppression **dégrade automatiquement en désactivation** dès qu'une
donnée est référencée par un panier ou une commande. Vérifié en conditions
réelles :

| Action | Sans référence | Avec référence (commande/panier) |
|---|---|---|
| Supprimer un service | supprimé (cascade modes/variantes) | **désactivé** (`is_active=0`) |
| Supprimer un extra | supprimé | **désactivé** |
| Supprimer une variante | supprimée | **désactivée** |
| Supprimer une catégorie | supprimée si vide | **refusée** si contient des éléments |

### Validation réelle (suite)

Testé de bout en bout (MariaDB + PHP 8.4) : création catégorie « Tapis »,
création service « Nettoyage tapis » (+ mode onsite auto), ajout/suppression de
variante, création + rattachement (override 12,50 € / radio / groupe) +
détachement d'un extra, duplication d'un service (6 variantes recopiées),
réordonnancement persisté, et les 4 garde-fous ci-dessus.

## Reste de la Phase 2 (optionnel, cosmétique)

Éditeur de grille de variantes en saisie clavier rapide, upload de photos
avant/après et d'illustrations, réordonnancement drag & drop des catégories dans
l'arbre (l'endpoint existe déjà, seul le liant visuel manque). Non bloquant pour
la suite.
