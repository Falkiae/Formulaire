<?php
/**
 * Vue : page de diagnostic (réservée admin).
 * @var array<string,mixed> $data
 * @var callable $e
 */
$badge = static function (string $state): string {
    return match ($state) {
        'ok' => 'kn-badge kn-badge-onsite',
        default => 'kn-badge kn-badge-off',
    };
};
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Diagnostic — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <?php include __DIR__ . '/_nav.php'; ?>

    <main class="kn-wrap">
        <h1>Diagnostic</h1>
        <p class="kn-muted">
            État réel du code déployé sur ce serveur. Aucun secret n'est affiché.
            Le fait que cette page s'ouvre prouve déjà que le dernier envoi de fichiers est arrivé.
        </p>

        <section class="kn-card">
            <h2>Fichiers du correctif « app technicien »</h2>
            <div class="kn-table-wrap">
                <table class="kn-table">
                    <thead><tr><th>Fichier</th><th>État</th><th>Détail</th></tr></thead>
                    <tbody>
                        <?php foreach ($data['files'] as $f): ?>
                            <tr>
                                <td><code><?= $e($f['path']) ?></code></td>
                                <td><span class="<?= $badge($f['state']) ?>"><?= $e($f['state']) ?></span></td>
                                <td class="kn-muted"><?= $e($f['detail']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="kn-card">
            <h2>Routes reconnues par l'application</h2>
            <div class="kn-table-wrap">
                <table class="kn-table">
                    <thead><tr><th>Route</th><th>État</th><th>Traitée par</th></tr></thead>
                    <tbody>
                        <?php foreach ($data['routes'] as $r): ?>
                            <tr>
                                <td><code><?= $e($r['route']) ?></code></td>
                                <td><span class="<?= $badge($r['state']) ?>"><?= $r['state'] === 'ok' ? 'reconnue' : 'INCONNUE' ?></span></td>
                                <td class="kn-muted"><?= $e($r['detail']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="kn-muted">
                Si <code>GET /tech</code> est « reconnue » ici mais renvoie tout de même une page
                « not found » dans le navigateur, l'erreur vient du <strong>serveur web</strong>
                (réécriture d'URL), pas de l'application — voir la section suivante.
            </p>
        </section>

        <section class="kn-card">
            <h2>Réécriture d'URL (serveur web)</h2>
            <p>
                <strong>public/.htaccess :</strong> <?= $e($data['rewrite']['htaccess']) ?>
                <span class="kn-muted">(fichier du <?= $e($data['rewrite']['htaccess_date']) ?>)</span>
            </p>

            <?php $shadows = array_filter($data['rewrite']['entries'], static fn (array $x): bool => $x['shadow']); ?>
            <?php if ($shadows !== []): ?>
                <p class="kn-alert kn-alert-error">
                    <strong>Cause trouvée.</strong> <code>public/</code> contient
                    <?= $e(implode(', ', array_map(static fn (array $x): string => $x['type'] . ' « ' . $x['name'] . ' »', $shadows))) ?>.
                    Apache sert cette entrée au lieu de passer la main au routeur : l'URL correspondante
                    renvoie « Not Found ». Supprimez-la par FTP.
                </p>
            <?php else: ?>
                <p class="kn-muted">Aucune entrée de <code>public/</code> ne porte le nom d'une route.</p>
            <?php endif; ?>

            <p class="kn-muted" style="margin-bottom:4px;">Contenu réel de <code>public/</code> sur le serveur :</p>
            <div class="kn-table-wrap">
                <table class="kn-table">
                    <thead><tr><th>Nom</th><th>Type</th></tr></thead>
                    <tbody>
                        <?php foreach ($data['rewrite']['entries'] as $x): ?>
                            <tr<?= $x['shadow'] ? ' style="font-weight:600;"' : '' ?>>
                                <td><code><?= $e($x['name']) ?></code></td>
                                <td class="kn-muted"><?= $e($x['type']) ?><?= $x['shadow'] ? ' — intercepte une route' : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="kn-card">
            <h2>Comptes techniciens</h2>
            <div class="kn-table-wrap">
                <table class="kn-table">
                    <thead><tr><th>Compte</th><th>Actif</th><th>Fiche liée</th><th>Compétences</th><th>Dispos</th><th>RDV</th></tr></thead>
                    <tbody>
                        <?php foreach ($data['technicians'] as $t): ?>
                            <tr>
                                <td><?= $e($t['email']) ?></td>
                                <td><?= (int) $t['is_active'] === 1 ? 'oui' : 'non' ?></td>
                                <td>
                                    <?php if (empty($t['technician_id'])): ?>
                                        <span class="kn-badge kn-badge-off">aucune</span>
                                    <?php else: ?>
                                        <a href="/admin/techniciens/<?= (int) $t['technician_id'] ?>">fiche #<?= (int) $t['technician_id'] ?></a>
                                        <?= (int) $t['tech_active'] === 1 ? '' : ' (inactive)' ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int) $t['skills'] ?></td>
                                <td><?= (int) $t['availability'] ?></td>
                                <td><?= (int) $t['jobs'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($data['technicians'] === []): ?>
                            <tr><td colspan="6" class="kn-muted">Aucun compte de rôle technicien.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="kn-card">
            <h2>Serveur</h2>
            <div class="kn-table-wrap">
                <table class="kn-table">
                    <tbody>
                        <?php foreach ($data['server'] as $label => $value): ?>
                            <tr><th style="text-align:left;"><?= $e($label) ?></th><td class="kn-muted"><code><?= $e($value) ?></code></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
