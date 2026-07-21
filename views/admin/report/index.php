<?php
/**
 * Vue : rapports.
 * @var array<string,mixed> $data
 * @var callable $e
 */
$eur = static fn (int $c): string => number_format($c / 100, 2, ',', ' ');
$t = $data['totals'];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rapports — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
    <style>
        .kn-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; }
        .kn-kpi { background:var(--kn-surface); border:1px solid var(--kn-line); border-radius:12px; padding:16px; }
        .kn-kpi .v { font-size:1.6rem; font-weight:700; font-variant-numeric:tabular-nums; }
        .kn-kpi .l { color:var(--kn-muted); font-size:.85rem; }
    </style>
</head>
<body>
    <header class="kn-header">
        <strong>Keepnew · back-office</strong>
        <nav>
            <a href="/admin/dispatch">Dispatch</a>
            <a href="/admin/clients">Clients</a>
            <a href="/admin/rapports" aria-current="page">Rapports</a>
            <a href="/admin/factures">Factures</a>
            <a href="/admin/catalogue">Catalogue</a>
            <a href="/admin/deconnexion">Déconnexion</a>
        </nav>
    </header>
    <main class="kn-wrap">
        <h1>Rapports</h1>

        <div class="kn-kpis">
            <div class="kn-kpi"><div class="v"><?= $e($eur((int) $t['revenue_cents'])) ?> €</div><div class="l">Chiffre d'affaires</div></div>
            <div class="kn-kpi"><div class="v"><?= (int) $t['bookings'] ?></div><div class="l">Réservations</div></div>
            <div class="kn-kpi"><div class="v"><?= $e($eur((int) $t['avg_basket_cents'])) ?> €</div><div class="l">Panier moyen</div></div>
            <div class="kn-kpi"><div class="v"><?= $e((string) $data['conversion_rate']) ?> %</div><div class="l">Conversion du tunnel</div></div>
            <div class="kn-kpi"><div class="v"><?= $e((string) $data['cancel_rate']) ?> %</div><div class="l">Taux d'annulation</div></div>
            <div class="kn-kpi"><div class="v"><?= $e(number_format((float) $t['km'], 0, ',', ' ')) ?> km</div><div class="l">Km parcourus</div></div>
        </div>

        <div class="kn-grid kn-grid-2" style="margin-top:24px;">
            <section class="kn-card">
                <h2>CA par prestation</h2>
                <table class="kn-table">
                    <thead><tr><th>Prestation</th><th class="kn-num">Lignes</th><th class="kn-num">CA</th></tr></thead>
                    <tbody>
                        <?php foreach ($data['by_service'] as $r): ?>
                            <tr><td><?= $e($r['name']) ?></td><td class="kn-num"><?= (int) $r['lines_count'] ?></td><td class="kn-num"><?= $e($eur((int) $r['revenue_cents'])) ?> €</td></tr>
                        <?php endforeach; ?>
                        <?php if ($data['by_service'] === []): ?><tr><td colspan="3" class="kn-muted">Aucune donnée.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </section>

            <section class="kn-card">
                <h2>CA par technicien</h2>
                <table class="kn-table">
                    <thead><tr><th>Technicien</th><th class="kn-num">Jobs</th><th class="kn-num">CA</th></tr></thead>
                    <tbody>
                        <?php foreach ($data['by_technician'] as $r): ?>
                            <tr><td><?= $e($r['first_name'] . ' ' . $r['last_name']) ?></td><td class="kn-num"><?= (int) $r['jobs_count'] ?></td><td class="kn-num"><?= $e($eur((int) $r['revenue_cents'])) ?> €</td></tr>
                        <?php endforeach; ?>
                        <?php if ($data['by_technician'] === []): ?><tr><td colspan="3" class="kn-muted">Aucune donnée.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </section>
        </div>

        <section class="kn-card" style="margin-top:24px;">
            <h2>CA par mois</h2>
            <table class="kn-table">
                <thead><tr><th>Mois</th><th class="kn-num">Réservations</th><th class="kn-num">CA</th></tr></thead>
                <tbody>
                    <?php foreach ($data['by_month'] as $r): ?>
                        <tr><td><?= $e($r['month']) ?></td><td class="kn-num"><?= (int) $r['n'] ?></td><td class="kn-num"><?= $e($eur((int) $r['revenue_cents'])) ?> €</td></tr>
                    <?php endforeach; ?>
                    <?php if ($data['by_month'] === []): ?><tr><td colspan="3" class="kn-muted">Aucune donnée.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </section>
    </main>
</body>
</html>
