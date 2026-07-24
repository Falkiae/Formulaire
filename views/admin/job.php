<?php
/**
 * Vue : fiche job — page complète (nav + panneau). Repli sans JS / mobile du
 * panneau coulissant ouvert depuis le calendrier/dispatch : même contenu
 * (admin/job/_panel), juste affiché en pleine page ici.
 * @var array<string,mixed> $data
 * @var callable $e
 */
$j = $data['job'];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Job <?= $e($j['reference']) ?> — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
    <link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
</head>
<body>
    <?php include __DIR__ . '/_nav.php'; ?>

    <main class="kn-wrap">
        <p><a href="/admin/dispatch">← Dispatch</a></p>
        <?php include __DIR__ . '/job/_panel.php'; ?>
    </main>

    <script src="/assets/vendor/leaflet/leaflet.js"></script>
    <script src="/assets/admin-job-panel.js"></script>
    <script src="/assets/admin-reschedule.js"></script>
</body>
</html>
