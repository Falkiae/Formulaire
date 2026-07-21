<?php
/**
 * Vue : factures.
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
    <title>Factures — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <?php include dirname(__DIR__) . '/_nav.php'; ?>

    <main class="kn-wrap">
        <?php if (!empty($data['flash'])): ?><p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p><?php endif; ?>
        <h1>Factures</h1>

        <p>
            <a class="kn-btn kn-btn-ghost" href="/admin/factures/journal">Exporter le journal des recettes (CSV)</a>
        </p>

        <?php if ($data['to_invoice'] !== []): ?>
            <section class="kn-card" style="margin-bottom:24px;">
                <h2>Commandes à facturer</h2>
                <div class="kn-table-wrap">
                    <table class="kn-table">
                        <?php foreach ($data['to_invoice'] as $b): ?>
                            <tr>
                                <td><?= $e($b['reference']) ?></td>
                                <td class="kn-num"><?= $e($eur((int) $b['total_cents'])) ?> €</td>
                                <td>
                                    <form method="post" action="/admin/factures/commande/<?= (int) $b['id'] ?>">
                                        <?= $data['csrf'] ?>
                                        <button class="kn-btn kn-btn-primary kn-btn-sm">Générer la facture</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <section class="kn-card">
            <h2>Factures émises</h2>
            <div class="kn-table-wrap">
                <table class="kn-table">
                    <thead><tr><th>Numéro</th><th>Client</th><th>Type</th><th class="kn-num">TVAC</th><th>Peppol</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($data['invoices'] as $i): ?>
                            <tr>
                                <td><?= $e($i['number']) ?><br><span class="kn-muted"><?= $e(substr((string) $i['issued_at'], 0, 10)) ?></span></td>
                                <td><?= $e($i['customer']) ?></td>
                                <td><span class="kn-badge"><?= $i['invoice_type'] === 'simplified' ? 'simplifiée' : 'complète' ?></span></td>
                                <td class="kn-num"><?= $e($eur((int) $i['total_cents'])) ?> €</td>
                                <td>
                                    <?php if ($i['peppol_status'] === 'not_applicable'): ?><span class="kn-muted">—</span>
                                    <?php else: ?><span class="kn-badge kn-badge-onsite"><?= $e($i['peppol_status']) ?></span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($i['peppol_status'] !== 'not_applicable'): ?>
                                        <a href="/admin/factures/<?= (int) $i['id'] ?>/ubl">Télécharger UBL</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($data['invoices'] === []): ?><tr><td colspan="6" class="kn-muted">Aucune facture.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
