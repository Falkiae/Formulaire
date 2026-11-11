<?php
/**
 * Vue : catalogue back-office — catégories (CRUD + réordonnancement) et
 * prestations (création, duplication, suppression, réordonnancement).
 *
 * @var array<string,mixed> $data
 * @var callable $e
 */

/** Rend récursivement l'arborescence des catégories avec liens d'édition. */
$renderTree = static function (array $nodes, callable $e) use (&$renderTree): string {
    if ($nodes === []) {
        return '';
    }
    // data-reorder : chaque niveau de l'arborescence se réordonne chez lui.
    $html = '<ul class="kn-cat-list" data-reorder="/admin/catalogue/categories/ordre"'
        . ' data-reorder-item="li" data-reorder-label="catégorie">';
    foreach ($nodes as $node) {
        $vis = ((int) $node['is_visible'] === 1) ? '' : ' <span class="kn-badge kn-badge-off">masquée</span>';
        $html .= '<li data-id="' . (int) $node['id'] . '" data-reorder-name="' . $e($node['name']) . '">'
            . '<a href="/admin/catalogue/categorie/' . (int) $node['id'] . '">' . $e($node['name']) . '</a>' . $vis
            . $renderTree($node['children'] ?? [], $e)
            . '</li>';
    }

    return $html . '</ul>';
};

$centsToEuros = static fn (int $c): string => number_format($c / 100, 2, ',', ' ');
$vatRateBp = (int) $data['vat_rate_bp'];
// Colonne stockée en HT ; affichage TVAC pour cohérence avec la fiche
// service, où le prix est désormais saisi en TVAC.
$tvacEuros = static fn (int $htCents): string =>
    $centsToEuros(\Keepnew\Support\Money::addVat($htCents, $vatRateBp));
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Catalogue — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <?php include dirname(__DIR__) . '/_nav.php'; ?>

    <main class="kn-wrap">
        <?php if (!empty($data['flash'])): ?>
            <p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p>
        <?php endif; ?>

        <h1>Catalogue</h1>

        <div class="kn-grid kn-grid-2">
            <section class="kn-card">
                <h2>Catégories</h2>
                <?= $renderTree($data['tree'], $e) ?>

                <details style="margin-top:16px;"<?= $data['tree'] === [] ? ' open' : '' ?>>
                    <summary>Ajouter une catégorie</summary>
                    <form method="post" action="/admin/catalogue/categorie" style="margin-top:12px;" enctype="multipart/form-data">
                        <?= $data['csrf'] ?>
                        <div class="kn-field">
                            <label for="cat_name">Nom</label>
                            <input type="text" id="cat_name" name="name" required>
                        </div>
                        <div class="kn-field">
                            <label for="cat_parent">Catégorie parente</label>
                            <select id="cat_parent" name="parent_id">
                                <option value="0">— Aucune (racine) —</option>
                                <?php foreach ($data['categories'] as $c): ?>
                                    <option value="<?= (int) $c['id'] ?>"><?= $e($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="kn-field">
                            <label for="cat_image">Image (optionnel)</label>
                            <input type="file" id="cat_image" name="image" accept="image/jpeg,image/png,image/webp">
                        </div>
                        <label class="kn-check">
                            <input type="checkbox" name="is_visible" value="1" checked>
                            Visible côté client
                        </label>
                        <button type="submit" class="kn-btn kn-btn-ghost" style="margin-top:12px;">Créer la catégorie</button>
                    </form>
                </details>
            </section>

            <section class="kn-card">
                <h2>Prestations</h2>
                <p class="kn-muted">Les flèches réordonnent la liste — l'ordre est celui que verra le client. Le glisser-déposer fonctionne aussi à la souris.</p>
                <div class="kn-table-wrap">
                    <table class="kn-table" id="services-table">
                        <caption class="kn-visually-hidden">Prestations du catalogue, dans l'ordre d'affichage côté client</caption>
                        <thead>
                            <tr>
                                <th><span class="kn-visually-hidden">Ordre</span></th>
                                <th>Prestation</th>
                                <th>Catégorie</th>
                                <th class="kn-num">Prix base (TVAC)</th>
                                <th class="kn-num">Durée</th>
                                <th>État</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="services-body" data-reorder="/admin/catalogue/services/ordre"
                               data-reorder-item="tr" data-reorder-label="prestation">
                            <?php foreach ($data['services'] as $s): ?>
                                <tr draggable="true" data-id="<?= (int) $s['id'] ?>" data-reorder-name="<?= $e($s['name']) ?>">
                                    <td><a href="/admin/catalogue/service/<?= (int) $s['id'] ?>"><?= $e($s['name']) ?></a></td>
                                    <td class="kn-muted"><?= $e($s['category_name']) ?></td>
                                    <td class="kn-num"><?= $e($tvacEuros((int) $s['base_price_cents'])) ?> €</td>
                                    <td class="kn-num"><?= (int) $s['base_duration_min'] ?> min</td>
                                    <td>
                                        <?php if ((int) $s['is_active'] === 1): ?>
                                            <span class="kn-badge kn-badge-on">actif</span>
                                        <?php else: ?>
                                            <span class="kn-badge kn-badge-off">inactif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" action="/admin/catalogue/service/<?= (int) $s['id'] ?>/dupliquer" style="display:inline;">
                                            <?= $data['csrf'] ?>
                                            <button type="submit" class="kn-btn kn-btn-ghost kn-btn-sm">Dupliquer</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($data['services'] === []): ?>
                                <tr><td colspan="7" class="kn-muted">Aucune prestation. Créez-en une ci-dessous : elle apparaîtra aussitôt dans le tunnel client.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <details style="margin-top:16px;"<?= $data['services'] === [] ? ' open' : '' ?>>
                    <summary>Ajouter une prestation</summary>
                    <form method="post" action="/admin/catalogue/service" style="margin-top:12px;">
                        <?= $data['csrf'] ?>
                        <div class="kn-field">
                            <label for="svc_name">Nom</label>
                            <input type="text" id="svc_name" name="name" required>
                        </div>
                        <div class="kn-field">
                            <label for="svc_cat">Catégorie</label>
                            <select id="svc_cat" name="category_id" required>
                                <?php foreach ($data['categories'] as $c): ?>
                                    <option value="<?= (int) $c['id'] ?>"><?= $e($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="kn-grid kn-grid-2">
                            <div class="kn-field">
                                <label for="svc_price">Prix de base (€ TVAC)</label>
                                <input type="text" id="svc_price" name="base_price" inputmode="decimal" value="0">
                            </div>
                            <div class="kn-field">
                                <label for="svc_dur">Durée de base (min)</label>
                                <input type="number" id="svc_dur" name="base_duration" min="0" value="60">
                            </div>
                        </div>
                        <button type="submit" class="kn-btn kn-btn-primary" style="margin-top:12px;">Créer la prestation</button>
                    </form>
                </details>
            </section>
        </div>
    </main>

    <script src="/assets/admin-reorder.js" defer></script>
</body>
</html>
