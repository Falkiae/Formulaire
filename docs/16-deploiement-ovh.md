# Mettre Keepnew Booking en ligne sur un hébergement mutualisé OVH

Guide pas à pas, pensé pour quelqu'un qui n'a jamais déployé de site PHP. On part
de zéro. Comptez **1 à 2 heures** la première fois.

> **Vocabulaire express**
> - **Hébergement mutualisé** : un serveur OVH partagé où vivront vos fichiers.
> - **FTP/SFTP** : le moyen de copier vos fichiers sur le serveur (comme une clé
>   USB à distance). On utilise le logiciel gratuit **FileZilla**.
> - **phpMyAdmin** : une page web fournie par OVH pour gérer la base de données.
> - **Manager / Espace client OVH** : le tableau de bord OVH sur ovh.com.
> - **Sous-domaine** : `booking.keepnew.be` est un sous-domaine de `keepnew.be`.

---

## Ce dont vous avez besoin avant de commencer

1. Un **hébergement web OVH** (offre **Perso**, **Pro** ou **Performance** —
   évitez l'offre « Starter/Kimsufi » sans base MySQL). Ces offres incluent PHP,
   MySQL et le SSH.
2. Le **nom de domaine** `keepnew.be` géré chez OVH (ou pointant vers l'hébergement).
3. Les **fichiers du projet** (ce dépôt) sur votre ordinateur.
4. **FileZilla** installé (gratuit, `filezilla-project.org`).
5. 30 minutes de tranquillité.

L'idée générale : le **site vitrine** `keepnew.be` reste tel quel. On installe
l'application sur un **sous-domaine** `booking.keepnew.be`, puis on colle un petit
bout de code sur le site vitrine pour afficher le module de réservation.

---

## Étape 1 — Créer le sous-domaine booking.keepnew.be

1. Connectez-vous au **Manager OVH** → **Web Cloud** → **Noms de domaine** →
   `keepnew.be` → onglet **Zone DNS**.
2. Le plus simple : dans **Hébergements** → votre hébergement → onglet
   **Multisite** → **Ajouter un domaine ou sous-domaine**.
3. Saisissez `booking.keepnew.be`. OVH propose de créer le sous-domaine
   automatiquement (répondez oui).
4. **Point crucial — le dossier racine** : OVH demande « quel dossier de votre
   espace doit être affiché ? ». Indiquez **`keepnew/public`** (on créera ce
   dossier à l'étape 3). C'est ce qui fait que seul le dossier `public/` est
   visible sur le web — le reste (code, mots de passe) reste caché.
5. Cochez, si proposé, **« activer le SSL »** (on y revient à l'étape 8).

> Si votre offre n'affiche pas l'option « dossier » : voir l'**Annexe A** en bas
> (méthode de repli avec le `.htaccess` déjà fourni).

---

## Étape 2 — Choisir PHP 8.2 (ou plus récent)

L'application a besoin de **PHP 8.2 minimum**.

1. Sur votre ordinateur, créez un fichier texte nommé **`.ovhconfig`** (attention
   au point au début, et **pas** d'extension `.txt`) avec exactement ce contenu :

   ```
   app.engine=php
   app.engine.version=8.2
   ```

2. Ce fichier ira à la **racine de votre espace FTP** (on l'envoie à l'étape 4).

> Alternative : certains hébergements permettent de choisir la version PHP
> directement dans le Manager (**Hébergement → onglet « Informations générales »
> → « Version de PHP »**). Les deux méthodes marchent ; `.ovhconfig` est la plus
> fiable.

---

## Étape 3 — Créer la base de données MySQL

1. Manager OVH → votre **Hébergement** → onglet **Bases de données** →
   **Créer une base de données**.
2. Choisissez **MySQL** (dernière version proposée), un mot de passe solide.
3. OVH vous affiche alors **4 informations à noter précieusement** :
   - **Serveur** (l'adresse de la base), par ex. `keepnewxxxx.mysql.db` — ⚠️ **ce
     n'est PAS `localhost`** sur OVH, c'est une adresse spécifique ;
   - **Nom** de la base, par ex. `keepnewxxxx` ;
   - **Utilisateur**, souvent identique au nom ;
   - **Mot de passe** (celui que vous venez de choisir).

Gardez ces 4 valeurs sous la main : elles vont dans le fichier `.env` (étape 5).

---

## Étape 4 — Envoyer les fichiers avec FileZilla

1. Manager OVH → **Hébergement** → onglet **FTP-SSH** → notez l'**hôte FTP** (par
   ex. `ftp.cluster0xx.hosting.ovh.net`), votre **login** et créez/récupérez le
   **mot de passe FTP**.
2. Ouvrez **FileZilla** → en haut, renseignez :
   - **Hôte** : `sftp://` suivi de l'hôte FTP (le SFTP est plus sûr) ;
   - **Identifiant** et **Mot de passe** ;
   - **Port** : `22` (SFTP).
   Cliquez **Connexion rapide**.
3. À droite (le serveur), vous voyez généralement un dossier **`www`** (le site
   vitrine actuel). **Ne le touchez pas.**
4. **Créez un dossier `keepnew`** à côté de `www` (clic droit → Créer un dossier).
5. Depuis votre ordinateur (partie gauche), **glissez tout le contenu du projet**
   dans ce dossier `keepnew`. Vous devez y retrouver : `public/`, `src/`,
   `config/`, `database/`, `cron/`, `bin/`, `views/`, `storage/`, `composer.json`,
   etc.
6. Placez aussi le fichier **`.ovhconfig`** (étape 2) à la **racine** de l'espace
   (au même niveau que `www` et `keepnew`).

> Les fichiers commençant par un point (`.ovhconfig`, `.htaccess`, `.env`) sont
> parfois masqués. Dans FileZilla : menu **Serveur → Forcer l'affichage des
> fichiers cachés**.

À ce stade, l'arborescence sur le serveur ressemble à :

```
/ (racine FTP)
├── .ovhconfig
├── www/                ← votre site vitrine actuel (intact)
└── keepnew/            ← l'application
    ├── public/         ← SEUL dossier visible sur booking.keepnew.be
    ├── src/  config/  database/  cron/  bin/  views/  storage/
    └── composer.json
```

---

## Étape 5 — Créer le fichier de configuration `.env`

1. Sur votre ordinateur, faites une copie de **`.env.example`** et renommez-la
   **`.env`**.
2. Ouvrez-la avec un éditeur de texte simple (Bloc-notes, TextEdit) et remplissez
   au minimum le bloc base de données avec les valeurs de l'étape 3 :

   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://booking.keepnew.be
   APP_KEY=REMPLACER_PAR_UNE_LONGUE_CHAINE_ALEATOIRE

   DB_HOST=keepnewxxxx.mysql.db      ← le "Serveur" OVH, PAS localhost
   DB_PORT=3306
   DB_NAME=keepnewxxxx
   DB_USER=keepnewxxxx
   DB_PASSWORD=le_mot_de_passe_de_la_base
   DB_CHARSET=utf8mb4
   ```

3. Pour **`APP_KEY`**, mettez une longue suite de caractères au hasard (40+).
   Astuce : sur un site comme « random.org » ou tapez n'importe quoi de long et
   d'unique. Cette clé sert à signer des jetons de sécurité.
4. Laissez le reste tel quel pour démarrer (email, SMS, tracking sont **inactifs
   proprement** tant qu'ils ne sont pas configurés — voir étape 9).
5. Envoyez ce fichier `.env` dans le dossier **`keepnew/`** via FileZilla
   (donc **`keepnew/.env`**, à côté de `src/` — surtout **pas** dans `public/`).

---

## Étape 6 — Importer la base de données (les tables)

1. Manager OVH → **Hébergement** → **Bases de données** → cliquez sur
   **phpMyAdmin** à côté de votre base. Connectez-vous (utilisateur + mot de passe
   de l'étape 3).
2. À gauche, cliquez sur **le nom de votre base** pour la sélectionner.
3. Onglet **Importer** → **Choisir un fichier** → sélectionnez
   `database/schema.sql` (sur votre ordinateur) → **Exécuter**. Cela crée les 61
   tables.
4. Recommencez l'import avec **`database/seed.sql`** : cela ajoute le catalogue de
   démonstration (prestations, 5 techniciens, 1 atelier, zones de Liège) et un
   compte administrateur de démo.

> Les deux fichiers sont petits (< 100 Ko), l'import passe sans souci malgré les
> limites de phpMyAdmin.

---

## Étape 7 — Installer PHPMailer (pour les vrais e-mails) — optionnel mais recommandé

Sans cette étape, l'application **fonctionne quand même** : les e-mails sont
simplement écrits dans un fichier journal au lieu d'être envoyés. Pour envoyer de
vrais e-mails, il faut la librairie **PHPMailer**, installée avec **Composer**.

**Si vous avez le SSH (offres Perso et plus) :**

1. Manager OVH → **Hébergement** → **FTP-SSH** → activez le **SSH** et notez les
   identifiants.
2. Sur votre ordinateur, ouvrez un terminal et connectez-vous :
   `ssh votre-login@ftp.cluster0xx.hosting.ovh.net`
3. Placez-vous dans le dossier puis lancez Composer :
   ```
   cd keepnew
   php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
   php composer-setup.php
   php composer.phar install --no-dev --optimize-autoloader
   ```

**Si vous n'avez pas le SSH :** installez Composer sur votre ordinateur
(`getcomposer.org`), lancez `composer install --no-dev` dans le projet en local,
puis envoyez le dossier **`vendor/`** ainsi créé via FileZilla dans `keepnew/`.

> Vous pouvez tout à fait démarrer **sans** cette étape et l'ajouter plus tard.

---

## Étape 8 — Activer le HTTPS (cadenas)

1. Manager OVH → **Hébergement** → onglet **SSL** → **Demander un certificat SSL**
   (gratuit, Let's Encrypt). L'activation prend quelques minutes à quelques heures.
2. Une fois actif, le site est accessible en `https://`. Le projet force déjà les
   bonnes en-têtes de sécurité ; vous pouvez ajouter la redirection automatique
   http→https en créant/complétant `keepnew/public/.htaccess` (le fichier existe
   déjà, ajoutez ces lignes en haut si besoin) :

   ```apache
   RewriteEngine On
   RewriteCond %{HTTPS} off
   RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
   ```

---

## Étape 9 — Programmer la tâche automatique (cron)

Certaines actions tournent en arrière-plan : envoi des rappels SMS/e-mail,
nettoyage des créneaux réservés temporairement, facturation des interventions
terminées.

1. Manager OVH → **Hébergement** → onglet **Tâches planifiées (Cron)** →
   **Ajouter une tâche planifiée**.
2. Renseignez :
   - **Chemin du script** : `keepnew/cron/cron.php`
   - **Langage** : **PHP**, version **8.2**
   - **Fréquence** : la plus fréquente disponible (idéalement **toutes les 5 ou
     10 minutes**). Si seule l'option « toutes les heures » existe, c'est
     acceptable — les rappels partiront dans l'heure.
   - **E-mail** : le vôtre, pour recevoir les éventuelles erreurs.
3. Validez.

---

## Étape 10 — Définir le mot de passe administrateur

Le seed installe un compte de démonstration
(`antoine@keepnew.be` / `keepnew-demo`). **Changez-le immédiatement.**

**Avec le SSH :**
```
cd keepnew
php bin/set-password.php antoine@keepnew.be "VotreMotDePasseSolide"
```

**Sans SSH :** créez une **tâche planifiée** ponctuelle (comme à l'étape 9) avec
le chemin `keepnew/bin/set-password.php` — mais OVH ne permet pas de passer des
arguments facilement. Le plus simple sans SSH : passez par **phpMyAdmin** et
demandez-moi de vous générer le « hash » de votre mot de passe (je vous fournis la
ligne SQL `UPDATE users SET password_hash='...' WHERE email='antoine@keepnew.be'`
à coller). Contactez-moi avec le mot de passe souhaité et je vous donne la ligne.

---

## Étape 11 — Vérifier que tout marche

Dans votre navigateur :

1. **`https://booking.keepnew.be/health`** → doit afficher un petit texte JSON
   avec `"status":"ok"`. ✅ Si oui, le serveur et la base sont bien connectés.
2. **`https://booking.keepnew.be/admin/connexion`** → connectez-vous avec le
   compte admin (nouveau mot de passe). Vous arrivez sur le back-office.
3. **`https://booking.keepnew.be/widget-demo.html`** → le tunnel de réservation de
   démonstration. Faites une réservation test de bout en bout.

> Une fois les tests faits, **supprimez `public/widget-demo.html`** (page de démo)
> via FileZilla.

---

## Étape 12 — Afficher le module sur keepnew.be

Sur la page de votre site vitrine où vous voulez le module de réservation
(dans WordPress : un bloc « HTML personnalisé » ; sinon dans le code de la page),
collez :

```html
<div id="keepnew-booking" data-api="https://booking.keepnew.be"></div>
<script src="https://booking.keepnew.be/widget.js" async></script>
```

C'est tout : le module s'affiche, isolé du style de votre site, et envoie les
réservations à votre application.

---

## Étape 13 — Réglages finaux (quand vous êtes prêt)

Tout se règle dans le back-office ou le `.env`, sans toucher au code :

- **Email réel** : renseignez `MAIL_HOST`, `MAIL_USER`, `MAIL_PASSWORD` dans
  `.env` (OVH fournit un serveur SMTP avec vos adresses e-mail OVH).
- **SMS** : `SMS_PROVIDER=twilio` (ou `brevo`) + les clés.
- **Cartographie** : mettez votre clé `ORS_API_KEY` (OpenRouteService, gratuit)
  pour des temps de trajet précis.
- **Catalogue, zones, horaires, techniciens** : tout se gère dans
  `/admin` (catalogue, ateliers, formulaire, etc.).
- **Supprimez le catalogue de démo** et saisissez vos vraies prestations.

---

## Dépannage (les erreurs les plus fréquentes)

| Symptôme | Cause probable | Solution |
|---|---|---|
| Page blanche ou « erreur 500 » | `.env` mal rempli, ou `DB_HOST` = `localhost` | Vérifiez les 4 infos base (étape 3). Sur OVH, l'hôte n'est **jamais** `localhost`. |
| `/health` affiche une erreur base | mauvais identifiants MySQL | Recopiez exactement Serveur/Nom/User/Mot de passe. |
| Le sous-domaine montre la liste des fichiers | le dossier racine n'est pas `keepnew/public` | Étape 1, point 4 : corrigez le dossier dans « Multisite ». |
| « 404 » sur toutes les pages sauf l'accueil | `mod_rewrite`/`.htaccess` non pris en compte | Vérifiez que `public/.htaccess` a bien été envoyé (fichiers cachés). |
| Les e-mails n'arrivent pas | PHPMailer/SMTP non configuré | Normal au départ (journalisés). Faites l'étape 7 + réglages SMTP. |
| PHP trop ancien | `.ovhconfig` absent | Étape 2 : placez `.ovhconfig` à la racine FTP. |

---

## Annexe A — Si vous ne pouvez pas définir le dossier `public`

Certaines configurations pointent forcément le sous-domaine vers un dossier fixe
(ex. `www/booking`). Dans ce cas :

1. Placez l'application dans `www/booking/` (au lieu de `keepnew/`).
2. Le projet fournit déjà un **`.htaccess` à sa racine** qui redirige vers
   `public/` et bloque l'accès à `src/`, `config/`, `.env`, `storage/`. Il prend
   le relais automatiquement.
3. Le sous-domaine `booking.keepnew.be` pointe alors sur `www/booking`, et tout
   fonctionne grâce à ce `.htaccess`.

C'est un peu moins propre (le code n'est protégé que par le `.htaccess`, pas par
l'emplacement), mais parfaitement fonctionnel sur OVH.

---

## Récapitulatif ultra-court

1. Sous-domaine `booking.keepnew.be` → dossier `keepnew/public` (Multisite).
2. `.ovhconfig` (PHP 8.2) à la racine FTP.
3. Créer la base MySQL, noter les 4 infos.
4. Envoyer le projet dans `keepnew/` via FileZilla.
5. Créer `keepnew/.env` avec les infos de la base.
6. Importer `schema.sql` puis `seed.sql` dans phpMyAdmin.
7. (Optionnel) Composer pour PHPMailer.
8. Activer le SSL.
9. Cron sur `keepnew/cron/cron.php` toutes les 5–10 min.
10. Changer le mot de passe admin.
11. Tester `/health`, `/admin`, une réservation.
12. Coller le snippet du widget sur keepnew.be.
