<?php
/**
 * Vue : création / édition d'un compte utilisateur (réservé admin).
 * $data['user'] === null → création.
 * @var array<string,mixed> $data
 * @var callable $e
 */
$user = $data['user'];
$isNew = $user === null;
$roleLabels = [
    'admin' => 'Administrateur — accès total',
    'dispatcher' => 'Dispatcher — planning, clients, techniciens, ateliers',
    'technician' => 'Technicien — app terrain uniquement',
    'accountant' => 'Comptable — factures, rapports, clients',
];
$currentRole = $isNew ? 'dispatcher' : (string) $user['role'];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $isNew ? 'Nouveau compte' : 'Modifier le compte' ?> — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <?php include dirname(__DIR__) . '/_nav.php'; ?>

    <main class="kn-wrap kn-wrap-narrow">
        <p><a href="/admin/utilisateurs">← Utilisateurs</a></p>
        <h1><?= $isNew ? 'Nouveau compte' : $e(trim($user['first_name'] . ' ' . $user['last_name'])) ?></h1>

        <?php if (!empty($data['error'])): ?>
            <p class="kn-alert kn-alert-error"><?= $e($data['error']) ?></p>
        <?php endif; ?>

        <form method="post" action="<?= $isNew ? '/admin/utilisateurs' : '/admin/utilisateurs/' . (int) $user['id'] ?>" class="kn-card">
            <?= $data['csrf'] ?>

            <div class="kn-grid kn-grid-2">
                <div class="kn-field">
                    <label for="first_name">Prénom</label>
                    <input type="text" id="first_name" name="first_name" value="<?= $e($user['first_name'] ?? '') ?>" required>
                </div>
                <div class="kn-field">
                    <label for="last_name">Nom</label>
                    <input type="text" id="last_name" name="last_name" value="<?= $e($user['last_name'] ?? '') ?>" required>
                </div>
            </div>

            <div class="kn-field">
                <label for="email">Adresse e-mail</label>
                <input type="email" id="email" name="email" value="<?= $e($user['email'] ?? '') ?>" autocomplete="off" required>
            </div>

            <div class="kn-field">
                <label for="phone">Téléphone (optionnel)</label>
                <input type="text" id="phone" name="phone" value="<?= $e($user['phone'] ?? '') ?>" inputmode="tel">
            </div>

            <div class="kn-field">
                <label for="role">Rôle</label>
                <select id="role" name="role"<?= !empty($data['is_self']) ? ' disabled' : '' ?>>
                    <?php foreach ($data['roles'] as $r): ?>
                        <option value="<?= $e($r) ?>" <?= $r === $currentRole ? 'selected' : '' ?>><?= $e($roleLabels[$r] ?? $r) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($data['is_self'])): ?>
                    <input type="hidden" name="role" value="admin">
                    <p class="kn-muted" style="margin-top:6px;">Vous ne pouvez pas modifier votre propre rôle.</p>
                <?php endif; ?>
            </div>

            <?php $linked = $data['linked_technician'] ?? null; ?>
            <div class="kn-field" id="kn-tech-profile" <?= $currentRole === 'technician' ? '' : 'hidden' ?>>
                <label for="technician_profile">Fiche technicien (app terrain)</label>
                <?php if ($linked !== null): ?>
                    <p class="kn-alert kn-alert-ok" style="margin:0;">
                        Rattaché à la fiche
                        <a href="/admin/techniciens/<?= (int) $linked['id'] ?>"><?= $e(trim($linked['first_name'] . ' ' . $linked['last_name'])) ?></a>.
                        L'app terrain (<code>/tech</code>) est accessible avec ce compte.
                    </p>
                <?php else: ?>
                    <select id="technician_profile" name="technician_profile">
                        <option value="new" selected>Créer une fiche technicien et la rattacher</option>
                        <?php foreach ($data['unlinked_technicians'] as $t): ?>
                            <option value="<?= (int) $t['id'] ?>">
                                Rattacher la fiche existante : <?= $e(trim($t['first_name'] . ' ' . $t['last_name'])) ?><?= (int) $t['is_active'] === 1 ? '' : ' (inactive)' ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="0">Aucune fiche pour l'instant</option>
                    </select>
                    <p class="kn-muted" style="margin-top:6px;">
                        Un compte de rôle « technicien » <strong>sans fiche rattachée ne peut pas ouvrir l'app
                        terrain</strong> : il se connecte et reçoit « Compte technicien introuvable ». La fiche porte
                        les compétences, zones et disponibilités qui alimentent le planning.
                    </p>
                <?php endif; ?>
            </div>

            <div class="kn-field">
                <label for="password"><?= $isNew ? 'Mot de passe' : 'Nouveau mot de passe (laisser vide pour conserver)' ?></label>
                <input type="password" id="password" name="password" autocomplete="new-password"
                       minlength="8" <?= $isNew ? 'required' : '' ?>>
            </div>

            <?php if (empty($data['is_self'])): ?>
                <label class="kn-check">
                    <input type="checkbox" name="is_active" value="1" <?= ($isNew || (int) ($user['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                    Compte actif (peut se connecter)
                </label>
            <?php else: ?>
                <input type="hidden" name="is_active" value="1">
            <?php endif; ?>

            <button type="submit" class="kn-btn kn-btn-primary"><?= $isNew ? 'Créer le compte' : 'Enregistrer' ?></button>
        </form>

        <?php if (!$isNew && empty($data['is_self'])): ?>
            <form method="post" action="/admin/utilisateurs/<?= (int) $user['id'] ?>/supprimer" style="margin-top:12px;"
                  onsubmit="return confirm('Supprimer ce compte ? S\'il est lié à une fiche technicien, il sera désactivé plutôt que supprimé.');">
                <?= $data['csrf'] ?>
                <button type="submit" class="kn-btn kn-btn-danger">Supprimer le compte</button>
            </form>
        <?php endif; ?>
    </main>

    <script>
        // Le bloc « Fiche technicien » n'a de sens que pour le rôle technicien.
        (function () {
            var role = document.getElementById('role');
            var block = document.getElementById('kn-tech-profile');
            if (!role || !block) return;
            role.addEventListener('change', function () {
                block.hidden = role.value !== 'technician';
            });
        })();
    </script>
</body>
</html>
