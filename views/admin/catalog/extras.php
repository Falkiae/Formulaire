<?php
/**
 * Vue : catalogue central des extras (mutualisés).
 *
 * @var array<string,mixed> $data
 * @var callable $e
 */
$centsToEuros = static fn (int $c): string => number_format($c / 100, 2, ',', ' ');
$vatRateBp = (int) $data['vat_rate_bp'];
// Colonnes stockées en HT ; l'admin saisit et voit du TVAC ici (converti à
// l'affichage, reconverti en HT à l'enregistrement par ExtraController).
$tvacEuros = static fn (int $htCents): string =>
    $centsToEuros(\Keepnew\Support\Money::addVat($htCents, $vatRateBp));
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
    <?php include dirname(__DIR__) . '/_nav.php'; ?>

    <main class="kn-wrap">
        <?php if (!empty($data['flash'])): ?>
            <p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p>
        <?php endif; ?>

        <h1>Extras</h1>
        <p class="kn-muted">Catalogue central : un extra est créé une fois, puis rattaché à plusieurs prestations depuis leur fiche.</p>
        <p class="kn-muted" style="font-size:.85rem;">Prix TVA comprise (<?= number_format($vatRateBp / 100, 2, ',', ' ') ?> %) — le montant hors TVA est calculé automatiquement pour la facturation.</p>

        <section class="kn-card">
            <div class="kn-table-wrap">
                <table class="kn-table">
                    <thead>
                        <tr><th>Extra</th><th class="kn-num">Prix défaut (TVAC)</th><th class="kn-num">Durée</th><th>État</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['extras'] as $x): ?>
                            <tr>
                                <td>
                                    <form method="post" action="/admin/extras/<?= (int) $x['id'] ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;" enctype="multipart/form-data">
                                        <?= $data['csrf'] ?>
                                        <?php if (($x['image_path'] ?? '') !== ''): ?>
                                            <img src="/uploads/<?= $e($x['image_path']) ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:6px;">
                                        <?php endif; ?>
                                        <input type="text" name="label" value="<?= $e($x['label']) ?>" style="max-width:200px;">
                                        <input type="text" name="default_price" value="<?= $e($tvacEuros((int) $x['default_price_cents'])) ?>" inputmode="decimal" style="max-width:90px;" aria-label="Prix (TVAC)">
                                        <input type="number" name="default_duration" value="<?= (int) $x['default_duration_min'] ?>" min="0" style="max-width:80px;" aria-label="Durée">
                                        <label class="kn-check">
                                            <input type="checkbox" name="is_active" value="1" <?= ((int) $x['is_active'] === 1) ? 'checked' : '' ?>> actif
                                        </label>
                                        <input type="file" name="image" accept="image/jpeg,image/png,image/webp" style="max-width:170px;" aria-label="Image">
                                        <?php if (($x['image_path'] ?? '') !== ''): ?>
                                            <label class="kn-check"><input type="checkbox" name="remove_image" value="1"> retirer l'image</label>
                                        <?php endif; ?>
                                        <button type="submit" class="kn-btn kn-btn-ghost kn-btn-sm">Enregistrer</button>
                                    </form>
                                </td>
                                <td class="kn-num"><?= $e($tvacEuros((int) $x['default_price_cents'])) ?> €</td>
                                <td class="kn-num"><?= (int) $x['default_duration_min'] ?> min</td>
                                <td><?= ((int) $x['is_active'] === 1) ? 'actif' : '<span class="kn-muted">inactif</span>' ?></td>
                                <td>
                                    <form method="post" action="/admin/extras/<?= (int) $x['id'] ?>/supprimer" onsubmit="return confirm('Supprimer cet extra ?');">
                                        <?= $data['csrf'] ?>
                                        <button type="submit" class="kn-btn kn-btn-danger kn-btn-sm">Supprimer</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="kn-card" style="margin-top:24px;max-width:560px;">
            <h2>Nouvel extra</h2>
            <form method="post" action="/admin/extras" enctype="multipart/form-data">
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
                        <label for="default_price">Prix par défaut (€ TVAC)</label>
                        <input type="text" id="default_price" name="default_price" inputmode="decimal" value="0">
                    </div>
                    <div class="kn-field">
                        <label for="default_duration">Durée par défaut (min)</label>
                        <input type="number" id="default_duration" name="default_duration" min="0" value="0">
                    </div>
                </div>
                <div class="kn-field">
                    <label for="extra_image">Image (tunnel client)</label>
                    <input type="file" id="extra_image" name="image" accept="image/jpeg,image/png,image/webp">
                </div>
                <button type="submit" class="kn-btn kn-btn-primary">Créer l'extra</button>
            </form>
        </section>
    </main>
</body>
</html>
