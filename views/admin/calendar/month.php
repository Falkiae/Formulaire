<?php
/**
 * Vue : calendrier mensuel des prestations (lecture + filtres).
 * @var array<string,mixed> $data
 * @var callable $e
 */
$qs = $data['filter_qs'];
$data['base_path'] = '/admin/calendrier';
$initials = static fn (string $first, string $last): string =>
    mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
$avatarColor = static fn (int $techId): string =>
    ['#EFC6CB', '#E5E9FB', '#E4EFE7', '#F6EDD8', '#DDE3EF', '#E4AEB7'][$techId % 6];
$techById = [];
foreach ($data['technicians'] as $t) {
    $techById[(int) $t['id']] = $t;
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Calendrier — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
    <link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
</head>
<body>
    <?php include dirname(__DIR__) . '/_nav.php'; ?>

    <main class="kn-wrap" style="max-width:100%;">
        <div class="kn-daynav">
            <a class="kn-btn kn-btn-ghost kn-btn-sm" href="/admin/calendrier?date=<?= $e($data['prev']) . $qs ?>">← Mois précédent</a>
            <h1 style="margin:0;font-size:var(--kn-fs-2);text-transform:capitalize;"><?= $e($data['label']) ?></h1>
            <a class="kn-btn kn-btn-ghost kn-btn-sm" href="/admin/calendrier?date=<?= $e($data['next']) . $qs ?>">Mois suivant →</a>
            <div class="kn-cal-toggle">
                <a class="kn-btn kn-btn-ghost kn-btn-sm" href="<?= $e($data['day_link']) ?>">Jour</a>
                <a class="kn-btn kn-btn-ghost kn-btn-sm" href="/admin/calendrier/semaine?date=<?= $e($data['week_of']) . $qs ?>">Semaine</a>
                <span class="kn-badge kn-badge-onsite">Mois</span>
            </div>
        </div>

        <div class="kn-cal-layout">
            <?php include __DIR__ . '/_sidebar.php'; ?>

            <div class="kn-cal-main">
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
                                    <?php $tech = $techById[(int) $j['technician_id']] ?? null; ?>
                                    <a class="kn-cal-pill kn-pill-<?= $e($j['mode']) ?>" href="/admin/job/<?= (int) $j['id'] ?>"
                                       title="<?= $e($j['start_local'] . ' · ' . $j['customer'] . ' · ' . $j['services']) ?>">
                                        <?php if ($tech): ?>
                                            <span class="kn-avatar" style="background:<?= $avatarColor((int) $j['technician_id']) ?>"><?= $e($initials($tech['first_name'], $tech['last_name'])) ?></span>
                                        <?php endif; ?>
                                        <span class="kn-cal-pill-body">
                                            <span class="kn-cal-time"><?= $e($j['start_local'] ?? '') ?></span> <?= $e($j['customer']) ?>
                                        </span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>
    <script src="/assets/vendor/leaflet/leaflet.js"></script>
    <script src="/assets/admin-job-panel.js"></script>
    <script src="/assets/admin-reschedule.js"></script>
</body>
</html>
