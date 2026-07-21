<?php
/**
 * Vue : ateliers (horaires, postes, fermetures).
 * @var array<string,mixed> $data
 * @var callable $e
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ateliers — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <header class="kn-header">
        <strong>Keepnew · back-office</strong>
        <nav>
            <a href="/admin/dispatch">Dispatch</a>
            <a href="/admin/clients">Clients</a>
            <a href="/admin/ateliers" aria-current="page">Ateliers</a>
            <a href="/admin/catalogue">Catalogue</a>
            <a href="/admin/deconnexion">Déconnexion</a>
        </nav>
    </header>

    <main class="kn-wrap">
        <?php if (!empty($data['flash'])): ?><p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p><?php endif; ?>
        <h1>Ateliers</h1>

        <?php foreach ($data['locations'] as $loc): ?>
            <section class="kn-card" style="margin-bottom:24px;">
                <h2><?= $e($loc['name']) ?> <?php if ((int) $loc['is_active'] !== 1): ?><span class="kn-badge kn-badge-off">inactif</span><?php endif; ?></h2>
                <p class="kn-muted"><?= $e(trim(($loc['address_line'] ?? '') . ', ' . ($loc['postal_code'] ?? '') . ' ' . ($loc['city'] ?? ''))) ?></p>

                <h3 class="kn-h3">Postes de travail</h3>
                <ul>
                    <?php foreach ($loc['bays'] as $b): ?>
                        <li><?= $e($b['name']) ?> <?php if ((int) $b['is_active'] !== 1): ?><span class="kn-muted">(inactif)</span><?php endif; ?></li>
                    <?php endforeach; ?>
                    <?php if ($loc['bays'] === []): ?><li class="kn-muted">Aucun poste.</li><?php endif; ?>
                </ul>
                <form method="post" action="/admin/ateliers/<?= (int) $loc['id'] ?>/poste" style="display:flex;gap:8px;align-items:end;max-width:420px;">
                    <?= $data['csrf'] ?>
                    <div class="kn-field" style="margin:0;flex:1;"><label>Nouveau poste</label><input type="text" name="name" placeholder="Ex. Poste 3"></div>
                    <button type="submit" class="kn-btn kn-btn-ghost">Ajouter</button>
                </form>

                <h3 class="kn-h3" style="margin-top:16px;">Fermetures exceptionnelles</h3>
                <ul>
                    <?php foreach ($loc['closures'] as $cl): ?>
                        <li><?= $e(substr((string) $cl['starts_at'], 0, 10)) ?> → <?= $e(substr((string) $cl['ends_at'], 0, 10)) ?> <?= $e($cl['reason'] ?? '') ?></li>
                    <?php endforeach; ?>
                    <?php if ($loc['closures'] === []): ?><li class="kn-muted">Aucune.</li><?php endif; ?>
                </ul>
                <form method="post" action="/admin/ateliers/<?= (int) $loc['id'] ?>/fermeture" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;max-width:560px;">
                    <?= $data['csrf'] ?>
                    <div class="kn-field" style="margin:0;"><label>Du</label><input type="date" name="from"></div>
                    <div class="kn-field" style="margin:0;"><label>Au</label><input type="date" name="to"></div>
                    <div class="kn-field" style="margin:0;flex:1;"><label>Motif</label><input type="text" name="reason"></div>
                    <button type="submit" class="kn-btn kn-btn-ghost">Enregistrer</button>
                </form>
            </section>
        <?php endforeach; ?>
    </main>
</body>
</html>
