# Keepnew Booking — Checklist QA

À dérouler avant chaque mise en production.

## Parcours de réservation (bout en bout)

- [ ] Code postal en zone → mode domicile → créneaux proposés.
- [ ] Code postal hors zone → message « hors zone », pas de créneaux.
- [ ] Choix service (cartes), configuration (variante + extras) → **prix live**.
- [ ] Ajout au panier ; ajout d'une 2ᵉ prestation → **remise cumul** affichée.
- [ ] Panier mixte domicile + atelier → deux blocs, deux créneaux.
- [ ] Questions d'intake (formulaire dynamique) ; champs requis bloquants.
- [ ] Coordonnées + consentement RGPD obligatoire.
- [ ] Choix de créneau (domicile et/ou atelier).
- [ ] Récapitulatif + code promo + confirmation **sans paiement**.
- [ ] Écran de confirmation : référence, téléphone, « vous payez après ».
- [ ] Email de confirmation reçu (ou dans `storage/logs/mail.log`).

## Cas d'erreur

- [ ] Double-réservation du même créneau → refus (409) propre.
- [ ] Réponses de formulaire incomplètes → 422, champs signalés.
- [ ] Coupon invalide / sous le minimum → ignoré sans casse.
- [ ] Perte de session → panier et étape retrouvés (localStorage).

## Back-office

- [ ] Catalogue : créer / éditer / dupliquer / désactiver une prestation.
- [ ] Simulateur de prix cohérent avec le tunnel.
- [ ] Dispatch : réassignation drag & drop, trajet recalculé, conflit signalé.
- [ ] Fiche job : statut, note, photos, historique.
- [ ] Factures : génération (numérotation sans trou), UBL B2B, journal CSV.
- [ ] Form builder : brouillon → publication → reflété dans le widget.
- [ ] Rapports : CA, conversion, annulation, panier moyen, km.

## App technicien

- [ ] Login technicien → `/tech`, planning du jour.
- [ ] Pointage start/stop (géolocalisé) ; immuabilité (correction = nouvelle ligne).
- [ ] Statuts → notifications ; complétion → indemnité de mobilité.
- [ ] Photos avant/après (appareil photo) ; signature client.
- [ ] Encaissement espèces/Bancontact → commande payée.
- [ ] Installable (PWA), utilisable en mode dégradé.

## Responsive & accessibilité (WCAG 2.1 AA)

- [ ] iPhone SE (petit écran), Android milieu de gamme, une main.
- [ ] Cibles ≥ 48 px, focus visible, navigation clavier complète.
- [ ] Contraste texte ≥ 4.5:1 ; zoom 200 % sans scroll horizontal.
- [ ] Champs à ≥ 16 px (pas de zoom iOS), `inputmode`/`autocomplete`.
- [ ] `prefers-reduced-motion` respecté.
- [ ] Lecteur d'écran (NVDA / VoiceOver) sur le parcours complet.

## Performance

- [ ] Widget < 60 Ko gzip, chargé en async, pas de layout shift.
- [ ] LCP < 2,0 s en 4G, CLS < 0,05.
- [ ] Images AVIF/WebP, `width`/`height` explicites.

## Sécurité

- [ ] Seul `public/` exposé ; `src/`, `config/`, `storage/`, `.env` inaccessibles.
- [ ] CSRF sur tous les POST back-office ; rate limiting login/dispo/réservation.
- [ ] Sessions régénérées au login ; cookies HttpOnly/Secure/SameSite.
- [ ] Uploads : MIME réel vérifié, ré-encodage, hors webroot.
- [ ] SQL 100 % préparé ; pas de secret en dur.
- [ ] En-têtes CSP/HSTS/X-Frame (widget whitelisté).

## Conformité belge

- [ ] TVA 21 %, facture simplifiée < 250 € TVAC, numérotation sans trou.
- [ ] Journal des recettes exportable.
- [ ] UBL BIS 3.0 pour le B2B (Peppol).
- [ ] Pointage CP 121 immuable ; indemnité de mobilité paramétrable + export.
- [ ] RGPD : consentement horodaté/versionné, droit à l'effacement, rétention.
