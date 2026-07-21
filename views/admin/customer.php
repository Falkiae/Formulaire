<?php
/**
 * Vue : fiche client.
 * @var array<string,mixed> $data
 * @var callable $e
 */
$c = $data['customer'];
$eur = static fn (int $v): string => number_format($v / 100, 2, ',', ' ');
$name = $c['type'] === 'b2b' && $c['company_name'] ? $c['company_name'] : trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($name) ?> — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <header class="kn-header">
        <strong>Keepnew · back-office</strong>
        <nav>
            <a href="/admin/dispatch">Dispatch</a>
            <a href="/admin/clients">Clients</a>
            <a href="/admin/ateliers">Ateliers</a>
            <a href="/admin/catalogue">Catalogue</a>
            <a href="/admin/deconnexion">Déconnexion</a>
        </nav>
    </header>

    <main class="kn-wrap">
        <p><a href="/admin/clients">← Clients</a></p>
        <h1><?= $e($name ?: $c['email']) ?></h1>
        <p>
            <span class="kn-badge"><?= strtoupper($e($c['type'])) ?></span>
            · <?= $e($c['email']) ?> · <?= $e($c['phone'] ?? '') ?>
            <?php if ($c['vat_number']): ?> · TVA <?= $e($c['vat_number']) ?><?php endif; ?>
        </p>
        <p><strong>LTV :</strong> <?= $e($eur((int) $data['ltv_cents'])) ?> € · <strong><?= count($data['bookings']) ?></strong> commande(s)</p>

        <div class="kn-grid kn-grid-2">
            <section class="kn-card">
                <h2>Commandes</h2>
                <table class="kn-table">
                    <?php foreach ($data['bookings'] as $b): ?>
                        <tr>
                            <td><?= $e($b['reference']) ?><br><span class="kn-muted"><?= $e(substr((string) $b['created_at'], 0, 10)) ?></span></td>
                            <td><span class="kn-badge"><?= $e($b['status']) ?></span></td>
                            <td class="kn-num"><?= $e($eur((int) $b['total_cents'])) ?> €</td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($data['bookings'] === []): ?><tr><td class="kn-muted">Aucune commande.</td></tr><?php endif; ?>
                </table>
            </section>

            <section class="kn-card">
                <h2>Adresses</h2>
                <?php foreach ($data['addresses'] as $a): ?>
                    <p><?= $e(trim(($a['street'] ?? '') . ' ' . ($a['number'] ?? '') . ', ' . ($a['postal_code'] ?? '') . ' ' . ($a['city'] ?? ''))) ?></p>
                <?php endforeach; ?>
                <?php if ($data['addresses'] === []): ?><p class="kn-muted">Aucune.</p><?php endif; ?>

                <h3 class="kn-h3">Notes</h3>
                <?php foreach ($data['notes'] as $n): ?>
                    <p class="kn-muted"><?= $e(substr((string) $n['created_at'], 0, 10)) ?> (<?= $e($n['first_name'] ?? 'système') ?>) : <?= $e($n['body']) ?></p>
                <?php endforeach; ?>
                <?php if ($data['notes'] === []): ?><p class="kn-muted">Aucune note.</p><?php endif; ?>
            </section>
        </div>
    </main>
</body>
</html>
