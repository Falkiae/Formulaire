<?php
/**
 * Vue : catalogue des compétences (skills) requises/détenues.
 * @var array<string,mixed> $data
 * @var callable $e
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Compétences — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <?php include __DIR__ . '/_nav.php'; ?>

    <main class="kn-wrap kn-wrap-narrow">
        <?php if (!empty($data['flash'])): ?><p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p><?php endif; ?>
        <p><a href="/admin/techniciens">← Techniciens</a></p>
        <h1>Compétences</h1>
        <p class="kn-muted">Le catalogue partagé : une compétence est requise par des prestations et détenue par des techniciens.</p>

        <section class="kn-card">
            <?php foreach ($data['skills'] as $s): ?>
                <div style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;padding:8px 0;border-bottom:1px solid var(--kn-line);">
                    <form method="post" action="/admin/competences/<?= (int) $s['id'] ?>" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;flex:1;">
                        <?= $data['csrf'] ?>
                        <div class="kn-field" style="margin:0;"><label>Code</label><input type="text" name="code" value="<?= $e($s['code']) ?>" style="max-width:180px;"></div>
                        <div class="kn-field" style="margin:0;flex:1;"><label>Libellé</label><input type="text" name="label" value="<?= $e($s['label']) ?>"></div>
                        <button type="submit" class="kn-btn kn-btn-ghost kn-btn-sm">Enregistrer</button>
                    </form>
                    <form method="post" action="/admin/competences/<?= (int) $s['id'] ?>/supprimer"
                          onsubmit="return confirm('Supprimer cette compétence ? Impossible si elle est utilisée par un technicien ou une prestation.');">
                        <?= $data['csrf'] ?>
                        <button type="submit" class="kn-btn kn-btn-danger kn-btn-sm">Suppr.</button>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php if ($data['skills'] === []): ?><p class="kn-muted">Aucune compétence.</p><?php endif; ?>
        </section>

        <section class="kn-card" style="margin-top:24px;">
            <h2>Nouvelle compétence</h2>
            <form method="post" action="/admin/competences">
                <?= $data['csrf'] ?>
                <div class="kn-grid kn-grid-2">
                    <div class="kn-field"><label for="code">Code</label><input type="text" id="code" name="code" placeholder="ex. sofa_leather" required></div>
                    <div class="kn-field"><label for="label">Libellé</label><input type="text" id="label" name="label" placeholder="ex. Canapé cuir" required></div>
                </div>
                <button type="submit" class="kn-btn kn-btn-primary">Créer</button>
            </form>
        </section>
    </main>
</body>
</html>
