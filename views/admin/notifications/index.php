<?php
/**
 * Vue : personnalisation des notifications email/SMS par événement.
 * @var array<string,mixed> $data
 * @var callable $e
 */
$channelLabels = ['email' => 'Email', 'sms' => 'SMS'];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notifications — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <?php include __DIR__ . '/../_nav.php'; ?>

    <main class="kn-wrap">
        <?php if (!empty($data['flash'])): ?><p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p><?php endif; ?>
        <h1>Notifications</h1>
        <p class="kn-muted">
            Texte, délai et activation par canal pour chaque événement déclenché automatiquement.
            Variables disponibles : <?php foreach ($data['variables'] as $v): ?><code style="margin-right:6px;">{{<?= $e($v) ?>}}</code><?php endforeach; ?>
        </p>

        <?php foreach ($data['events'] as $event): ?>
            <section class="kn-card" style="margin-top:16px;">
                <form method="post" action="/admin/notifications/<?= (int) $event['id'] ?>">
                    <?= $data['csrf'] ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <h2 style="margin:0;"><?= $e($event['label']) ?></h2>
                        <label class="kn-check">
                            <input type="checkbox" name="event_active" value="1" <?= (int) $event['is_active'] === 1 ? 'checked' : '' ?>>
                            Événement actif
                        </label>
                    </div>
                    <p class="kn-muted" style="font-size:.85rem;"><code><?= $e($event['event_key']) ?></code></p>

                    <?php if ($event['templates'] === []): ?>
                        <p class="kn-muted">Aucun canal configuré pour cet événement.</p>
                    <?php endif; ?>

                    <?php foreach ($event['templates'] as $tpl): ?>
                        <?php $prefix = 'tpl_' . (int) $tpl['id'] . '_'; ?>
                        <fieldset style="border:1px solid var(--kn-line);border-radius:var(--kn-radius-control);padding:12px;margin-top:12px;">
                            <legend><?= $e($channelLabels[$tpl['channel']] ?? $tpl['channel']) ?></legend>
                            <label class="kn-check">
                                <input type="checkbox" name="<?= $prefix ?>active" value="1" <?= (int) $tpl['is_active'] === 1 ? 'checked' : '' ?>>
                                Canal actif
                            </label>
                            <?php if ($tpl['channel'] === 'email'): ?>
                                <div class="kn-field">
                                    <label>Sujet</label>
                                    <input type="text" name="<?= $prefix ?>subject" value="<?= $e($tpl['subject'] ?? '') ?>">
                                </div>
                            <?php endif; ?>
                            <div class="kn-field">
                                <label>Corps du message</label>
                                <textarea name="<?= $prefix ?>body" rows="4" style="width:100%;padding:8px;border:1px solid var(--kn-line);border-radius:8px;font:inherit;"><?= $e($tpl['body']) ?></textarea>
                            </div>
                            <div class="kn-field" style="max-width:220px;">
                                <label>Délai (minutes, négatif = avant le RDV)</label>
                                <input type="number" name="<?= $prefix ?>offset" value="<?= (int) $tpl['offset_minutes'] ?>">
                            </div>
                        </fieldset>
                    <?php endforeach; ?>

                    <button type="submit" class="kn-btn kn-btn-primary" style="margin-top:12px;">Enregistrer</button>
                </form>
            </section>
        <?php endforeach; ?>
    </main>
</body>
</html>
