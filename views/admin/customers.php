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
    <header class="kn-header">
        <strong>Keepnew · back-office</strong>
        <nav>
            <a href="/admin/dispatch">Dispatch</a>
            <a href="/admin/clients" aria-current="page">Clients</a>
            <a href="/admin/ateliers">Ateliers</a>
            <a href="/admin/catalogue">Catalogue</a>
            <a href="/admin/deconnexion">Déconnexion</a>
        </nav>
    </header>

    <main class="kn-wrap">
        <h1>Clients</h1>
        <form method="get" action="/admin/clients" class="kn-field" style="max-width:360px;">
            <input type="text" name="q" value="<?= $e($data['q']) ?>" placeholder="Rechercher (nom, email, société)">
        </form>

        <section class="kn-card">
            <table class="kn-table">
                <thead><tr><th>Client</th><th>Segment</th><th class="kn-num">Commandes</th><th class="kn-num">LTV</th><th>Dernière</th></tr></thead>
                <tbody>
                    <?php foreach ($data['customers'] as $c): ?>
                        <?php $name = $c['type'] === 'b2b' && $c['company_name'] ? $c['company_name'] : trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')); ?>
                        <tr>
                            <td><a href="/admin/client/<?= (int) $c['id'] ?>"><?= $e($name ?: $c['email']) ?></a><br><span class="kn-muted"><?= $e($c['email']) ?></span></td>
                            <td><span class="kn-badge"><?= strtoupper($e($c['type'])) ?></span></td>
                            <td class="kn-num"><?= (int) $c['bookings_count'] ?></td>
                            <td class="kn-num"><?= $e($eur((int) $c['ltv_cents'])) ?> €</td>
                            <td class="kn-muted"><?= $e($c['last_booking'] ? substr((string) $c['last_booking'], 0, 10) : '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($data['customers'] === []): ?><tr><td colspan="5" class="kn-muted">Aucun client.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </section>
    </main>
</body>
</html>
