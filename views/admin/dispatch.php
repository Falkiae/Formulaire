<?php
/**
 * Vue : planning du jour (liste de RDV + carte), avec la même sidebar de
 * filtres territoire/technicien que le calendrier Semaine/Mois.
 * @var array<string,mixed> $data
 * @var callable $e
 */
$qs = $data['filter_qs'];
$data['base_path'] = '/admin/dispatch';
$data['current'] = $data['date'];
$initials = static fn (string $first, string $last): string =>
    mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
$avatarColor = static fn (int $techId): string =>
    ['#F7D7E2', '#E7ECFB', '#D8F0DF', '#FCE8C8', '#E4D9F7', '#D6EFF3'][$techId % 6];

$durationLabel = static function (?int $min): string {
    if ($min === null || $min <= 0) {
        return '—';
    }
    $h = intdiv($min, 60);
    $m = $min % 60;
    return $h > 0 ? ($m > 0 ? "{$h}h{$m}" : "{$h}h") : "{$m}min";
};
$euro = static fn (int $cents): string => number_format($cents / 100, 2, ',', ' ') . ' €';

$jobCard = static function (array $j) use ($e, $durationLabel): string {
    $badge = $j['mode'] === 'onsite' ? 'kn-badge-onsite' : 'kn-badge-workshop';
    $time = $j['start_local'] !== null
        ? $e($j['start_local'] . '–' . ($j['end_local'] ?? '')) . ' · ' . $e($durationLabel($j['duration_min']))
        : 'Non planifié';
    return '<a class="kn-schedule-card" href="/admin/job/' . (int) $j['id'] . '">'
        . '<div class="kn-schedule-card-head">'
        . '<span class="kn-schedule-ref">Job #' . (int) $j['id'] . '</span>'
        . '<span class="kn-badge kn-badge-' . $e($j['status_variant']) . '">' . $e($j['status_label']) . '</span>'
        . '</div>'
        . '<div class="kn-schedule-time">' . $time . '</div>'
        . '<div><strong>' . $e($j['customer']) . '</strong> <span class="kn-badge ' . $badge . '" style="margin-left:4px;">' . ($j['mode'] === 'onsite' ? 'Domicile' : 'Atelier') . '</span></div>'
        . '<div class="kn-muted" style="font-size:.85rem;margin-top:4px;">' . $e($j['services']) . '</div>'
        . '<div class="kn-muted" style="font-size:.85rem;">' . $e($j['address']) . '</div>'
        . '</a>';
};

$mapJobs = [];
foreach ($data['jobs'] as $j) {
    if ($j['lat'] !== null && $j['lng'] !== null) {
        $mapJobs[] = [
            'lat' => (float) $j['lat'],
            'lng' => (float) $j['lng'],
            'label' => trim(($j['start_local'] ?? '') . ' · ' . $j['customer']),
        ];
    }
}
$summary = $data['summary'];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Planning — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
    <link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
</head>
<body>
    <?php include __DIR__ . '/_nav.php'; ?>

    <main class="kn-wrap" style="max-width:100%;">
        <div class="kn-daynav">
            <a class="kn-btn kn-btn-ghost kn-btn-sm" href="/admin/dispatch?date=<?= $e($data['prev']) . $qs ?>">← Jour précédent</a>
            <h1 style="margin:0;font-size:var(--kn-fs-2);"><?= $e($data['date']) ?></h1>
            <a class="kn-btn kn-btn-ghost kn-btn-sm" href="/admin/dispatch?date=<?= $e($data['next']) . $qs ?>">Jour suivant →</a>
            <div class="kn-cal-toggle">
                <span class="kn-badge kn-badge-onsite">Jour</span>
                <a class="kn-btn kn-btn-ghost kn-btn-sm" href="/admin/calendrier/semaine?date=<?= $e($data['week_of']) . $qs ?>">Semaine</a>
                <a class="kn-btn kn-btn-ghost kn-btn-sm" href="/admin/calendrier?date=<?= $e($data['month_of']) . $qs ?>">Mois</a>
            </div>
        </div>

        <div class="kn-cal-layout">
            <?php include __DIR__ . '/calendar/_sidebar.php'; ?>

            <div class="kn-schedule-main">
                <div class="kn-schedule-summary">
                    <span><strong><?= (int) $summary['job_count'] ?></strong> job<?= $summary['job_count'] > 1 ? 's' : '' ?></span>
                    <span><strong><?= $e($durationLabel($summary['total_duration_min'])) ?></strong> estimées</span>
                    <span><strong><?= $e($euro($summary['total_tvac_cents'])) ?></strong> estimés (TVAC)</span>
                    <?php if ($summary['route_url'] !== null): ?>
                        <a class="kn-btn kn-btn-ghost kn-btn-sm kn-schedule-route" href="<?= $e($summary['route_url']) ?>" target="_blank" rel="noopener">Voir l'itinéraire →</a>
                    <?php endif; ?>
                </div>

                <div class="kn-schedule-row">
                    <div class="kn-schedule-list">
                        <?php if ($data['jobs'] === [] && $data['unassigned'] === []): ?>
                            <p class="kn-muted" style="padding:8px 4px;">Aucun rendez-vous ce jour-là.</p>
                        <?php endif; ?>
                        <?php foreach ($data['jobs'] as $j): ?><?= $jobCard($j) ?><?php endforeach; ?>
                        <?php if ($data['unassigned'] !== []): ?>
                            <h3 style="font-size:var(--kn-fs-small);text-transform:uppercase;letter-spacing:.04em;color:var(--kn-muted);margin:var(--kn-space-3) 0 6px;">Non assigné</h3>
                            <?php foreach ($data['unassigned'] as $j): ?><?= $jobCard($j) ?><?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="kn-schedule-map" id="kn-schedule-map" data-jobs="<?= $e(json_encode($mapJobs, JSON_UNESCAPED_UNICODE)) ?>"></div>
                </div>
            </div>
        </div>
    </main>

    <script src="/assets/vendor/leaflet/leaflet.js"></script>
    <script src="/assets/admin-schedule-map.js"></script>
    <script src="/assets/admin-job-panel.js"></script>
    <script src="/assets/admin-reschedule.js"></script>
</body>
</html>
