<?php
/**
 * Vue : fiche job de l'app technicien (pointage, statut, photos, signature, encaissement).
 * @var array<string,mixed> $data
 * @var callable $e
 */
$j = $data['job'];
$eur = static fn (int $c): string => number_format($c / 100, 2, ',', ' ');
$maps = 'https://www.google.com/maps/dir/?api=1&destination=' . urlencode(($j['lat'] && $j['lng']) ? ($j['lat'] . ',' . $j['lng']) : ($j['street'] . ' ' . $j['postal_code'] . ' ' . $j['city']));
$waze = ($j['lat'] && $j['lng']) ? ('https://waze.com/ul?ll=' . $j['lat'] . ',' . $j['lng'] . '&navigate=yes') : null;
$isStart = $data['last_punch'] !== 'start';
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#4A63E7">
    <title><?= $e($j['reference']) ?> — Keepnew</title>
    <link rel="manifest" href="/tech.webmanifest">
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
    <style>
        body { max-width: 520px; margin: 0 auto; padding-bottom: 40px; }
        .kn-wrap2 { padding: 16px; }
        .kn-big { width:100%; min-height:64px; font-size:1.1rem; }
        canvas { border:1px solid var(--kn-line); border-radius:var(--kn-radius-control); width:100%; height:160px; touch-action:none; background:#fff; }
        .kn-row2 { display:flex; gap:8px; }
        .kn-row2 > * { flex:1; }
    </style>
</head>
<body>
    <div class="kn-wrap2">
        <p><a href="/tech">← Ma journée</a></p>
        <h1 style="font-size:1.3rem;"><?= $e($j['reference']) ?> · <?= $e($j['first_name'] . ' ' . $j['last_name']) ?></h1>
        <p>
            <span class="kn-badge kn-badge-<?= $j['mode'] ?>"><?= $j['mode'] === 'onsite' ? 'À domicile' : 'Atelier' ?></span>
            · statut <strong><?= $e($j['status']) ?></strong>
        </p>
        <p class="kn-muted"><?= $e($j['services']) ?></p>
        <p><?= $e(trim(($j['street'] ?? '') . ' ' . ($j['number'] ?? '') . ', ' . ($j['postal_code'] ?? '') . ' ' . ($j['city'] ?? ''))) ?></p>
        <?php if (!empty($j['access_notes'])): ?><p class="kn-muted">Accès : <?= $e($j['access_notes']) ?></p><?php endif; ?>
        <?php if (!empty($j['phone'])): ?><p><a href="tel:<?= $e($j['phone']) ?>">Appeler · <?= $e($j['phone']) ?></a></p><?php endif; ?>

        <div class="kn-row2" style="margin-bottom:16px;">
            <a class="kn-btn kn-btn-ghost" href="<?= $e($maps) ?>" target="_blank" rel="noopener">Google Maps</a>
            <?php if ($waze): ?><a class="kn-btn kn-btn-ghost" href="<?= $e($waze) ?>" target="_blank" rel="noopener">Waze</a><?php endif; ?>
        </div>

        <!-- Pointage CP 121 -->
        <section class="kn-card" style="margin-bottom:16px;">
            <h2>Pointage</h2>
            <p class="kn-muted">Dernier : <?= $e($data['last_punch'] ?? 'aucun') ?></p>
            <form method="post" action="/tech/job/<?= (int) $j['id'] ?>/pointer" id="punch-form">
                <?= $data['csrf'] ?>
                <input type="hidden" name="type" value="<?= $isStart ? 'start' : 'stop' ?>">
                <input type="hidden" name="lat" id="p-lat"><input type="hidden" name="lng" id="p-lng">
                <button class="kn-btn <?= $isStart ? 'kn-btn-primary' : 'kn-btn-ghost' ?> kn-big" type="submit">
                    <?= $isStart ? '▶ Démarrer la prestation' : '⏹ Terminer le pointage' ?>
                </button>
            </form>
        </section>

        <!-- Statut -->
        <section class="kn-card" style="margin-bottom:16px;">
            <h2>Statut</h2>
            <div class="kn-row2">
                <?php foreach (['en_route' => 'En route', 'in_progress' => 'En cours', 'completed' => 'Terminé'] as $s => $label): ?>
                    <form method="post" action="/tech/job/<?= (int) $j['id'] ?>/statut">
                        <?= $data['csrf'] ?>
                        <input type="hidden" name="status" value="<?= $s ?>">
                        <button class="kn-btn kn-btn-ghost" type="submit"><?= $e($label) ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Photos avant / après -->
        <section class="kn-card" style="margin-bottom:16px;">
            <h2>Photos</h2>
            <div class="kn-row2">
                <?php foreach (['before' => 'Avant', 'after' => 'Après'] as $kind => $label): ?>
                    <form method="post" action="/tech/job/<?= (int) $j['id'] ?>/photo" enctype="multipart/form-data">
                        <?= $data['csrf'] ?>
                        <input type="hidden" name="kind" value="<?= $kind ?>">
                        <label class="kn-btn kn-btn-ghost" style="cursor:pointer;display:block;text-align:center;">
                            <?= $e($label) ?>
                            <input type="file" name="photo" accept="image/*" capture="environment" style="display:none;" onchange="this.form.submit()">
                        </label>
                    </form>
                <?php endforeach; ?>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
                <?php foreach ($data['photos'] as $ph): ?>
                    <img src="/tech/photo/<?= (int) $ph['id'] ?>" alt="<?= $e($ph['kind']) ?>" width="72" height="72" style="object-fit:cover;border-radius:8px;">
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Signature client -->
        <section class="kn-card" style="margin-bottom:16px;">
            <h2>Signature du client</h2>
            <canvas id="sig"></canvas>
            <form method="post" action="/tech/job/<?= (int) $j['id'] ?>/signature" id="sig-form" style="margin-top:8px;">
                <?= $data['csrf'] ?>
                <input type="hidden" name="signature" id="sig-data">
                <div class="kn-row2">
                    <button type="button" class="kn-btn kn-btn-ghost" id="sig-clear">Effacer</button>
                    <button type="submit" class="kn-btn kn-btn-primary">Enregistrer</button>
                </div>
            </form>
        </section>

        <!-- Encaissement -->
        <section class="kn-card">
            <h2>Encaissement</h2>
            <?php if (($data['payment']['status'] ?? '') === 'paid'): ?>
                <p class="kn-alert kn-alert-ok">Payé (<?= $e($data['payment']['method'] ?? '') ?>) — <?= $e($eur((int) $data['payment']['amount_cents'])) ?> €</p>
            <?php else: ?>
                <p>Montant dû : <strong><?= $e($eur((int) $j['total_cents'])) ?> €</strong></p>
                <div class="kn-row2">
                    <?php foreach (['cash' => 'Espèces', 'bancontact' => 'Bancontact'] as $m => $label): ?>
                        <form method="post" action="/tech/job/<?= (int) $j['id'] ?>/encaisser">
                            <?= $data['csrf'] ?>
                            <input type="hidden" name="method" value="<?= $m ?>">
                            <button class="kn-btn kn-btn-primary" type="submit"><?= $e($label) ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <script>
        // Géolocalisation du pointage (best effort).
        var pf = document.getElementById("punch-form");
        if (pf && navigator.geolocation) {
            pf.addEventListener("submit", function () {}, false);
            navigator.geolocation.getCurrentPosition(function (pos) {
                document.getElementById("p-lat").value = pos.coords.latitude;
                document.getElementById("p-lng").value = pos.coords.longitude;
            });
        }
        // Signature au doigt.
        (function () {
            var c = document.getElementById("sig"); if (!c) return;
            var ctx = c.getContext("2d"); var drawing = false;
            function resize() { c.width = c.offsetWidth; c.height = 160; ctx.lineWidth = 2; ctx.lineCap = "round"; ctx.strokeStyle = "#1B2A4A"; }
            resize();
            function pos(e) { var r = c.getBoundingClientRect(); var t = e.touches ? e.touches[0] : e; return { x: t.clientX - r.left, y: t.clientY - r.top }; }
            function start(e) { drawing = true; var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); }
            function move(e) { if (!drawing) return; var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); }
            function end() { drawing = false; }
            c.addEventListener("mousedown", start); c.addEventListener("mousemove", move); window.addEventListener("mouseup", end);
            c.addEventListener("touchstart", start); c.addEventListener("touchmove", move); c.addEventListener("touchend", end);
            document.getElementById("sig-clear").addEventListener("click", function () { ctx.clearRect(0, 0, c.width, c.height); });
            document.getElementById("sig-form").addEventListener("submit", function () { document.getElementById("sig-data").value = c.toDataURL("image/png"); });
        })();
    </script>
</body>
</html>
