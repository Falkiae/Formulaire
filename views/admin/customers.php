<?php
/**
 * Vue : liste des clients (LTV, fréquence, segment).
 * @var array<string,mixed> $data
 * @var callable $e
 */
$eur = static fn (int $c): string => number_format($c / 100, 2, ',', ' ');
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Clients — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <?php include __DIR__ . '/_nav.php'; ?>

    <main class="kn-wrap">
        <?php if (!empty($data['flash'])): ?><p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p><?php endif; ?>

        <div class="kn-daynav">
            <h1 style="margin:0;">Clients</h1>
            <?php if (!empty($data['can_edit'])): ?>
                <a class="kn-btn kn-btn-primary kn-btn-sm" href="/admin/clients/nouveau">+ Nouveau client</a>
            <?php endif; ?>
        </div>
        <form method="get" action="/admin/clients" class="kn-row" style="max-width:560px;margin-bottom:var(--kn-space-4);">
            <div class="kn-field" style="flex:1 1 220px;margin:0;">
                <label for="q">Rechercher un client</label>
                <input type="search" id="q" name="q" value="<?= $e($data['q']) ?>" placeholder="Prénom, nom, e-mail ou société">
            </div>
            <button type="submit" class="kn-btn kn-btn-ghost">Rechercher</button>
            <?php if ($data['q'] !== ''): ?>
                <a class="kn-btn kn-btn-ghost" href="/admin/clients">Effacer</a>
            <?php endif; ?>
        </form>

        <p class="kn-muted">
            <?php if ($data['q'] !== ''): ?>
                <?= (int) $data['total'] ?> client<?= $data['total'] > 1 ? 's' : '' ?> pour « <?= $e($data['q']) ?> »<?php if ($data['pages'] > 1): ?> · page <?= (int) $data['page'] ?> sur <?= (int) $data['pages'] ?><?php endif; ?>
            <?php else: ?>
                <?= (int) $data['total'] ?> client<?= $data['total'] > 1 ? 's' : '' ?><?php if ($data['pages'] > 1): ?> · page <?= (int) $data['page'] ?> sur <?= (int) $data['pages'] ?><?php endif; ?>
            <?php endif; ?>
        </p>

        <section class="kn-card">
            <div class="kn-table-wrap">
                <table class="kn-table kn-table-sticky">
                    <thead><tr><th>Client</th><th>Segment</th><th class="kn-num">Commandes</th><th class="kn-num">LTV</th><th>Dernière</th></tr></thead>
                    <tbody>
                        <?php foreach ($data['customers'] as $c): ?>
                            <?php $name = $c['type'] === 'b2b' && $c['company_name'] ? $c['company_name'] : trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')); ?>
                            <tr>
                                <td><a href="/admin/client/<?= (int) $c['id'] ?>"><?= $e($name ?: $c['email']) ?></a><br><span class="kn-muted"><?= $e($c['email']) ?></span></td>
                                <td><span class="kn-badge"><?= $c['type'] === 'b2b' ? 'Professionnel' : 'Particulier' ?></span></td>
                                <td class="kn-num"><?= (int) $c['bookings_count'] ?></td>
                                <td class="kn-num"><?= $e($eur((int) $c['ltv_cents'])) ?> €</td>
                                <td class="kn-muted"><?= $e($c['last_booking'] ? substr((string) $c['last_booking'], 0, 10) : '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($data['customers'] === []): ?>
                            <tr><td colspan="5" class="kn-muted">
                                <?php if ($data['q'] !== ''): ?>
                                    Aucun client ne correspond à « <?= $e($data['q']) ?> ». <a href="/admin/clients">Voir tous les clients</a>.
                                <?php else: ?>
                                    Aucun client pour l'instant. Ils apparaissent ici dès la première réservation.
                                <?php endif; ?>
                            </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if ($data['pages'] > 1): ?>
            <?php $qs = static fn (int $p): string => '/admin/clients?' . http_build_query(array_filter(['q' => $data['q'], 'page' => $p > 1 ? $p : null])); ?>
            <nav class="kn-row kn-mt" aria-label="Pagination des clients">
                <?php if ($data['page'] > 1): ?>
                    <a class="kn-btn kn-btn-ghost kn-btn-sm" href="<?= $e($qs($data['page'] - 1)) ?>" rel="prev">← Précédents</a>
                <?php endif; ?>
                <span class="kn-muted">Page <?= (int) $data['page'] ?> sur <?= (int) $data['pages'] ?></span>
                <?php if ($data['page'] < $data['pages']): ?>
                    <a class="kn-btn kn-btn-ghost kn-btn-sm" href="<?= $e($qs($data['page'] + 1)) ?>" rel="next">Suivants →</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </main>
</body>
</html>
