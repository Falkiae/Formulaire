<?php
/**
 * Vue : catalogue back-office — arborescence des catégories + liste des services.
 *
 * @var array<string,mixed> $data
 * @var callable $e
 */

/** Rend récursivement l'arborescence des catégories. */
$renderTree = static function (array $nodes, callable $e) use (&$renderTree): string {
    if ($nodes === []) {
        return '';
    }
    $html = '<ul>';
    foreach ($nodes as $node) {
        $vis = ((int) $node['is_visible'] === 1) ? '' : ' <span class="kn-badge kn-badge-off">masquée</span>';
        $html .= '<li>' . $e($node['name']) . $vis;
        $html .= $renderTree($node['children'] ?? [], $e);
        $html .= '</li>';
    }

    return $html . '</ul>';
};

$centsToEuros = static fn (int $c): string => number_format($c / 100, 2, ',', ' ');
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Catalogue — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <header class="kn-header">
        <strong>Keepnew · back-office</strong>
        <nav>
            <a href="/admin/catalogue" aria-current="page">Catalogue</a>
            <a href="/admin/simulateur">Simulateur</a>
            <a href="/admin/deconnexion">Déconnexion</a>
        </nav>
    </header>

    <main class="kn-wrap">
        <?php if (!empty($data['flash'])): ?>
            <p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p>
        <?php endif; ?>

        <h1>Catalogue</h1>

        <div class="kn-grid kn-grid-2">
            <section class="kn-card">
                <h2>Catégories</h2>
                <?= $renderTree($data['tree'], $e) ?>
            </section>

            <section class="kn-card">
                <h2>Prestations</h2>
                <table class="kn-table">
                    <thead>
                        <tr>
                            <th>Prestation</th>
                            <th>Catégorie</th>
                            <th class="kn-num">Prix base</th>
                            <th class="kn-num">Durée</th>
                            <th>État</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['services'] as $s): ?>
                            <tr>
                                <td><a href="/admin/catalogue/service/<?= (int) $s['id'] ?>"><?= $e($s['name']) ?></a></td>
                                <td class="kn-muted"><?= $e($s['category_name']) ?></td>
                                <td class="kn-num"><?= $e($centsToEuros((int) $s['base_price_cents'])) ?> €</td>
                                <td class="kn-num"><?= (int) $s['base_duration_min'] ?> min</td>
                                <td>
                                    <?php if ((int) $s['is_active'] === 1): ?>
                                        <span class="kn-badge kn-badge-onsite">actif</span>
                                    <?php else: ?>
                                        <span class="kn-badge kn-badge-off">inactif</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>
    </main>
</body>
</html>
