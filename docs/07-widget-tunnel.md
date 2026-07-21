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
7. **Rendez-vous** — `POST /api/availability` → bande de dates défilante + liste
   de créneaux ; un créneau par job (domicile et/ou atelier).
8. **Récapitulatif** — devis final, code promo, « Confirmer la demande ».
9. **Confirmé** — référence, rappel « vous payez après l'intervention »,
   téléphone visible.

## Détails de conception (conformes au brief UX)

- **Mobile-first**, cibles ≥ 48 px, une décision par écran.
- **Barre de progression nommée** + **pastilles cliquables** des choix déjà faits
  (retour direct à une étape).
- **Panier collant** en bas (nombre de prestations + total), toujours visible.
- **Réassurance contextuelle** : une phrase par étape, jamais un bandeau permanent.
- **Voix** : vouvoiement, libellés d'action explicites (« Ajouter au panier »,
  « Choisir un créneau », « Confirmer la demande »), zéro jargon.
- **Reprise de session** via `localStorage` (le client retrouve son panier et son
  étape).
- **Accessibilité** : `label` réels, `inputmode`/`autocomplete`, focus visible,
  taille de police ≥ 16 px (anti-zoom iOS), `prefers-reduced-motion` respecté.

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
