<?php
/**
 * Vue : dispatch — agenda du jour, une colonne par technicien.
 * Réassignation par glisser-déposer d'un job vers une autre colonne, avec
 * recalcul du trajet et alerte de conflit.
 *
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
    $travel = '';
    if ($j['travel_in_min']) {
        $travel = '<span class="kn-muted">🚗 ' . (int) $j['travel_in_min'] . ' min</span>';
    }
    return '<div class="kn-job" draggable="true" data-id="' . (int) $j['id'] . '">'
        . '<div class="kn-job-time">' . $e($j['start_local'] ?? '—') . '–' . $e($j['end_local'] ?? '') . ' ' . $travel . '</div>'
        . '<a href="/admin/job/' . (int) $j['id'] . '"><strong>' . $e($j['customer']) . '</strong></a> '
        . '<span class="kn-badge ' . $badge . '">' . ($j['mode'] === 'onsite' ? 'Domicile' : 'Atelier') . '</span>'
        . '<div class="kn-muted">' . $e($j['services']) . '</div>'
        . '<div class="kn-muted">' . $e($j['address']) . '</div>'
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
    <style>
        .kn-dispatch { display: flex; gap: 12px; overflow-x: auto; padding-bottom: 16px; }
        .kn-col { min-width: 240px; flex: 0 0 240px; background: var(--kn-surface); border: 1px solid var(--kn-line); border-radius: 12px; padding: 8px; }
        .kn-col h3 { margin: 4px 8px 8px; font-size: 1rem; }
        .kn-col.drag-over { outline: 2px dashed var(--kn-accent); }
        .kn-job { background: var(--kn-paper); border: 1px solid var(--kn-line); border-radius: 8px; padding: 8px; margin-bottom: 8px; cursor: grab; }
        .kn-job-time { font-variant-numeric: tabular-nums; font-size: .85rem; color: var(--kn-accent-ink); }
        .kn-daynav { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
    </style>
</head>
<body>
    <header class="kn-header">
        <strong>Keepnew · back-office</strong>
        <nav>
            <a href="/admin/dispatch" aria-current="page">Dispatch</a>
            <a href="/admin/clients">Clients</a>
            <a href="/admin/ateliers">Ateliers</a>
            <a href="/admin/catalogue">Catalogue</a>
            <a href="/admin/deconnexion">Déconnexion</a>
        </nav>
    </header>

    <main class="kn-wrap" style="max-width:100%;">
        <div class="kn-daynav">
            <a class="kn-btn kn-btn-ghost" href="/admin/dispatch?date=<?= $e($data['prev']) ?>">← Jour précédent</a>
            <h1 style="margin:0;"><?= $e($data['date']) ?></h1>
            <a class="kn-btn kn-btn-ghost" href="/admin/dispatch?date=<?= $e($data['next']) ?>">Jour suivant →</a>
        </div>

        <p class="kn-muted">Glissez un rendez-vous d'une colonne à l'autre pour le réassigner. Le trajet est recalculé et un conflit est signalé.</p>
        <div id="kn-alert-zone"></div>

        <div class="kn-dispatch">
            <?php foreach ($data['technicians'] as $t): ?>
                <section class="kn-col" data-tech="<?= (int) $t['id'] ?>">
                    <h3><?= $e($t['first_name'] . ' ' . $t['last_name']) ?></h3>
                    <?php foreach ($jobsByTech[(int) $t['id']] as $j): ?>
                        <?= $jobCard($j, $e) ?>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>

            <?php if ($jobsByTech['none'] !== [] || $data['unassigned'] !== []): ?>
                <section class="kn-col" data-tech="none" style="background:var(--kn-blush);">
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
        document.addEventListener("dragstart", function (e) {
            var job = e.target.closest(".kn-job");
            if (job) dragged = job;
        });
        document.querySelectorAll(".kn-col").forEach(function (col) {
            col.addEventListener("dragover", function (e) { e.preventDefault(); col.classList.add("drag-over"); });
            col.addEventListener("dragleave", function () { col.classList.remove("drag-over"); });
            col.addEventListener("drop", function (e) {
                e.preventDefault();
                col.classList.remove("drag-over");
                if (!dragged) return;
                var techId = col.getAttribute("data-tech");
                if (techId === "none") return; // on ne réassigne pas vers « à assigner »
                var jobId = dragged.getAttribute("data-id");
                col.appendChild(dragged); // déplacement optimiste
                fetch("/admin/dispatch/reassign", {
                    method: "POST",
                    headers: { "Content-Type": "application/json", "X-CSRF-Token": CSRF, "Accept": "application/json" },
                    body: JSON.stringify({ job_id: parseInt(jobId, 10), technician_id: parseInt(techId, 10) }),
                }).then(function (r) { return r.json().then(function (d) { return { status: r.status, d: d }; }); })
                  .then(function (res) {
                      var zone = document.getElementById("kn-alert-zone");
                      if (res.status === 409 || res.d.conflict) {
                          zone.innerHTML = '<p class="kn-alert kn-alert-error">Conflit : ce technicien a déjà un rendez-vous sur ce créneau. Rechargez la page.</p>';
                      } else if (!res.d.travel_fits) {
                          zone.innerHTML = '<p class="kn-alert kn-alert-error">Réassigné, mais le trajet ne tient pas dans le planning (entrée ' + res.d.travel_in_min + ' min / sortie ' + res.d.travel_out_min + ' min). À vérifier.</p>';
                      } else {
                          zone.innerHTML = '<p class="kn-alert kn-alert-ok">Réassigné. Trajet : ' + res.d.travel_in_min + ' min avant / ' + res.d.travel_out_min + ' min après.</p>';
                      }
                  });
            });
        });
    })();
    </script>
</body>
</html>
