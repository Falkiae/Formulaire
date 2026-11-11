<?php
/**
 * Vue : réglages de l'application.
 *
 * Les champs sont décrits dans SettingsController::FIELDS ; cette vue ne fait
 * que les rendre, groupés par section. Ajouter un réglage ne demande donc
 * aucune modification ici.
 *
 * @var array<string,mixed> $data
 * @var callable $e
 */
$sections = $data['sections'];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Réglages — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <?php include __DIR__ . '/../_nav.php'; ?>

    <main class="kn-wrap kn-wrap-narrow">
        <?php if (!empty($data['flash'])): ?><p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p><?php endif; ?>
        <?php if (!empty($data['error'])): ?><p class="kn-alert kn-alert-error"><?= $e($data['error']) ?></p><?php endif; ?>

        <h1>Réglages</h1>
        <p class="kn-muted">Ces valeurs s'appliquent immédiatement, au tunnel public comme au back-office.</p>

        <form method="post" action="/admin/reglages">
            <?= $data['csrf'] ?>

            <?php foreach ($sections as $title => $fields): ?>
                <section class="kn-card kn-mt-sm">
                    <h2><?= $e($title) ?></h2>

                    <?php foreach ($fields as $f): ?>
                        <div class="kn-field">
                            <label for="<?= $e($f['name']) ?>"><?= $e($f['label']) ?></label>

                            <?php if ($f['type'] === 'textarea'): ?>
                                <textarea id="<?= $e($f['name']) ?>" name="<?= $e($f['name']) ?>" rows="5"
                                          style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.85rem;"
                                          <?= !empty($f['help']) ? 'aria-describedby="' . $e($f['name']) . '-help"' : '' ?>><?= $e($f['value']) ?></textarea>

                            <?php elseif ($f['type'] === 'secret'): ?>
                                <input type="password" id="<?= $e($f['name']) ?>" name="<?= $e($f['name']) ?>"
                                       autocomplete="off" placeholder="<?= $f['is_set'] ? '•••••••• (clé enregistrée)' : 'Aucune clé enregistrée' ?>"
                                       <?= !empty($f['help']) ? 'aria-describedby="' . $e($f['name']) . '-help"' : '' ?>>

                            <?php else: ?>
                                <?php
                                $type = match ($f['type']) {
                                    'email' => 'email',
                                    'url' => 'url',
                                    default => 'text',
                                };
                                // inputmode numérique pour tout ce qui se saisit au pavé :
                                // sur mobile, le clavier alphabétique était une friction gratuite.
                                $inputmode = in_array($f['type'], ['int', 'money', 'percent'], true)
                                    ? ($f['type'] === 'int' ? 'numeric' : 'decimal')
                                    : null;
                                ?>
                                <span style="display:flex;align-items:center;gap:8px;">
                                    <input type="<?= $type ?>" id="<?= $e($f['name']) ?>" name="<?= $e($f['name']) ?>"
                                           value="<?= $e($f['value']) ?>"
                                           <?= $inputmode ? 'inputmode="' . $inputmode . '"' : '' ?>
                                           <?= $f['type'] === 'url' ? 'placeholder="https://…"' : '' ?>
                                           <?= !empty($f['help']) ? 'aria-describedby="' . $e($f['name']) . '-help"' : '' ?>>
                                    <?php if (!empty($f['suffix'])): ?>
                                        <span class="kn-muted" style="white-space:nowrap;font-size:var(--kn-fs-small);"><?= $e($f['suffix']) ?></span>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>

                            <?php if (!empty($f['help'])): ?>
                                <p class="kn-muted" id="<?= $e($f['name']) ?>-help" style="font-size:var(--kn-fs-small);margin:4px 0 0;"><?= $e($f['help']) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>

            <div class="kn-row kn-mt">
                <button type="submit" class="kn-btn kn-btn-primary">Enregistrer</button>
            </div>
        </form>
    </main>
</body>
</html>
