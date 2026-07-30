<?php
/**
 * Vue : liste des comptes utilisateurs (réservé admin).
 * @var array<string,mixed> $data
 * @var callable $e
 */
$roleLabels = [
    'admin' => 'Administrateur',
    'dispatcher' => 'Dispatcher',
    'technician' => 'Technicien',
    'accountant' => 'Comptable',
];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Utilisateurs — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <?php include dirname(__DIR__) . '/_nav.php'; ?>

    <main class="kn-wrap">
        <?php if (!empty($data['flash'])): ?><p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p><?php endif; ?>

        <div class="kn-daynav">
            <h1 style="margin:0;">Utilisateurs</h1>
            <a class="kn-btn kn-btn-primary kn-btn-sm" href="/admin/utilisateurs/nouveau">+ Nouveau compte</a>
        </div>
        <p class="kn-muted">Gérez les accès au back-office et à l'app technicien. Le rôle détermine les sections accessibles.</p>

        <section class="kn-card">
            <div class="kn-table-wrap">
                <table class="kn-table">
                    <thead>
                        <tr><th>Nom</th><th>E-mail</th><th>Rôle</th><th>État</th><th>Dernière connexion</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['users'] as $u): ?>
                            <tr>
                                <td><a href="/admin/utilisateurs/<?= (int) $u['id'] ?>"><?= $e(trim($u['first_name'] . ' ' . $u['last_name'])) ?></a></td>
                                <td class="kn-muted"><?= $e($u['email']) ?></td>
                                <td>
                                    <span class="kn-badge"><?= $e($roleLabels[$u['role']] ?? $u['role']) ?></span>
                                    <?php if ($u['role'] === 'technician' && empty($u['technician_id'])): ?>
                                        <br><a class="kn-badge kn-badge-off" href="/admin/utilisateurs/<?= (int) $u['id'] ?>"
                                               title="Sans fiche technicien rattachée, ce compte ne peut pas ouvrir l'app terrain.">
                                            ⚠ sans fiche technicien
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int) $u['is_active'] === 1): ?>
                                        <span class="kn-badge kn-badge-onsite">actif</span>
                                    <?php else: ?>
                                        <span class="kn-badge kn-badge-off">inactif</span>
                                    <?php endif; ?>
                                </td>
                                <td class="kn-muted"><?= $e($u['last_login_at'] ? substr((string) $u['last_login_at'], 0, 16) : '—') ?></td>
                                <td>
                                    <?php if ((int) $u['id'] !== (int) ($data['self_id'] ?? 0)): ?>
                                        <form method="post" action="/admin/utilisateurs/<?= (int) $u['id'] ?>/actif" style="display:inline;">
                                            <?= $data['csrf'] ?>
                                            <button type="submit" class="kn-btn kn-btn-ghost kn-btn-sm">
                                                <?= (int) $u['is_active'] === 1 ? 'Désactiver' : 'Réactiver' ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="kn-muted">vous</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($data['users'] === []): ?><tr><td colspan="6" class="kn-muted">Aucun compte.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
