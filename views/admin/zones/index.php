<?php
/**
 * Vue : liste des zones de service (chalandise).
 * @var array<string,mixed> $data
 * @var callable $e
 */
$typeLabels = ['radius' => 'Rayon', 'postal_codes' => 'Codes postaux', 'polygon' => 'Polygone'];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zones de service — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <?php include dirname(__DIR__) . '/_nav.php'; ?>

    <main class="kn-wrap">
        <?php if (!empty($data['flash'])): ?><p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p><?php endif; ?>

        <div class="kn-daynav">
            <h1 style="margin:0;">Zones de service</h1>
            <a class="kn-btn kn-btn-primary kn-btn-sm" href="/admin/zones/nouvelle">+ Nouvelle zone</a>
        </div>
        <p class="kn-muted">Décide quand un code postal est accepté, refusé, ou soumis à un supplément de déplacement. Les zones sont évaluées par ordre de priorité (le plus petit nombre d'abord).</p>

        <section class="kn-card">
            <div class="kn-table-wrap">
                <table class="kn-table">
                    <thead>
                        <tr><th>Nom</th><th>Type</th><th>Couverture</th><th class="kn-num">Priorité</th><th>Techniciens</th><th>État</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['zones'] as $z): ?>
                            <tr>
                                <td><a href="/admin/zones/<?= (int) $z['id'] ?>"><?= $e($z['name']) ?></a></td>
                                <td><span class="kn-badge"><?= $e($typeLabels[$z['zone_type']] ?? $z['zone_type']) ?></span></td>
                                <td class="kn-muted">
                                    <?php if ($z['zone_type'] === 'radius'): ?>
                                        <?= $e($z['radius_km']) ?> km autour de <?= $e($z['center_lat']) ?>, <?= $e($z['center_lng']) ?>
                                    <?php else: ?>
                                        <?= (int) $z['postal_count'] ?> code(s) postal(aux)
                                    <?php endif; ?>
                                </td>
                                <td class="kn-num"><?= (int) $z['priority'] ?></td>
                                <td class="kn-num"><?= (int) $z['technician_count'] ?></td>
                                <td>
                                    <?php if ((int) $z['is_active'] === 1): ?>
                                        <span class="kn-badge kn-badge-onsite">active</span>
                                    <?php else: ?>
                                        <span class="kn-badge kn-badge-off">inactive</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($data['zones'] === []): ?><tr><td colspan="6" class="kn-muted">Aucune zone.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
