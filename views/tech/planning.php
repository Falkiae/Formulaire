<?php
/**
 * Vue : planning du jour de l'app technicien (PWA).
 * @var array<string,mixed> $data
 * @var callable $e
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#4A63E7">
    <title>Ma journée — Keepnew</title>
    <link rel="manifest" href="/tech.webmanifest">
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
    <style>
        body { max-width: 520px; margin: 0 auto; }
    </style>
</head>
<body>
    <div class="kn-tech-head">
        <div><strong>Bonjour <?= $e($data['tech']['first_name']) ?></strong><br><span class="kn-muted"><?= $e($data['date']) ?></span></div>
        <a class="kn-btn kn-btn-ghost" href="/admin/deconnexion" style="min-height:40px;">Quitter</a>
    </div>

    <main>
        <?php if ($data['jobs'] === []): ?>
            <p class="kn-muted" style="padding:0 16px;">Aucun rendez-vous aujourd'hui. Bonne journée !</p>
        <?php endif; ?>
        <?php foreach ($data['jobs'] as $j): ?>
            <a class="kn-jobcard" href="/tech/job/<?= (int) $j['id'] ?>">
                <div style="display:flex;justify-content:space-between;">
                    <span class="t"><?= $e($j['start_local'] ?? '—') ?></span>
                    <span class="kn-status <?= $e($j['status']) ?>"><?= $e($j['status']) ?></span>
                </div>
                <strong><?= $e($j['customer']) ?></strong>
                <span class="kn-badge kn-badge-<?= $j['mode'] ?>"><?= $j['mode'] === 'onsite' ? 'Domicile' : 'Atelier' ?></span>
                <div class="kn-muted"><?= $e($j['services']) ?></div>
                <div class="kn-muted"><?= $e($j['address']) ?></div>
            </a>
        <?php endforeach; ?>
    </main>

    <script>
        if ("serviceWorker" in navigator) {
            navigator.serviceWorker.register("/tech-sw.js").catch(function () {});
        }
    </script>
</body>
</html>
