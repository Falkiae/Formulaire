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
    $html = '<ul class="kn-cat-list">';
    foreach ($nodes as $node) {
        $vis = ((int) $node['is_visible'] === 1) ? '' : ' <span class="kn-badge kn-badge-off">masquée</span>';
        $html .= '<li data-id="' . (int) $node['id'] . '">'
            . '<a href="/admin/catalogue/categorie/' . (int) $node['id'] . '">' . $e($node['name']) . '</a>' . $vis
            . $renderTree($node['children'] ?? [], $e)
            . '</li>';
    }

    return $html . '</ul>';
};

$centsToEuros = static fn (int $c): string => number_format($c / 100, 2, ',', ' ');
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
    <header class="kn-header">
        <strong>Keepnew · back-office</strong>
        <nav>
            <a href="/admin/catalogue" aria-current="page">Catalogue</a>
            <a href="/admin/extras">Extras</a>
            <a href="/admin/simulateur">Simulateur</a>
            <a href="/admin/deconnexion">Déconnexion</a>
        </nav>
    </header>

    <main class="kn-wrap">
        <?php if (!empty($data['flash'])): ?>
            <p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p>
        <?php endif; ?>

        <h1>Catalogue</h1>

        <div class="kn-grid kn-grid-2">
            <section class="kn-card">
                <h2>Catégories</h2>
                <?= $renderTree($data['tree'], $e) ?>

                <details style="margin-top:16px;">
                    <summary>Ajouter une catégorie</summary>
                    <form method="post" action="/admin/catalogue/categorie" style="margin-top:12px;">
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
                        <label style="display:flex;align-items:center;gap:8px;">
                            <input type="checkbox" name="is_visible" value="1" checked style="width:auto;min-height:auto;">
                            Visible côté client
                        </label>
                        <button type="submit" class="kn-btn kn-btn-ghost" style="margin-top:12px;">Créer la catégorie</button>
                    </form>
                </details>
            </section>

            <section class="kn-card">
                <h2>Prestations</h2>
                <p class="kn-muted">Glissez-déposez les lignes pour réordonner.</p>
                <table class="kn-table" id="services-table">
                    <thead>
                        <tr>
                            <th>Prestation</th>
                            <th>Catégorie</th>
                            <th class="kn-num">Prix base</th>
                            <th class="kn-num">Durée</th>
                            <th>État</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="services-body">
                        <?php foreach ($data['services'] as $s): ?>
                            <tr draggable="true" data-id="<?= (int) $s['id'] ?>">
                                <td><a href="/admin/catalogue/service/<?= (int) $s['id'] ?>"><?= $e($s['name']) ?></a></td>
                                <td class="kn-muted"><?= $e($s['category_name']) ?></td>
                                <td class="kn-num"><?= $e($centsToEuros((int) $s['base_price_cents'])) ?> €</td>
                                <td class="kn-num"><?= (int) $s['base_duration_min'] ?> min</td>
                                <td>
                                    <?php if ((int) $s['is_active'] === 1): ?>
                                        <span class="kn-badge kn-badge-onsite">actif</span>
                                    <?php else: ?>
                                        <span class="kn-badge kn-badge-off">inactif</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" action="/admin/catalogue/service/<?= (int) $s['id'] ?>/dupliquer" style="display:inline;">
                                        <?= $data['csrf'] ?>
                                        <button type="submit" class="kn-btn kn-btn-ghost" style="min-height:36px;padding:0 12px;">Dupliquer</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <details style="margin-top:16px;">
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
                                <label for="svc_price">Prix de base (€)</label>
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

    <script>
    (function () {
        "use strict";
        const CSRF = <?= json_encode(preg_match('/value="([^"]+)"/', $data['csrf'], $m) ? $m[1] : '', JSON_UNESCAPED_SLASHES) ?>;
        const body = document.getElementById("services-body");
        let dragged = null;

        body.addEventListener("dragstart", (e) => { dragged = e.target.closest("tr"); e.dataTransfer.effectAllowed = "move"; });
        body.addEventListener("dragover", (e) => {
            e.preventDefault();
            const row = e.target.closest("tr");
            if (!row || row === dragged) return;
            const rect = row.getBoundingClientRect();
            const after = (e.clientY - rect.top) > rect.height / 2;
            body.insertBefore(dragged, after ? row.nextSibling : row);
        });
        body.addEventListener("drop", (e) => {
            e.preventDefault();
            const order = Array.from(body.querySelectorAll("tr")).map((r) => parseInt(r.dataset.id, 10));
            fetch("/admin/catalogue/services/ordre", {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": CSRF, "Accept": "application/json" },
                body: JSON.stringify({ order }),
            });
        });
    })();
    </script>
</body>
</html>
