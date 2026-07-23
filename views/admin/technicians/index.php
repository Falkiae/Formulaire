<?php
/**
 * Vue : liste des techniciens.
 * @var array<string,mixed> $data
 * @var callable $e
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Techniciens — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <?php include dirname(__DIR__) . '/_nav.php'; ?>

    <main class="kn-wrap">
        <?php if (!empty($data['flash'])): ?><p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p><?php endif; ?>

        <div class="kn-daynav">
            <h1 style="margin:0;">Techniciens</h1>
            <a class="kn-btn kn-btn-primary kn-btn-sm" href="/admin/techniciens/nouveau">+ Nouveau technicien</a>
        </div>
        <p class="kn-muted">Fiches, compétences, disponibilités récurrentes et absences. Ces données alimentent la proposition de créneaux du tunnel de réservation.</p>

        <section class="kn-card">
            <div class="kn-table-wrap">
                <table class="kn-table">
                    <thead>
                        <tr><th>Nom</th><th>Téléphone</th><th>Compte terrain</th><th class="kn-num">Jobs/jour</th><th>État</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['technicians'] as $t): ?>
                            <tr>
                                <td><a href="/admin/techniciens/<?= (int) $t['id'] ?>"><?= $e(trim($t['first_name'] . ' ' . $t['last_name'])) ?></a></td>
                                <td class="kn-muted"><?= $e($t['phone'] ?? '—') ?></td>
                                <td class="kn-muted"><?= $e($t['account_email'] ?? '—') ?></td>
                                <td class="kn-num"><?= (int) $t['max_jobs_per_day'] ?></td>
                                <td>
                                    <?php if ((int) $t['is_active'] === 1): ?>
                                        <span class="kn-badge kn-badge-onsite">actif</span>
                                    <?php else: ?>
                                        <span class="kn-badge kn-badge-off">inactif</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($data['technicians'] === []): ?><tr><td colspan="5" class="kn-muted">Aucun technicien.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
