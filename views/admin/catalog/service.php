<?php
/**
 * Vue : fiche service éditable (prix/durée de base, modes, variantes, extras).
 *
 * @var array<string,mixed> $data
 * @var callable $e
 */
$service = $data['service'];
$centsToEuros = static fn (int $c): string => number_format($c / 100, 2, ',', ' ');

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
    <header class="kn-header">
        <strong>Keepnew · back-office</strong>
        <nav>
            <a href="/admin/catalogue">Catalogue</a>
            <a href="/admin/simulateur">Simulateur</a>
            <a href="/admin/deconnexion">Déconnexion</a>
        </nav>
    </header>

    <main class="kn-wrap">
        <p><a href="/admin/catalogue">← Retour au catalogue</a></p>

        <?php if (!empty($data['flash'])): ?>
            <p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p>
        <?php endif; ?>

        <h1><?= $e($service['name']) ?></h1>
        <p class="kn-muted"><?= $e($service['short_description'] ?? '') ?></p>

        <form method="post" action="/admin/catalogue/service/<?= (int) $service['id'] ?>">
            <?= $data['csrf'] ?>

            <section class="kn-card" style="margin-bottom:24px;">
                <h2>Prix et durée de base</h2>
                <div class="kn-grid kn-grid-2">
                    <div class="kn-field">
                        <label for="base_price">Prix de base (€)</label>
                        <input type="text" id="base_price" name="base_price" inputmode="decimal"
                               value="<?= $e($centsToEuros((int) $service['base_price_cents'])) ?>">
                    </div>
                    <div class="kn-field">
                        <label for="base_duration">Durée de base (min)</label>
                        <input type="number" id="base_duration" name="base_duration" min="0"
                               value="<?= (int) $service['base_duration_min'] ?>">
                    </div>
                </div>
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="is_active" value="1" style="width:auto;min-height:auto;"
                        <?= ((int) $service['is_active'] === 1) ? 'checked' : '' ?>>
                    Prestation active (visible côté client)
                </label>
            </section>

            <section class="kn-card" style="margin-bottom:24px;">
                <h2>Modes d'exécution</h2>
                <p class="kn-muted">Prix et durée propres à chaque mode. Laisser le prix vide pour retomber sur la base.</p>

                <?php foreach (['onsite' => 'À domicile', 'workshop' => 'Atelier'] as $mode => $label): ?>
                    <?php $m = $modesByName[$mode] ?? null; ?>
                    <fieldset style="border:1px solid var(--kn-line);border-radius:12px;padding:16px;margin-bottom:16px;">
                        <legend>
                            <span class="kn-badge kn-badge-<?= $mode ?>"><?= $e($label) ?></span>
                            <?php if ($m === null): ?><span class="kn-muted">— non proposé</span><?php endif; ?>
                        </legend>
                        <div class="kn-grid kn-grid-2">
                            <div class="kn-field">
                                <label for="price_<?= $mode ?>">Prix (€)</label>
                                <input type="text" id="price_<?= $mode ?>" name="price_<?= $mode ?>" inputmode="decimal"
                                       value="<?= ($m && $m['price_cents'] !== null) ? $e($centsToEuros((int) $m['price_cents'])) : '' ?>"
                                    <?= $m === null ? 'disabled' : '' ?>>
                            </div>
                            <div class="kn-field">
                                <label for="duration_<?= $mode ?>">Durée active (min)</label>
                                <input type="number" id="duration_<?= $mode ?>" name="duration_<?= $mode ?>" min="0"
                                       value="<?= ($m && $m['active_duration_min'] !== null) ? (int) $m['active_duration_min'] : '' ?>"
                                    <?= $m === null ? 'disabled' : '' ?>>
                            </div>
                        </div>
                        <?php if ($mode === 'workshop' && $m !== null): ?>
                            <div class="kn-field">
                                <label for="occupancy_workshop">Immobilisation du poste (min, séchage inclus)</label>
                                <input type="number" id="occupancy_workshop" name="occupancy_workshop" min="0"
                                       value="<?= $m['occupancy_duration_min'] !== null ? (int) $m['occupancy_duration_min'] : '' ?>">
                            </div>
                        <?php endif; ?>
                    </fieldset>
                <?php endforeach; ?>
            </section>

            <button type="submit" class="kn-btn kn-btn-primary">Enregistrer la prestation</button>
        </form>

        <section class="kn-card" style="margin-top:24px;">
            <h2>Variantes</h2>
            <table class="kn-table">
                <thead><tr><th>Variante</th><th class="kn-num">Δ prix</th><th class="kn-num">Δ durée</th><th>État</th></tr></thead>
                <tbody>
                    <?php foreach ($data['variants'] as $v): ?>
                        <tr>
                            <td><?= $e($v['label']) ?></td>
                            <td class="kn-num"><?= $e($centsToEuros((int) $v['price_delta_cents'])) ?> €</td>
                            <td class="kn-num"><?= (int) $v['duration_delta_min'] ?> min</td>
                            <td><?= ((int) $v['is_active'] === 1) ? 'actif' : '<span class="kn-muted">inactif</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($data['variants'] === []): ?>
                        <tr><td colspan="4" class="kn-muted">Aucune variante.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="kn-card" style="margin-top:24px;">
            <h2>Extras rattachés</h2>
            <table class="kn-table">
                <thead><tr><th>Extra</th><th>Sélection</th><th class="kn-num">Prix effectif</th><th class="kn-num">Durée</th></tr></thead>
                <tbody>
                    <?php foreach ($data['extras'] as $x): ?>
                        <tr>
                            <td><?= $e($x['label']) ?></td>
                            <td class="kn-muted"><?= $x['selection_type'] === 'radio' ? 'exclusif' : 'cumulable' ?></td>
                            <td class="kn-num"><?= $e($centsToEuros((int) $x['eff_price_cents'])) ?> €</td>
                            <td class="kn-num"><?= (int) $x['eff_duration_min'] ?> min</td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($data['extras'] === []): ?>
                        <tr><td colspan="4" class="kn-muted">Aucun extra rattaché.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </main>
</body>
</html>
