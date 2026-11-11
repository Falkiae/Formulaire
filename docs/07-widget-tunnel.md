# Keepnew Booking — Phase 6 : widget public & tunnel

Le parcours client, mobile-first, embarquable sur keepnew.be, consommant l'API
REST (Phase 5).

## Embarquement

```html
<div id="keepnew-booking" data-api="https://booking.keepnew.be"></div>
<script src="https://booking.keepnew.be/widget.js" async></script>
```

- **Vanilla JS, zéro dépendance**, chargé en `async`.
- Rendu en **Shadow DOM** : les styles du site hôte n'affectent pas le widget et
  inversement (vérifié : une page hôte au style volontairement agressif ne
  traverse pas l'isolation).
- Tokens de marque inline, scopés au Shadow DOM.
- CORS activé côté API (préflight OPTIONS géré par le Kernel).

## Le tunnel (9 étapes nommées)

`Où · Quoi · Détails · Panier · Questions · Coordonnées · Rendez-vous ·
Récapitulatif · Confirmé`

1. **Où** — code postal + choix du mode (cartes « qu'on vienne chez moi » /
   « je viens à l'atelier »).
2. **Quoi** — catégories puis prestations en cartes visuelles ; auto-avance
   après un choix unique (300 ms de confirmation visuelle).
3. **Détails** — variante (cartes) + extras (cases, exclusivité `radio` gérée),
   **prix live** (`POST /api/quote`) affiché avant l'ajout au panier.
4. **Panier** — le « devis vivant » : lignes avec badge de mode, durée, remise
   groupée explicite, TVA, total TVAC en chiffres tabulaires ; « Ajouter une
   autre prestation » ou « Continuer ».
5. **Questions** — intake (accès eau/électricité si domicile, animaux, échelle
   de salissure).
6. **Coordonnées** — contact + adresse (autocomplete natif, `inputmode`), et
   consentement RGPD obligatoire.
7. **Rendez-vous** — `POST /api/availability` → **calendrier mensuel navigable**
   + liste des heures du jour choisi (voir plus bas).
8. **Récapitulatif** — devis final, code promo, « Confirmer la demande ».
9. **Confirmé** — référence, rappel « vous payez après l'intervention »,
   téléphone visible.

## Détails de conception (conformes au brief UX)

- **Mobile-first**, cibles ≥ 48 px, une décision par écran.
- **Barre de progression nommée** (sur les étapes réellement parcourues, voir
  plus bas) + **pastilles cliquables** des choix déjà faits (retour direct à une
  étape).
- **Panier collant** en bas (nombre de prestations + total), toujours visible.
- **Réassurance contextuelle** : une phrase par étape, jamais un bandeau permanent.
- **Voix** : vouvoiement, libellés d'action explicites (« Ajouter au panier »,
  « Choisir un créneau », « Confirmer la demande »), zéro jargon.
- **Reprise de session** via `localStorage` (le client retrouve son panier et son
  étape).
- **Accessibilité** : `label` réels, `inputmode`/`autocomplete`, focus visible,
  taille de police ≥ 16 px (anti-zoom iOS), `prefers-reduced-motion` respecté.

## Révision UX (novembre 2026)

Huit points relevés en relisant le tunnel comme un client, et ce qui a changé.

1. **Les champs n'étaient pas validés.** Un `required` HTML sur des `<input>`
   hors `<form>` ne déclenche rien, et le serveur ne vérifiait que le honeypot,
   le consentement et le formulaire d'intake : une réservation à domicile sans
   rue partait, et le technicien recevait un rendez-vous sans adresse.
   `BookingService::assertCustomerAndAddress()` est désormais l'autorité
   (identité complète, e-mail et téléphone plausibles, adresse exigée dès
   qu'une ligne est `onsite`), et renvoie des clés `customer.*` / `address.*`.
   Le widget applique les **mêmes** règles avant d'appeler l'API, pour éviter
   l'aller-retour, jamais à la place du serveur.
   Testé sans base : `tests/Booking/CustomerValidationTest.php`.
2. **Les erreurs étaient globales.** Un bandeau « Merci de compléter vos
   coordonnées » laissait le client chercher le champ fautif. Chaque champ porte
   maintenant son message sous lui (`aria-invalid`, `aria-describedby`), la page
   défile sur le premier et lui donne le focus ; le bandeau ne dit plus que
   « corrigez les champs signalés » et est annoncé (`role="alert"`).
   Les erreurs renvoyées par l'API (`fields`) sont replacées sur les mêmes
   champs, étape Coordonnées **ou** Questions selon la clé.
3. **L'étape « aucune disponibilité » était une impasse.** Le client voyait une
   liste vide et abandonnait. Il a désormais deux sorties : chercher plus loin
   (devenu depuis la navigation du calendrier, voir plus bas) et *Être
   rappelé*, qui crée une vraie commande **sans créneau** (statut `pending`, événement `booking_created` au lieu de la
   confirmation et des rappels — programmer un rappel sur un rendez-vous
   inexistant enverrait un SMS sans date). L'écran final le dit : « nous
   revenons vers vous avec une proposition de date », pas « c'est confirmé ».
   La réponse de l'API porte `scheduled: false`.
4. **Le honeypot n'existait pas.** Le widget envoyait `hp: ""` en dur ; aucun
   champ n'était dans le DOM, donc le contrôle serveur ne pouvait jamais se
   déclencher. Le champ est réel, hors parcours clavier et hors arbre
   d'accessibilité — et **invisible pour le navigateur**, pas seulement pour
   l'œil : voir ci-dessous, la première version de ce champ a bloqué de vrais
   clients.
5. **Le consentement portait sur des documents illisibles.** Le texte était
   brut, sans lien. Le réglage `company.terms_url` est exposé par
   `/api/catalog` et rendu en lien (`target="_blank"`, `rel="noopener"`).
6. **La barre de progression mentait.** Le dénominateur était figé à 9 : le
   client lisait « étape 6/9 » pour un parcours qui en compte 7 (« Confirmé »
   est un aboutissement, et « Questions » disparaît quand le formulaire publié
   n'a aucun champ). Elle compte les étapes réellement parcourues et affiche
   100 % sur la confirmation.
7. **Les champs du formulaire dynamique étaient inaccessibles** : pas de
   `label for`, des groupes de choix sans nom, des pastilles sans état. Chaque
   champ a un `label` associé, les groupes un `role="group"` nommé, les
   pastilles un `aria-pressed`.
8. **Le téléphone était optionnel** alors que le tunnel promet un SMS au départ
   du technicien. Il est obligatoire, avec un `inputmode="tel"`, et les
   écritures belges courantes sont acceptées (`+32 470 12 34 56`,
   `0470/12.34.56`, `0032470123456`, `04 70 12 34 56`).

Vérifié dans Chromium (viewport 390 px, API simulée) : nom accessible et
`inputmode` sur chaque champ, honeypot hors écran, 7 erreurs placées avec focus
sur la première à la soumission à vide, message d'e-mail mal formé, lien CGV,
les deux sorties de l'étape créneau, et la demande de rappel qui aboutit à
l'écran « demande enregistrée » avec `slots: {}`.

### Calendrier de l'étape Rendez-vous

Le client navigue de mois en mois, aussi loin que l'agenda est ouvert.
Auparavant : une bande horizontale des dates d'une fenêtre glissante de 30
jours, élargissable à 60 puis 90 par un bouton « Chercher plus loin », puis
plus rien. Qui voulait réserver en mars depuis novembre n'avait aucun moyen
d'y arriver.

- **Une requête par mois affiché.** `POST /api/availability` accepte
  `month` (`AAAA-MM`) et renvoie `window: { month, first_month, last_month }`.
  Les bornes viennent du serveur : l'interface n'a aucune règle d'horizon en
  dur, elle active ou grise les flèches d'après ce qu'il lui dit. `days` reste
  accepté pour un appelant qui ne navigue pas par mois.
- **`Keepnew\Support\MonthWindow`** calcule la fenêtre, isolée et testée
  (`tests/Support/MonthWindowTest.php`, pur, sans base) : un mois « belge » ne
  commence pas à minuit UTC et le décalage change deux fois par an ; le mois
  courant ne doit jamais proposer d'heures déjà passées ; un mois hors période
  est **ramené** dans la période plutôt que refusé, parce qu'un client dans un
  autre fuseau peut demander le mois d'à côté en toute bonne foi. C'est le
  mois renvoyé qui fait foi.
- **Horizon : 12 mois** (`MonthWindow::MAX_MONTHS_AHEAD`). Cette borne existe
  pour qu'une requête forgée ne fasse pas calculer l'agenda sur dix ans, pas
  pour limiter le client.
- **Semaine commençant le lundi**, jours fermés barrés (et pas seulement
  pâles : la couleur seule ne suffit pas), jour ouvert = cible de 44 px,
  `aria-pressed` sur le jour retenu, flèches nommées pour les lecteurs d'écran.
- **Arrivée sur un mois vide** (fin de mois, agenda fermé) : le tunnel avance
  tout seul jusqu'au premier mois qui a quelque chose à offrir, au plus trois
  fois. Dès que le client a tourné une page lui-même, on respecte son choix.
- **Choix retenu dans un autre mois** : le calendrier affiché ne montre alors
  aucune sélection alors que « Continuer » fonctionne — une ambiguïté que la
  navigation par mois introduit. Une ligne le rappelle : « Créneau retenu :
  17/11/2026 11:30 ». Le créneau est aussi enregistré immédiatement, donc il
  survit à un rechargement.
- **Deux rendus concurrents du même panneau** (le panneau est redessiné quand
  le catalogue arrive, alors qu'un chargement de disponibilité est déjà en
  vol) : c'était la réponse la plus lente qui gagnait le DOM, donc parfois le
  mauvais mois. Seul le dernier chargement demandé a le droit d'écrire.

La sortie de secours (« Être rappelé ») reste sur un mois vide ; « chercher
plus loin », c'est désormais le calendrier lui-même.

### Le honeypot qui refusait de vrais clients

Signalé en production : « Requête rejetée. » au moment de confirmer, sans
recours. C'est le message du honeypot — le serveur prenait le client pour un
robot. Trois défauts se cumulaient, tous dans la première version du champ.

- **Un champ hors écran reste un champ affiché.** Il était masqué par
  `position:absolute; left:-9999px`, ce qui ne le retire ni du rendu ni du
  remplissage automatique : le navigateur le voyait en 26 × 48 px. Il est
  désormais en `display:none`, que le remplissage automatique de Chrome et de
  Safari, comme les gestionnaires de mots de passe, ignorent — alors qu'un
  script qui poste tous les `<input>` d'un formulaire le remplit toujours,
  c'est-à-dire exactement la population visée.
- **Son nom le désignait au remplissage automatique.** `company_website`
  contient « company », que l'heuristique d'adresse de Chrome associe à
  `organization`. Le client qui remplissait ses coordonnées d'un clic faisait
  remplir le piège par son propre navigateur. Le champ s'appelle maintenant
  `subject_line`, hors de la taxonomie d'adresse.
- **L'erreur était définitive.** La valeur du piège était recopiée dans
  `state`, donc dans `localStorage` : une fois posée, elle survivait au
  rechargement et condamnait le client pour toujours. Elle est maintenant lue
  dans le champ vivant au moment de l'envoi, jamais mémorisée ; une session
  déjà bloquée est purgée au chargement.

Un quatrième défaut a été trouvé en cherchant le premier : six rappels
asynchrones redessinaient **l'étape courante** (`state.step`) dans **leur
propre panneau**. À la reprise d'une session (`replaySession()` reconstruit
tous les panneaux d'un coup), le catalogue arrivait alors que l'étape courante
était déjà « Coordonnées » : le formulaire de coordonnées était rendu dans le
panneau « Quoi ». D'où, en remontant le tunnel, un second formulaire identique,
des `id` en double — et un second champ-piège. Un panneau ne redessine
désormais que sa propre étape (`body.dataset.step`).

Vérifié dans Chromium, trois parcours complets : un vrai client (`hp: ""` →
201, écran de confirmation), un robot qui poste tous les champs (`hp` rempli →
400 « Requête rejetée. », le piège fait toujours son travail), et une session
déjà bloquée par l'ancienne version (repart et aboutit). Plus, sur la page : un
seul piège, un seul formulaire de coordonnées, aucun `id` en double.

## Validation réelle (navigateur, Playwright + Chromium)

Parcours complet piloté en viewport mobile (390 px) contre le serveur + la base
réelle :

| Étape | Résultat |
|---|---|
| Isolation Shadow DOM | carte à 16 px malgré le style hôte à 40 px |
| Où → Quoi → Détails | ok, **prix live 95,59 € TVAC** (canapé 3 places) |
| Panier | ligne + badge mode + total, panier collant |
| Questions → Coordonnées | ok |
| Rendez-vous | **26 créneaux** rendus, sélection |
| Récapitulatif → Confirmé | **réservation KN-2026-000001 créée**, statut confirmé |
| Erreurs console | **aucune** |

La réservation est bien persistée (client, commande, job planifié) — le tunnel
produit une vraie commande via l'API.

## Fichiers

- `public/widget.js` — le widget autonome (funnel + devis vivant + CSS scopé).
- `public/widget-demo.html` — page hôte de démonstration (style agressif pour
  prouver l'isolation).

## Ce que la Phase 6 laisse aux phases suivantes

Tracking GA4 / Meta CAPI (Phase 11), géocodage d'adresse et bouton « utiliser ma
position », échelle de salissure en photos et cartes visuelles illustrées
(assets), pose explicite du hold pendant la sélection de créneau, et le form
builder dynamique (Phase 8) qui remplacera l'intake codé en dur.
