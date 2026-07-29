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
        .kn-tech-head { display:flex; justify-content:space-between; align-items:center; padding: var(--kn-space-4); }
        .kn-jobcard { display:block; text-decoration:none; color:inherit; background:var(--kn-surface); border:1px solid var(--kn-line); border-radius:var(--kn-radius-card); box-shadow:var(--kn-shadow-card); padding:16px; margin:0 var(--kn-space-4) var(--kn-space-3); }
        .kn-jobcard .t { font-variant-numeric:tabular-nums; font-weight:600; color:var(--kn-accent-ink); }
        .kn-status { font-size:.75rem; font-weight:500; letter-spacing:.02em; padding:2px 10px; border-radius:var(--kn-radius-pill); background:var(--kn-line); }
        .kn-status.completed { background:#E4EFE7; color:var(--kn-success); }
        .kn-status.in_progress { background:var(--kn-blush); color:var(--kn-inverse-deep); }
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
