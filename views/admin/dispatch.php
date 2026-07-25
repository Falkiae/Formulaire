<?php
/**
 * Vue : dispatch — agenda du jour, une colonne par technicien.
 * @var array<string,mixed> $data
 * @var callable $e
 */
$jobsByTech = ['none' => []];
foreach ($data['technicians'] as $t) {
    $jobsByTech[(int) $t['id']] = [];
}
foreach ($data['jobs'] as $j) {
    $key = $j['technician_id'] ?? 'none';
    $jobsByTech[$key][] = $j;
}
$jobCard = static function (array $j, callable $e): string {
    $badge = $j['mode'] === 'onsite' ? 'kn-badge-onsite' : 'kn-badge-workshop';
    $travel = $j['travel_in_min']
        ? '<span class="kn-muted" style="font-size:.8rem;">🚗 ' . (int) $j['travel_in_min'] . ' min</span>'
        : '';
    return '<div class="kn-job" draggable="true" data-id="' . (int) $j['id'] . '">'
        . '<div class="kn-job-time">' . $e($j['start_local'] ?? '—') . '–' . $e($j['end_local'] ?? '') . ' ' . $travel . '</div>'
        . '<a href="/admin/job/' . (int) $j['id'] . '" style="text-decoration:none;color:inherit;"><strong>' . $e($j['customer']) . '</strong></a> '
        . '<span class="kn-badge ' . $badge . '" style="margin-left:4px;">' . ($j['mode'] === 'onsite' ? 'Domicile' : 'Atelier') . '</span>'
        . '<div class="kn-muted" style="font-size:.85rem;margin-top:4px;">' . $e($j['services']) . '</div>'
        . '<div class="kn-muted" style="font-size:.85rem;">' . $e($j['address']) . '</div>'
        . '</div>';
};
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dispatch — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
    <link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
</head>
<body>
    <?php include __DIR__ . '/_nav.php'; ?>

    <main class="kn-wrap" style="max-width:100%;">
        <div class="kn-daynav">
            <a class="kn-btn kn-btn-ghost kn-btn-sm" href="/admin/dispatch?date=<?= $e($data['prev']) ?>">← Jour précédent</a>
            <h1 style="margin:0;font-size:var(--kn-fs-2);"><?= $e($data['date']) ?></h1>
            <a class="kn-btn kn-btn-ghost kn-btn-sm" href="/admin/dispatch?date=<?= $e($data['next']) ?>">Jour suivant →</a>
        </div>

        <p class="kn-muted" style="margin-bottom:var(--kn-space-4);font-size:var(--kn-fs-small);">Glissez un rendez-vous vers une autre colonne pour le réassigner. Le trajet est recalculé automatiquement.</p>
        <div id="kn-alert-zone"></div>

        <div class="kn-dispatch">
            <?php foreach ($data['technicians'] as $t): ?>
                <section class="kn-col" data-tech="<?= (int) $t['id'] ?>">
                    <h3><?= $e($t['first_name'] . ' ' . $t['last_name']) ?></h3>
                    <?php foreach ($jobsByTech[(int) $t['id']] as $j): ?>
                        <?= $jobCard($j, $e) ?>
                    <?php endforeach; ?>
                    <?php if ($jobsByTech[(int) $t['id']] === []): ?>
                        <p class="kn-muted" style="font-size:.85rem;padding:8px 4px;">Aucun job</p>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

            <?php if ($jobsByTech['none'] !== [] || $data['unassigned'] !== []): ?>
                <section class="kn-col" data-tech="none" style="background:var(--kn-blush);border-color:transparent;">
                    <h3>À assigner</h3>
                    <?php foreach ($jobsByTech['none'] as $j): ?><?= $jobCard($j, $e) ?><?php endforeach; ?>
                    <?php foreach ($data['unassigned'] as $j): ?><?= $jobCard($j, $e) ?><?php endforeach; ?>
                </section>
            <?php endif; ?>
        </div>
    </main>

    <script>
    (function () {
        "use strict";
        var CSRF = <?= json_encode($data['csrf_token'], JSON_UNESCAPED_SLASHES) ?>;
        var dragged = null;
        var hovered = null;
        document.addEventListener("dragstart", function (e) {
            var job = e.target.closest(".kn-job");
            if (job) dragged = job;
        });
        // Délégation sur .kn-dispatch (plutôt qu'un listener par .kn-col) :
        // reste fonctionnel après un remplacement du DOM (ex. rafraîchissement
        // en arrière-plan depuis le panneau job, admin-job-panel.js), sans
        // avoir à ré-attacher quoi que ce soit.
        var board = document.querySelector(".kn-dispatch");
        if (board) {
            board.addEventListener("dragover", function (e) {
                var col = e.target.closest(".kn-col");
                if (!col) return;
                e.preventDefault();
                if (hovered && hovered !== col) hovered.classList.remove("drag-over");
                col.classList.add("drag-over");
                hovered = col;
            });
            board.addEventListener("dragleave", function (e) {
                var col = e.target.closest(".kn-col");
                if (col && col === hovered && !col.contains(e.relatedTarget)) {
                    col.classList.remove("drag-over");
                    hovered = null;
                }
            });
            board.addEventListener("drop", function (e) {
                var col = e.target.closest(".kn-col");
                if (!col) return;
                e.preventDefault();
                col.classList.remove("drag-over");
                hovered = null;
                if (!dragged) return;
                var techId = col.getAttribute("data-tech");
                if (techId === "none") return;
                var jobId = dragged.getAttribute("data-id");
                col.appendChild(dragged);
                fetch("/admin/dispatch/reassign", {
                    method: "POST",
                    headers: { "Content-Type": "application/json", "X-CSRF-Token": CSRF, "Accept": "application/json" },
                    body: JSON.stringify({ job_id: parseInt(jobId, 10), technician_id: parseInt(techId, 10) }),
                }).then(function (r) { return r.json().then(function (d) { return { status: r.status, d: d }; }); })
                  .then(function (res) {
                      var zone = document.getElementById("kn-alert-zone");
                      if (res.status === 409 || res.d.conflict) {
                          zone.innerHTML = '<p class="kn-alert kn-alert-error">Conflit : ce technicien a déjà un rendez-vous sur ce créneau.</p>';
                      } else if (!res.d.travel_fits) {
                          zone.innerHTML = '<p class="kn-alert kn-alert-warning">Réassigné, mais le trajet ne tient pas dans le planning (' + res.d.travel_in_min + ' min entrée / ' + res.d.travel_out_min + ' min sortie). À vérifier.</p>';
                      } else {
                          zone.innerHTML = '<p class="kn-alert kn-alert-ok">Réassigné. Trajet : ' + res.d.travel_in_min + ' min avant / ' + res.d.travel_out_min + ' min après.</p>';
                      }
                  });
            });
        }
    })();
    </script>
    <script src="/assets/vendor/leaflet/leaflet.js"></script>
    <script src="/assets/admin-job-panel.js"></script>
    <script src="/assets/admin-reschedule.js"></script>
</body>
</html>
