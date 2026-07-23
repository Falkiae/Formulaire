<?php
/**
 * Vue : calendrier mensuel des prestations (lecture + filtres).
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
    <title>Calendrier — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <?php include dirname(__DIR__) . '/_nav.php'; ?>

    <main class="kn-wrap" style="max-width:100%;">
        <div class="kn-daynav">
            <a class="kn-btn kn-btn-ghost kn-btn-sm" href="/admin/calendrier?date=<?= $e($data['prev']) . $qs ?>">← Mois précédent</a>
            <h1 style="margin:0;font-size:var(--kn-fs-2);text-transform:capitalize;"><?= $e($data['label']) ?></h1>
            <a class="kn-btn kn-btn-ghost kn-btn-sm" href="/admin/calendrier?date=<?= $e($data['next']) . $qs ?>">Mois suivant →</a>
        </div>

        <?php include __DIR__ . '/_filters.php'; ?>

        <div class="kn-cal-grid">
            <?php foreach ($data['day_labels'] as $dl): ?>
                <div class="kn-cal-head"><?= $e($dl) ?></div>
            <?php endforeach; ?>

            <?php foreach ($data['weeks'] as $week): ?>
                <?php foreach ($week as $cell): ?>
                    <div class="kn-cal-cell<?= $cell['in_month'] ? '' : ' is-out' ?><?= $cell['is_today'] ? ' is-today' : '' ?>">
                        <div class="kn-cal-daynum">
                            <a href="/admin/calendrier/semaine?date=<?= $e($cell['date']) . $qs ?>"><?= $cell['day'] ?></a>
                        </div>
                        <?php foreach ($cell['jobs'] as $j): ?>
                            <a class="kn-cal-pill kn-pill-<?= $e($j['mode']) ?>" href="/admin/job/<?= (int) $j['id'] ?>"
                               title="<?= $e($j['start_local'] . ' · ' . $j['customer'] . ' · ' . $j['services']) ?>">
                                <span class="kn-cal-time"><?= $e($j['start_local'] ?? '') ?></span> <?= $e($j['customer']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    </main>
</body>
</html>
