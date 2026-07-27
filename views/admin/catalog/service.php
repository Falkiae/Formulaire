<?php
/**
 * Vue : fiche service éditable (prix/durée de base, modes, variantes, extras).
 *
 * @var array<string,mixed> $data
 * @var callable $e
 */
$service = $data['service'];
$centsToEuros = static fn (int $c): string => number_format($c / 100, 2, ',', ' ');
$vatRateBp = (int) $data['vat_rate_bp'];
// Les colonnes de prix restent stockées en HT ; l'admin saisit et voit du
// TVAC ici (converti à l'affichage, reconverti en HT à l'enregistrement
// par CatalogController::eurosTvacToHtCents()).
$tvacEuros = static fn (int $htCents): string =>
    $centsToEuros(\Keepnew\Support\Money::addVat($htCents, $vatRateBp));

// Indexe les modes par nom pour un accès simple dans le formulaire.
$modesByName = [];
foreach ($data['modes'] as $m) {
    $modesByName[$m['mode']] = $m;
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($service['name']) ?> — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <?php include dirname(__DIR__) . '/_nav.php'; ?>

    <main class="kn-wrap">
        <p><a href="/admin/catalogue">← Retour au catalogue</a></p>

        <?php if (!empty($data['flash'])): ?>
            <p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p>
        <?php endif; ?>

        <h1><?= $e($service['name']) ?></h1>
        <p class="kn-muted"><?= $e($service['short_description'] ?? '') ?></p>

        <form method="post" action="/admin/catalogue/service/<?= (int) $service['id'] ?>" enctype="multipart/form-data">
            <?= $data['csrf'] ?>

            <section class="kn-card" style="margin-bottom:24px;">
                <h2>Identité</h2>
                <div class="kn-field">
                    <label for="name">Nom de la prestation</label>
                    <input type="text" id="name" name="name" value="<?= $e($service['name']) ?>" required>
                </div>
                <div class="kn-field">
                    <label for="category_id">Catégorie</label>
                    <select id="category_id" name="category_id" required>
                        <?php foreach ($data['categories'] as $cat): ?>
                            <option value="<?= (int) $cat['id'] ?>" <?= (int) $service['category_id'] === (int) $cat['id'] ? 'selected' : '' ?>>
                                <?= $e($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="kn-field">
                    <label for="short_description">Description courte</label>
                    <input type="text" id="short_description" name="short_description" value="<?= $e($service['short_description'] ?? '') ?>">
                </div>
                <div class="kn-field">
                    <label for="badge_label">Badge de mise en avant</label>
                    <input type="text" id="badge_label" name="badge_label" list="badge-presets" maxlength="40"
                           value="<?= $e($service['badge_label'] ?? '') ?>">
                    <datalist id="badge-presets">
                        <option value="Plus demandée">
                        <option value="La plus complète">
                        <option value="Meilleur rapport qualité-prix">
                        <option value="Nouveauté">
                    </datalist>
                    <p class="kn-muted" style="font-size:.8rem;margin-top:4px;">Affiché en petit badge sur la carte de la prestation. Laisser vide pour ne rien afficher.</p>
                </div>
                <div class="kn-field">
                    <label>Image (affichée dans le tunnel client)</label>
                    <?php if (($service['image_path'] ?? '') !== ''): ?>
                        <img src="/uploads/<?= $e($service['image_path']) ?>" alt="" style="max-width:140px;border-radius:8px;display:block;margin-bottom:8px;">
                        <label class="kn-check"><input type="checkbox" name="remove_image" value="1"> Retirer l'image</label>
                    <?php endif; ?>
                    <input type="file" name="image" accept="image/jpeg,image/png,image/webp">
                    <p class="kn-muted" style="font-size:.8rem;margin-top:4px;">JPEG, PNG ou WebP — recadrée et ré-encodée automatiquement.</p>
                </div>
            </section>

            <section class="kn-card" style="margin-bottom:24px;">
                <h2>Prix et durée de base</h2>
                <p class="kn-muted" style="font-size:.85rem;">Prix TVA comprise (<?= number_format($vatRateBp / 100, 2, ',', ' ') ?> %) — le montant hors TVA est calculé automatiquement pour la facturation.</p>
                <div class="kn-grid kn-grid-2">
                    <div class="kn-field">
                        <label for="base_price">Prix de base (€ TVAC)</label>
                        <input type="text" id="base_price" name="base_price" inputmode="decimal"
                               value="<?= $e($tvacEuros((int) $service['base_price_cents'])) ?>">
                    </div>
                    <div class="kn-field">
                        <label for="base_duration">Durée de base (min)</label>
                        <input type="number" id="base_duration" name="base_duration" min="0"
                               value="<?= (int) $service['base_duration_min'] ?>">
                    </div>
                </div>
                <label class="kn-check">
                    <input type="checkbox" name="is_active" value="1"
                        <?= ((int) $service['is_active'] === 1) ? 'checked' : '' ?>>
                    Prestation active (visible côté client)
                </label>
            </section>

            <section class="kn-card" style="margin-bottom:24px;">
                <h2>Modes d'exécution</h2>
                <p class="kn-muted">Décochez « Proposé aux clients » pour retirer un mode (ex. prestation réservable uniquement à domicile, ou uniquement en atelier). Prix et durée propres à chaque mode ; laisser le prix vide pour retomber sur la base.</p>

                <?php foreach (['onsite' => 'À domicile', 'workshop' => 'Atelier'] as $mode => $label): ?>
                    <?php $m = $modesByName[$mode] ?? null; ?>
                    <fieldset style="border:1px solid var(--kn-line);border-radius:12px;padding:16px;margin-bottom:16px;">
                        <legend>
                            <span class="kn-badge kn-badge-<?= $mode ?>"><?= $e($label) ?></span>
                        </legend>
                        <label class="kn-check">
                            <input type="checkbox" name="mode_<?= $mode ?>" value="1" <?= $m !== null ? 'checked' : '' ?>>
                            Proposé aux clients
                        </label>
                        <div class="kn-grid kn-grid-2">
                            <div class="kn-field">
                                <label for="price_<?= $mode ?>">Prix (€ TVAC)</label>
                                <input type="text" id="price_<?= $mode ?>" name="price_<?= $mode ?>" inputmode="decimal"
                                       value="<?= ($m && $m['price_cents'] !== null) ? $e($tvacEuros((int) $m['price_cents'])) : '' ?>">
                            </div>
                            <div class="kn-field">
                                <label for="duration_<?= $mode ?>">Durée active (min)</label>
                                <input type="number" id="duration_<?= $mode ?>" name="duration_<?= $mode ?>" min="0"
                                       value="<?= ($m && $m['active_duration_min'] !== null) ? (int) $m['active_duration_min'] : '' ?>">
                            </div>
                        </div>
                        <?php if ($mode === 'workshop'): ?>
                            <div class="kn-field">
                                <label for="occupancy_workshop">Immobilisation du poste (min, séchage inclus)</label>
                                <input type="number" id="occupancy_workshop" name="occupancy_workshop" min="0"
                                       value="<?= ($m && $m['occupancy_duration_min'] !== null) ? (int) $m['occupancy_duration_min'] : '' ?>">
                            </div>
                        <?php endif; ?>
                    </fieldset>
                <?php endforeach; ?>
            </section>

            <button type="submit" class="kn-btn kn-btn-primary">Enregistrer la prestation</button>
        </form>

        <section class="kn-card" style="margin-top:24px;">
            <h2>Variantes</h2>

            <?php if ($data['variants'] === []): ?>
                <p class="kn-muted">Aucune variante.</p>
            <?php endif; ?>

            <?php foreach ($data['variants'] as $v): ?>
                <div style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;padding:8px 0;border-bottom:1px solid var(--kn-line);">
                    <form method="post" action="/admin/catalogue/service/<?= (int) $service['id'] ?>/variante/<?= (int) $v['id'] ?>"
                          style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
                        <?= $data['csrf'] ?>
                        <div class="kn-field" style="margin:0;"><label>Libellé <?= ((int) $v['is_default'] === 1) ? '<span class="kn-badge">★ Par défaut</span>' : '' ?></label><input type="text" name="label" value="<?= $e($v['label']) ?>" style="max-width:180px;"></div>
                        <div class="kn-field" style="margin:0;"><label>Δ prix (€ TVAC)</label><input type="text" name="price_delta" value="<?= $e($tvacEuros((int) $v['price_delta_cents'])) ?>" inputmode="decimal" style="max-width:100px;"></div>
                        <div class="kn-field" style="margin:0;"><label>Δ durée (min)</label><input type="number" name="duration_delta" value="<?= (int) $v['duration_delta_min'] ?>" style="max-width:90px;"></div>
                        <label class="kn-check" style="margin-bottom:12px;">
                            <input type="checkbox" name="is_active" value="1" <?= ((int) $v['is_active'] === 1) ? 'checked' : '' ?>> active
                        </label>
                        <label class="kn-check" style="margin-bottom:12px;">
                            <input type="checkbox" name="is_default" value="1" <?= ((int) $v['is_default'] === 1) ? 'checked' : '' ?>> défaut
                        </label>
                        <button type="submit" class="kn-btn kn-btn-ghost kn-btn-sm" style="margin-bottom:12px;">Enregistrer</button>
                    </form>
                    <form method="post" action="/admin/catalogue/service/<?= (int) $service['id'] ?>/variante/<?= (int) $v['id'] ?>/supprimer"
                          onsubmit="return confirm('Supprimer cette variante ?');" style="margin-bottom:12px;">
                        <?= $data['csrf'] ?>
                        <button type="submit" class="kn-btn kn-btn-danger kn-btn-sm">Supprimer</button>
                    </form>
                </div>
            <?php endforeach; ?>

            <details style="margin-top:12px;">
                <summary>Ajouter une variante</summary>
                <form method="post" action="/admin/catalogue/service/<?= (int) $service['id'] ?>/variante"
                      style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:12px;">
                    <?= $data['csrf'] ?>
                    <div class="kn-field" style="margin:0;"><label>Libellé</label><input type="text" name="label" required style="max-width:200px;"></div>
                    <div class="kn-field" style="margin:0;"><label>Δ prix (€ TVAC)</label><input type="text" name="price_delta" inputmode="decimal" value="0" style="max-width:100px;"></div>
                    <div class="kn-field" style="margin:0;"><label>Δ durée (min)</label><input type="number" name="duration_delta" value="0" style="max-width:90px;"></div>
                    <button type="submit" class="kn-btn kn-btn-ghost">Ajouter</button>
                </form>
            </details>
        </section>

        <section class="kn-card" style="margin-top:24px;">
            <h2>Extras rattachés</h2>
            <div class="kn-table-wrap">
                <table class="kn-table">
                    <thead><tr><th>Extra</th><th>Sélection</th><th class="kn-num">Prix effectif (TVAC)</th><th class="kn-num">Durée</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($data['extras'] as $x): ?>
                            <tr>
                                <td><?= $e($x['label']) ?></td>
                                <td class="kn-muted"><?= $x['selection_type'] === 'radio' ? 'exclusif' : 'cumulable' ?><?= $x['exclusive_group'] ? ' · ' . $e($x['exclusive_group']) : '' ?></td>
                                <td class="kn-num"><?= $e($tvacEuros((int) $x['eff_price_cents'])) ?> €</td>
                                <td class="kn-num"><?= (int) $x['eff_duration_min'] ?> min</td>
                                <td>
                                    <form method="post" action="/admin/catalogue/service/<?= (int) $service['id'] ?>/extras/<?= (int) $x['extra_id'] ?>/detacher">
                                        <?= $data['csrf'] ?>
                                        <button type="submit" class="kn-btn kn-btn-ghost kn-btn-sm">Détacher</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($data['extras'] === []): ?>
                            <tr><td colspan="5" class="kn-muted">Aucun extra rattaché.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($data['available_extras'] !== []): ?>
                <details style="margin-top:12px;">
                    <summary>Rattacher un extra existant</summary>
                    <form method="post" action="/admin/catalogue/service/<?= (int) $service['id'] ?>/extras"
                          style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:12px;">
                        <?= $data['csrf'] ?>
                        <div class="kn-field" style="margin:0;">
                            <label>Extra</label>
                            <select name="extra_id">
                                <?php foreach ($data['available_extras'] as $ax): ?>
                                    <option value="<?= (int) $ax['id'] ?>"><?= $e($ax['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="kn-field" style="margin:0;">
                            <label>Prix surchargé (€ TVAC, vide = défaut)</label>
                            <input type="text" name="price" inputmode="decimal" style="max-width:120px;">
                        </div>
                        <div class="kn-field" style="margin:0;">
                            <label>Sélection</label>
                            <select name="selection_type">
                                <option value="checkbox">Cumulable</option>
                                <option value="radio">Exclusif</option>
                            </select>
                        </div>
                        <div class="kn-field" style="margin:0;">
                            <label>Groupe exclusif (optionnel)</label>
                            <input type="text" name="exclusive_group" style="max-width:120px;">
                        </div>
                        <button type="submit" class="kn-btn kn-btn-ghost">Rattacher</button>
                    </form>
                </details>
            <?php else: ?>
                <p class="kn-muted">Tous les extras actifs sont déjà rattachés. <a href="/admin/extras">Créer un nouvel extra</a>.</p>
            <?php endif; ?>
        </section>

        <section class="kn-card" style="margin-top:24px;">
            <h2>Actions</h2>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <form method="post" action="/admin/catalogue/service/<?= (int) $service['id'] ?>/dupliquer">
                    <?= $data['csrf'] ?>
                    <button type="submit" class="kn-btn kn-btn-ghost">Dupliquer la prestation</button>
                </form>
                <form method="post" action="/admin/catalogue/service/<?= (int) $service['id'] ?>/supprimer"
                      onsubmit="return confirm('Supprimer cette prestation ? Si elle a déjà été commandée, elle sera désactivée.');">
                    <?= $data['csrf'] ?>
                    <button type="submit" class="kn-btn kn-btn-danger">Supprimer / désactiver</button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
