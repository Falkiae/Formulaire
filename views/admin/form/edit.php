<?php
/**
 * Vue : édition d'une version de formulaire (champs, options, conditions).
 * @var array<string,mixed> $data
 * @var callable $e
 */
$version = $data['version'];
$fieldsById = [];
foreach ($data['fields'] as $f) { $fieldsById[(int) $f['id']] = $f; }
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Formulaire v<?= (int) $version['version'] ?> — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <header class="kn-header">
        <strong>Keepnew · back-office</strong>
        <nav>
            <a href="/admin/dispatch">Dispatch</a>
            <a href="/admin/catalogue">Catalogue</a>
            <a href="/admin/formulaire">Formulaire</a>
            <a href="/admin/deconnexion">Déconnexion</a>
        </nav>
    </header>
    <main class="kn-wrap">
        <p><a href="/admin/formulaire">← Versions</a></p>
        <?php if (!empty($data['flash'])): ?><p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p><?php endif; ?>

        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
            <h1>v<?= (int) $version['version'] ?> — <?= $e($version['label']) ?>
                <?= (int) $version['is_published'] === 1 ? '<span class="kn-badge kn-badge-onsite">publiée</span>' : '<span class="kn-badge kn-badge-off">brouillon</span>' ?>
            </h1>
            <form method="post" action="/admin/formulaire/<?= (int) $version['id'] ?>/publier" onsubmit="return confirm('Publier cette version comme formulaire en ligne ?');">
                <?= $data['csrf'] ?>
                <button type="submit" class="kn-btn kn-btn-primary">Publier</button>
            </form>
        </div>

        <section class="kn-card" style="margin-top:16px;">
            <h2>Champs</h2>
            <table class="kn-table" id="fields-table">
                <thead><tr><th>Clé</th><th>Libellé</th><th>Type</th><th>Étape</th><th>Requis</th><th>Options</th><th></th></tr></thead>
                <tbody id="fields-body">
                    <?php foreach ($data['fields'] as $f): ?>
                        <tr draggable="true" data-id="<?= (int) $f['id'] ?>">
                            <td><code><?= $e($f['field_key']) ?></code></td>
                            <td><?= $e($f['label']) ?></td>
                            <td><span class="kn-badge"><?= $e($f['field_type']) ?></span></td>
                            <td><?= (int) $f['step'] ?></td>
                            <td><?= (int) $f['is_required'] === 1 ? 'oui' : 'non' ?></td>
                            <td>
                                <?php foreach ($f['options'] as $o): ?>
                                    <span class="kn-badge"><?= $e($o['label']) ?>
                                        <a href="/admin/formulaire/<?= (int) $version['id'] ?>/option/<?= (int) $o['id'] ?>/supprimer" onclick="return confirm('Supprimer ?')" style="color:var(--kn-alert);text-decoration:none;">✕</a>
                                    </span>
                                <?php endforeach; ?>
                                <?php if (in_array($f['field_type'], ['select','radio','checkbox','cards'], true)): ?>
                                    <details><summary class="kn-muted">+ option</summary>
                                        <form method="post" action="/admin/formulaire/<?= (int) $version['id'] ?>/champ/<?= (int) $f['id'] ?>/option" style="display:flex;gap:4px;margin-top:4px;">
                                            <?= $data['csrf'] ?>
                                            <input type="text" name="label" placeholder="Libellé" style="max-width:120px;">
                                            <input type="number" name="duration_modifier_value" placeholder="+dur %" title="Modif. durée (points de base)" style="max-width:80px;">
                                            <input type="hidden" name="duration_modifier_type" value="percent">
                                            <button class="kn-btn kn-btn-ghost" style="min-height:36px;padding:0 10px;">+</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" action="/admin/formulaire/<?= (int) $version['id'] ?>/champ/<?= (int) $f['id'] ?>/supprimer" onsubmit="return confirm('Supprimer ce champ ?')">
                                    <?= $data['csrf'] ?>
                                    <button class="kn-btn kn-btn-ghost" style="min-height:36px;padding:0 10px;color:var(--kn-alert);">Suppr.</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($data['fields'] === []): ?><tr><td colspan="7" class="kn-muted">Aucun champ.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <p class="kn-muted">Glissez les lignes pour réordonner.</p>

            <details style="margin-top:12px;">
                <summary>Ajouter un champ</summary>
                <form method="post" action="/admin/formulaire/<?= (int) $version['id'] ?>/champ" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:12px;">
                    <?= $data['csrf'] ?>
                    <div class="kn-field" style="margin:0;"><label>Libellé</label><input type="text" name="label" required style="max-width:200px;"></div>
                    <div class="kn-field" style="margin:0;"><label>Clé (optionnel)</label><input type="text" name="field_key" style="max-width:140px;"></div>
                    <div class="kn-field" style="margin:0;"><label>Type</label><select name="field_type">
                        <?php foreach ($data['field_types'] as $t): ?><option value="<?= $e($t) ?>"><?= $e($t) ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="kn-field" style="margin:0;"><label>Étape</label><input type="number" name="step" value="5" min="1" style="max-width:70px;"></div>
                    <label style="display:inline-flex;align-items:center;gap:4px;margin-bottom:12px;"><input type="checkbox" name="is_required" value="1" style="width:auto;min-height:auto;"> requis</label>
                    <button type="submit" class="kn-btn kn-btn-ghost">Ajouter</button>
                </form>
            </details>
        </section>

        <section class="kn-card" style="margin-top:24px;">
            <h2>Logique conditionnelle</h2>
            <?php foreach ($data['conditions'] as $c): ?>
                <p>
                    SI <strong><?= $e($fieldsById[(int) $c['source_field_id']]['field_key'] ?? '?') ?></strong>
                    <?= $e($c['operator']) ?> « <?= $e($c['compare_value'] ?? '') ?> »
                    ALORS <strong><?= $e($c['action']) ?></strong>
                    <strong><?= $e($fieldsById[(int) $c['target_field_id']]['field_key'] ?? '?') ?></strong>
                    <a href="/admin/formulaire/<?= (int) $version['id'] ?>/condition/<?= (int) $c['id'] ?>/supprimer" onclick="return confirm('Supprimer ?')" style="color:var(--kn-alert);">✕</a>
                </p>
            <?php endforeach; ?>
            <?php if ($data['conditions'] === []): ?><p class="kn-muted">Aucune condition.</p><?php endif; ?>

            <details style="margin-top:12px;">
                <summary>Ajouter une condition</summary>
                <form method="post" action="/admin/formulaire/<?= (int) $version['id'] ?>/condition" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:12px;">
                    <?= $data['csrf'] ?>
                    <div class="kn-field" style="margin:0;"><label>SI champ</label><select name="source_field_id">
                        <?php foreach ($data['fields'] as $f): ?><option value="<?= (int) $f['id'] ?>"><?= $e($f['field_key']) ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="kn-field" style="margin:0;"><label>opérateur</label><select name="operator">
                        <?php foreach (['eq','neq','in','gt','lt','filled','empty'] as $op): ?><option value="<?= $op ?>"><?= $op ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="kn-field" style="margin:0;"><label>valeur</label><input type="text" name="compare_value" style="max-width:100px;"></div>
                    <div class="kn-field" style="margin:0;"><label>ALORS</label><select name="action">
                        <?php foreach (['show','hide','require','optional'] as $a): ?><option value="<?= $a ?>"><?= $a ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="kn-field" style="margin:0;"><label>champ cible</label><select name="target_field_id">
                        <?php foreach ($data['fields'] as $f): ?><option value="<?= (int) $f['id'] ?>"><?= $e($f['field_key']) ?></option><?php endforeach; ?>
                    </select></div>
                    <button type="submit" class="kn-btn kn-btn-ghost">Ajouter</button>
                </form>
            </details>
        </section>
    </main>

    <script>
    (function () {
        "use strict";
        var CSRF = <?= json_encode($data['csrf_token'], JSON_UNESCAPED_SLASHES) ?>;
        var body = document.getElementById("fields-body");
        var dragged = null;
        body.addEventListener("dragstart", function (e) { dragged = e.target.closest("tr"); });
        body.addEventListener("dragover", function (e) {
            e.preventDefault();
            var row = e.target.closest("tr");
            if (!row || row === dragged) return;
            var r = row.getBoundingClientRect();
            body.insertBefore(dragged, (e.clientY - r.top) > r.height / 2 ? row.nextSibling : row);
        });
        body.addEventListener("drop", function (e) {
            e.preventDefault();
            var order = Array.prototype.map.call(body.querySelectorAll("tr"), function (r) { return parseInt(r.dataset.id, 10); }).filter(Boolean);
            fetch("/admin/formulaire/<?= (int) $version['id'] ?>/champs/ordre", {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": CSRF },
                body: JSON.stringify({ order: order }),
            });
        });
    })();
    </script>
</body>
</html>
