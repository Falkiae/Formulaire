<?php
/**
 * Vue : liste des versions du formulaire.
 * @var array<string,mixed> $data
 * @var callable $e
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Formulaire — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <?php include dirname(__DIR__) . '/_nav.php'; ?>

    <main class="kn-wrap">
        <?php if (!empty($data['flash'])): ?><p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p><?php endif; ?>
        <h1>Formulaire dynamique</h1>
        <p class="kn-muted">Éditez un brouillon puis publiez-le : la version publiée devient le formulaire du tunnel.</p>

        <section class="kn-card">
            <div class="kn-table-wrap">
                <table class="kn-table">
                    <thead><tr><th>Version</th><th>Libellé</th><th>État</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($data['versions'] as $v): ?>
                            <tr>
                                <td>v<?= (int) $v['version'] ?></td>
                                <td><a href="/admin/formulaire/<?= (int) $v['id'] ?>"><?= $e($v['label']) ?></a></td>
                                <td><?= (int) $v['is_published'] === 1 ? '<span class="kn-badge kn-badge-onsite">publiée</span>' : '<span class="kn-badge kn-badge-off">brouillon</span>' ?></td>
                                <td><a href="/admin/formulaire/<?= (int) $v['id'] ?>">Éditer</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="kn-card" style="margin-top:24px;max-width:480px;">
            <h2>Nouveau brouillon</h2>
            <form method="post" action="/admin/formulaire">
                <?= $data['csrf'] ?>
                <div class="kn-field"><label>Libellé</label><input type="text" name="label" placeholder="Ex. Formulaire v2"></div>
                <button type="submit" class="kn-btn kn-btn-primary">Créer</button>
            </form>
        </section>
    </main>
</body>
</html>
