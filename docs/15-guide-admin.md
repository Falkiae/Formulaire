# Keepnew Booking — Guide d'utilisation du back-office

Accès : `/admin/connexion`.

## Navigation

Le menu compte **six entrées**, chacune ouvrant ses propres onglets juste sous
l'en-tête. Un groupe n'apparaît que si votre rôle donne accès à au moins un de
ses onglets — un admin voit les six, un dispatcher trois, un comptable trois.

| Entrée | Onglets |
|---|---|
| **Planning** | Jour · Semaine · Mois |
| **Clients** | Liste · Simulateur |
| **Services** | Catalogue · Extras |
| **Équipe** | Techniciens · Compétences · Zones · Ateliers |
| **Finances** | Factures · Rapports |
| **Réglages** | Général · Formulaire · Textes du tunnel · Notifications · Utilisateurs · Diagnostic |

Sous Planning, changer d'onglet **conserve la période et les filtres affichés** :
depuis la semaine du 3 août filtrée sur un technicien, « Mois » ouvre août avec
le même filtre, pas le mois courant.

## Catalogue (`/admin/catalogue`)

Le pilier, utilisable sans aide technique.

- **Catégories** : arborescence réordonnable (glisser-déposer), visibilité, slug.
- **Prestations** : créer, éditer (prix/durée de base, **prix et durée par mode**
  domicile/atelier), **dupliquer** en un clic, activer/désactiver (jamais
  supprimer si déjà commandé — désactivation automatique).
- **Variantes** : gabarits / places / dimensions, avec delta de prix/durée.
- **Extras** (`/admin/extras`) : catalogue central ; on crée l'extra une fois
  puis on le rattache aux prestations (surcharge de prix, exclusif ou cumulable).
- **Simulateur** (`/admin/simulateur`) : vérifier une configuration et son prix,
  détail ligne par ligne — idéal pour déboguer une grille.

## Dispatch (`/admin/dispatch`)

Agenda du jour par technicien. **Glisser-déposer** un rendez-vous d'une colonne à
l'autre pour le réassigner : le trajet est recalculé et un conflit est signalé.
Cliquer une carte ouvre la **fiche job** (statut, note, photos, historique).

## Clients (`/admin/clients`)

Liste avec LTV, fréquence et segment B2B/B2C ; fiche client (commandes, adresses,
notes).

## Ateliers (`/admin/ateliers`)

Adresse, postes de travail, fermetures exceptionnelles.

## Formulaire (`/admin/formulaire`)

Éditer un **brouillon** (champs, options, conditions SI/ALORS) puis **publier** :
la version publiée devient le formulaire du tunnel, sans redéploiement.

## Factures (`/admin/factures`)

Générer la facture d'une commande (numérotation séquentielle, simplifiée <250 €).
Télécharger l'**UBL** (B2B/Peppol). Exporter le **journal des recettes** (CSV).

## Rapports (`/admin/rapports`)

Chiffre d'affaires, panier moyen, taux de conversion et d'annulation ; CA par
prestation, technicien et mois.

## Modifier une commande déjà planifiée

Depuis la fiche d'un rendez-vous (Planning → cliquer un rendez-vous), le bloc
**Prestations** permet de corriger un prix ou une quantité, d'ajouter une
prestation du catalogue ou **sur mesure** (libellé et prix libres), d'en retirer
une, d'**ajouter ou retirer un extra** sur chaque prestation, et d'appliquer une
**remise** en euros ou en pourcentage. Sous-total, TVA
et total se recalculent à chaque modification.

Deux limites voulues : une commande **déjà facturée** n'est plus modifiable (la
raison s'affiche en clair — passez par un avoir), et on ne peut pas retirer la
dernière prestation (annulez la commande à la place).

Ajouter une prestation **allonge le rendez-vous**. Si la nouvelle durée
chevauche l'intervention suivante du technicien, un message vous le signale :
replanifiez depuis le même écran.

## App technicien (`/tech`)

Les comptes « technicien » sont redirigés vers leur app terrain : planning,
pointage, statuts, photos, signature, encaissement.

**Pour qu'un technicien puisse se connecter, il lui faut deux choses :** un
compte de rôle « technicien » *et* une fiche technicien rattachée à ce compte.
Le plus simple est de partir de *Utilisateurs → + Nouveau compte* : choisissez
le rôle « Technicien », le bloc « Fiche technicien » apparaît et crée la fiche
automatiquement. Si la fiche existe déjà, sélectionnez-la dans la même liste.

Un compte sans fiche est signalé par « ⚠ sans fiche technicien » dans la liste
des utilisateurs, et sa connexion est refusée avec un message explicite.

Pensez ensuite à compléter, sur la fiche (*Techniciens*), ses **compétences**,
ses **zones** et ses **disponibilités** : sans elles, le moteur ne lui attribue
aucun rendez-vous et son planning reste vide.

## Réglages

Les barèmes et seuils (TVA, remise cumul, délais de
réservation, fournisseurs) vivent dans la table `settings` — jamais en dur.
