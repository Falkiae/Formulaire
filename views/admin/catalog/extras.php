<?php
/**
 * Vue : catalogue central des extras (mutualisés).
 *
 * @var array<string,mixed> $data
 * @var callable $e
 */
$centsToEuros = static fn (int $c): string => number_format($c / 100, 2, ',', ' ');
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Extras — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <header class="kn-header">
        <strong>Keepnew · back-office</strong>
        <nav>
            <a href="/admin/catalogue">Catalogue</a>
            <a href="/admin/extras" aria-current="page">Extras</a>
            <a href="/admin/simulateur">Simulateur</a>
            <a href="/admin/deconnexion">Déconnexion</a>
        </nav>
    </header>

    <main class="kn-wrap">
        <?php if (!empty($data['flash'])): ?>
            <p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p>
        <?php endif; ?>

        <h1>Extras</h1>
        <p class="kn-muted">Catalogue central : un extra est créé une fois, puis rattaché à plusieurs prestations depuis leur fiche.</p>

        <section class="kn-card">
            <table class="kn-table">
                <thead>
                    <tr><th>Extra</th><th class="kn-num">Prix défaut</th><th class="kn-num">Durée</th><th>État</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($data['extras'] as $x): ?>
                        <tr>
                            <td>
                                <form method="post" action="/admin/extras/<?= (int) $x['id'] ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                                    <?= $data['csrf'] ?>
                                    <input type="text" name="label" value="<?= $e($x['label']) ?>" style="max-width:220px;">
                                    <input type="text" name="default_price" value="<?= $e($centsToEuros((int) $x['default_price_cents'])) ?>" inputmode="decimal" style="max-width:90px;" aria-label="Prix">
                                    <input type="number" name="default_duration" value="<?= (int) $x['default_duration_min'] ?>" min="0" style="max-width:80px;" aria-label="Durée">
                                    <label style="display:inline-flex;align-items:center;gap:4px;margin:0;color:var(--kn-ink);">
                                        <input type="checkbox" name="is_active" value="1" <?= ((int) $x['is_active'] === 1) ? 'checked' : '' ?> style="width:auto;min-height:auto;"> actif
                                    </label>
                                    <button type="submit" class="kn-btn kn-btn-ghost" style="min-height:36px;padding:0 12px;">Enregistrer</button>
                                </form>
                            </td>
                            <td class="kn-num"><?= $e($centsToEuros((int) $x['default_price_cents'])) ?> €</td>
                            <td class="kn-num"><?= (int) $x['default_duration_min'] ?> min</td>
                            <td><?= ((int) $x['is_active'] === 1) ? 'actif' : '<span class="kn-muted">inactif</span>' ?></td>
                            <td>
                                <form method="post" action="/admin/extras/<?= (int) $x['id'] ?>/supprimer" onsubmit="return confirm('Supprimer cet extra ?');">
                                    <?= $data['csrf'] ?>
                                    <button type="submit" class="kn-btn kn-btn-ghost" style="min-height:36px;padding:0 12px;color:var(--kn-alert);">Supprimer</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="kn-card" style="margin-top:24px;max-width:560px;">
            <h2>Nouvel extra</h2>
            <form method="post" action="/admin/extras">
                <?= $data['csrf'] ?>
                <div class="kn-field">
                    <label for="label">Libellé</label>
                    <input type="text" id="label" name="label" required>
                </div>
                <div class="kn-field">
                    <label for="description">Description</label>
                    <input type="text" id="description" name="description">
                </div>
                <div class="kn-grid kn-grid-2">
                    <div class="kn-field">
                        <label for="default_price">Prix par défaut (€)</label>
                        <input type="text" id="default_price" name="default_price" inputmode="decimal" value="0">
                    </div>
                    <div class="kn-field">
                        <label for="default_duration">Durée par défaut (min)</label>
                        <input type="number" id="default_duration" name="default_duration" min="0" value="0">
                    </div>
                </div>
                <button type="submit" class="kn-btn kn-btn-primary">Créer l'extra</button>
            </form>
        </section>
    </main>
</body>
</html>
