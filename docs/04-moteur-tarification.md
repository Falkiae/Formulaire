# Keepnew Booking — Phase 3 : moteur de tarification & durée du panier

Le calcul par ligne (Phase 2, `PriceCalculator`) est étendu à un **panier
multi-lignes** avec remise cumul, coupons, supplément déplacement et TVA.

## Composants (`src/Pricing/`, `src/Catalog/`)

- **`CartPricer`** (pur, testable) — agrège les lignes et applique, dans l'ordre :
  1. calcul de chaque ligne (`PriceCalculator`) ;
  2. **remise cumul** : −X % à partir de la Nᵉ prestation **à la même adresse**,
     les prestations étant comptées à l'unité (quantité incluse) et regroupées
     par `address_key` ;
  3. **décote de cumul sur la durée** (deux prestations au même endroit prennent
     moins de temps : le setup n'est fait qu'une fois) ;
  4. **coupon** (fixe ou pourcentage), sur le sous-total après remise cumul,
     plafonné à la base et soumis au montant minimum ;
  5. **supplément déplacement** (hors zone), taxable ;
  6. **TVA** sur la base nette → total TVAC.
- **`PricingRules`** — paramètres (remise cumul, min items, décote durée, TVA)
  chargés depuis la configuration, jamais en dur.
- **`CartQuote`** — devis complet : lignes détaillées + totaux (sous-total,
  remise cumul, coupon, supplément, net HTVA, TVA, TVAC, durée).
- **`LineResolver`** — résolution catalogue → entrée du calculateur, **mutualisée**
  entre le simulateur (une ligne) et le panier (n lignes) : mêmes prix partout.
- **`CartPricingService`** — charge les règles (`settings`, `duration_rules`),
  résout chaque ligne, valide le coupon en base (fenêtre, quota) et pilote
  `CartPricer`.

## Regroupement par adresse & commande mixte

L'`address_key` regroupe les prestations pour la remise cumul. Par défaut on
regroupe par **mode** : les lignes `onsite` partagent l'adresse du client, les
lignes `workshop` sont à l'atelier. Une commande mixte domicile + atelier forme
donc **deux groupes distincts** → pas de remise cumul entre eux, ce qui est
cohérent avec « à la même adresse ». À l'intérieur d'un groupe d'au moins
`multiItemMinItems` prestations, chaque prestation à partir de la 2ᵉ est remisée.

## Endpoint de vérification

`POST /admin/simulateur/panier` (authentifié, CSRF) —
`{ lines:[{service_id, mode, variant_id?, extra_ids?, quantity?, address_key?}],
coupon_code?, travel_surcharge_cents? }` → devis complet formaté.

## Tests

`tests/Pricing/CartPricerTest.php` — 9 scénarios : cumul même adresse, adresses
distinctes (pas de cumul), quantité comptée en prestations, coupon pourcentage,
coupon sous le minimum ignoré, coupon plafonné à la base, supplément déplacement
taxé, remise à partir de la 3ᵉ, combinaison cumul + coupon.

## Validation réelle (MariaDB 10.11 + PHP 8.4)

- Simulateur une ligne inchangé (canapé 4 places + ozone = 143,99 € TVAC).
- Panier 2 canapés même adresse : sous-total 148 € → remise cumul −6,90 € → net
  141,10 € → TVA 29,63 € → **TVAC 170,73 €**, durée 133 min (140 − décote 7 min).
- Panier mixte domicile + atelier + coupon BIENVENUE10 : sous-total 138 €, cumul
  0 € (groupes distincts), coupon −13,80 €, **TVAC 150,28 €**.

Cohérent avec les tests unitaires. Règles lues depuis `settings` et
`duration_rules`, coupon validé en base.

## Ce que la Phase 3 laisse aux phases suivantes

La persistance du panier (`carts`, `cart_items`) et le tunnel public relèvent des
phases 5 et 6. Le supplément déplacement réel provient de la résolution de zone
(Phase 4) ; ici il est un paramètre d'entrée taxable.
