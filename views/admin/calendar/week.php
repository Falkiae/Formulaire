<?php
/**
 * Vue : calendrier hebdomadaire des prestations (lecture + filtres).
 * @var array<string,mixed> $data
 * @var callable $e
 */
$qs = $data['filter_qs'];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Calendrier — Semaine — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <?php include dirname(__DIR__) . '/_nav.php'; ?>

    <main class="kn-wrap" style="max-width:100%;">
        <div class="kn-daynav">
            <a class="kn-btn kn-btn-ghost kn-btn-sm" href="/admin/calendrier/semaine?date=<?= $e($data['prev']) . $qs ?>">← Semaine préc.</a>
            <h1 style="margin:0;font-size:var(--kn-fs-2);"><?= $e($data['label']) ?></h1>
            <a class="kn-btn kn-btn-ghost kn-btn-sm" href="/admin/calendrier/semaine?date=<?= $e($data['next']) . $qs ?>">Semaine suiv. →</a>
        </div>

        <?php include __DIR__ . '/_filters.php'; ?>

        <div class="kn-week-grid">
            <?php foreach ($data['days'] as $day): ?>
                <section class="kn-week-col<?= $day['is_today'] ? ' is-today' : '' ?>">
                    <h3><?= $e($day['label']) ?></h3>
                    <?php foreach ($day['jobs'] as $j): ?>
                        <a class="kn-cal-pill kn-pill-<?= $e($j['mode']) ?>" href="/admin/job/<?= (int) $j['id'] ?>"
                           title="<?= $e($j['customer'] . ' · ' . $j['services']) ?>">
                            <span class="kn-cal-time"><?= $e($j['start_local'] ?? '') ?>–<?= $e($j['end_local'] ?? '') ?></span>
                            <?= $e($j['customer']) ?>
                            <span class="kn-badge <?= $j['mode'] === 'onsite' ? 'kn-badge-onsite' : 'kn-badge-workshop' ?>" style="margin-left:2px;"><?= $j['mode'] === 'onsite' ? 'Dom.' : 'Atl.' ?></span>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($day['jobs'] === []): ?><p class="kn-muted" style="font-size:.8rem;padding:4px;">—</p><?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    </main>
</body>
</html>
