<?php
/**
 * Vue : édition d'une catégorie.
 *
 * @var array<string,mixed> $data
 * @var callable $e
 */
$cat = $data['category'];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($cat['name']) ?> — Catégorie — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <header class="kn-header">
        <strong>Keepnew · back-office</strong>
        <nav>
            <a href="/admin/catalogue">Catalogue</a>
            <a href="/admin/extras">Extras</a>
            <a href="/admin/simulateur">Simulateur</a>
            <a href="/admin/deconnexion">Déconnexion</a>
        </nav>
    </header>

    <main class="kn-wrap" style="max-width:640px;">
        <p><a href="/admin/catalogue">← Retour au catalogue</a></p>
        <h1>Catégorie</h1>

        <form method="post" action="/admin/catalogue/categorie/<?= (int) $cat['id'] ?>" class="kn-card">
            <?= $data['csrf'] ?>
            <div class="kn-field">
                <label for="name">Nom</label>
                <input type="text" id="name" name="name" value="<?= $e($cat['name']) ?>" required>
            </div>
            <div class="kn-field">
                <label for="parent_id">Catégorie parente</label>
                <select id="parent_id" name="parent_id">
                    <option value="0">— Aucune (racine) —</option>
                    <?php foreach ($data['categories'] as $c): ?>
                        <?php if ((int) $c['id'] === (int) $cat['id']) { continue; } ?>
                        <option value="<?= (int) $c['id'] ?>" <?= ((int) ($cat['parent_id'] ?? 0) === (int) $c['id']) ? 'selected' : '' ?>>
                            <?= $e($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="kn-field">
                <label for="description">Description</label>
                <input type="text" id="description" name="description" value="<?= $e($cat['description'] ?? '') ?>">
            </div>
            <div class="kn-field">
                <label for="icon">Icône (nom du set SVG)</label>
                <input type="text" id="icon" name="icon" value="<?= $e($cat['icon'] ?? '') ?>">
            </div>
            <label style="display:flex;align-items:center;gap:8px;margin-bottom:16px;">
                <input type="checkbox" name="is_visible" value="1" <?= ((int) $cat['is_visible'] === 1) ? 'checked' : '' ?> style="width:auto;min-height:auto;">
                Visible côté client
            </label>
            <button type="submit" class="kn-btn kn-btn-primary">Enregistrer</button>
        </form>

        <form method="post" action="/admin/catalogue/categorie/<?= (int) $cat['id'] ?>/supprimer" style="margin-top:16px;"
              onsubmit="return confirm('Supprimer cette catégorie ?');">
            <?= $data['csrf'] ?>
            <?php if ($data['deletable']): ?>
                <button type="submit" class="kn-btn kn-btn-ghost" style="color:var(--kn-alert);">Supprimer la catégorie</button>
            <?php else: ?>
                <p class="kn-muted">Cette catégorie contient des sous-catégories ou des prestations : elle ne peut pas être supprimée. Décochez « visible » pour la masquer.</p>
            <?php endif; ?>
        </form>
    </main>
</body>
</html>
