<?php
/**
 * Vue : connexion back-office.
 * Variables ($data) : csrf (html), error (string|null).
 * $e() = fonction d'échappement fournie par le moteur de vues.
 *
 * @var array<string,mixed> $data
 * @var callable $e
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <main class="kn-wrap" style="max-width:420px;">
        <h1>Keepnew</h1>
        <p class="kn-muted">Espace d'administration</p>

        <?php if (!empty($data['expired'])): ?>
            <p class="kn-alert kn-alert-warning">Session expirée après 2 heures d'inactivité. Veuillez vous reconnecter.</p>
        <?php endif; ?>

        <?php if (!empty($data['error'])): ?>
            <p class="kn-alert kn-alert-error"><?= $e($data['error']) ?></p>
        <?php endif; ?>

        <form method="post" action="/admin/connexion" class="kn-card">
            <?= $data['csrf'] // champ caché déjà échappé ?>
            <div class="kn-field">
                <label for="email">Adresse email</label>
                <input type="email" id="email" name="email" autocomplete="username"
                       inputmode="email" required autofocus>
            </div>
            <div class="kn-field">
                <label for="password">Mot de passe</label>
                <input type="password" id="password" name="password"
                       autocomplete="current-password" required>
            </div>
            <button type="submit" class="kn-btn kn-btn-primary" style="width:100%;">Se connecter</button>
        </form>
    </main>
</body>
</html>
