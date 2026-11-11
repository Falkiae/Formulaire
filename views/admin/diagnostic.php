<?php
/**
 * Vue : page de diagnostic (réservée admin).
 * @var array<string,mixed> $data
 * @var callable $e
 */
$badge = static function (string $state): string {
    return match ($state) {
        'ok' => 'kn-badge kn-badge-on',
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
            État réel du serveur et de l'application. Aucun secret n'est affiché.
            Utile pour vérifier, après un envoi de fichiers, que les routes et la
            réécriture d'URL répondent comme attendu.
        </p>

        <?php if (!empty($data['flash'])): ?><p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p><?php endif; ?>
        <?php if (!empty($data['error'])): ?><p class="kn-alert kn-alert-error"><?= $e($data['error']) ?></p><?php endif; ?>

        <section class="kn-card">
            <h2>Jeu de rendez-vous de démonstration</h2>
            <p class="kn-muted">
                Remplit le planning du <strong><?= $e($data['demo_from']) ?></strong> au
                <strong><?= $e($data['demo_to']) ?></strong> avec des rendez-vous fictifs répartis
                sur vos techniciens actifs, aux heures ouvrées, avec des statuts cohérents
                (terminés dans le passé, en cours aujourd'hui, planifiés ensuite).
            </p>
            <p class="kn-muted">
                Ces données sont écrites dans la vraie base, mais elles sont
                <strong>reconnaissables</strong> — référence <code><?= $e(\Keepnew\Admin\DemoDataService::REFERENCE_PREFIX) ?>…</code>,
                clients en <code><?= $e(\Keepnew\Admin\DemoDataService::EMAIL_DOMAIN) ?></code>, un domaine qu'aucun
                serveur de messagerie ne peut livrer — et <strong>entièrement supprimables</strong>.
                Un créneau déjà occupé par une vraie intervention est sauté.
            </p>
            <div class="kn-alert kn-alert-warning">
                <div>
                    Tant qu'elles sont en base, ces commandes <strong>comptent dans les rapports</strong>
                    (chiffre d'affaires, conversion, annulations) et dans la liste des clients.
                    Retirez-les avant de lire vos chiffres.
                    <div class="kn-muted" style="margin-top:4px;font-size:var(--kn-fs-small);">
                        Les états « en route » et « en cours » n'apparaissent que si vous générez
                        pendant les heures ouvrées : marquer « en cours » une intervention à 22 h
                        serait faux.
                    </div>
                </div>
            </div>

            <?php if ($data['demo_blockers'] !== []): ?>
                <div class="kn-alert kn-alert-warning">
                    <div>
                        <?php foreach ($data['demo_blockers'] as $b): ?>
                            <div><?= $e($b) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <p>
                <?php if ($data['demo_count'] > 0): ?>
                    <span class="kn-badge kn-badge-info"><?= (int) $data['demo_count'] ?> commande<?= $data['demo_count'] > 1 ? 's' : '' ?> de démonstration en base</span>
                <?php else: ?>
                    <span class="kn-badge kn-badge-off">Aucune donnée de démonstration</span>
                <?php endif; ?>
            </p>

            <div class="kn-row">
                <form method="post" action="/admin/diagnostic/demo"
                      onsubmit="return confirm('Créer des rendez-vous fictifs du <?= $e($data['demo_from']) ?> au <?= $e($data['demo_to']) ?> dans la base ? Ils seront marqués et retirables d\'un clic.');">
                    <?= $data['csrf'] ?>
                    <button type="submit" class="kn-btn kn-btn-primary"<?= $data['demo_blockers'] !== [] ? ' disabled' : '' ?>>
                        Créer le jeu de démonstration
                    </button>
                </form>

                <?php if ($data['demo_count'] > 0): ?>
                    <form method="post" action="/admin/diagnostic/demo/supprimer"
                          onsubmit="return confirm('Retirer les <?= (int) $data['demo_count'] ?> commandes de démonstration ? Les vraies données ne sont pas touchées.');">
                        <?= $data['csrf'] ?>
                        <button type="submit" class="kn-btn kn-btn-danger">Retirer le jeu de démonstration</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <section class="kn-card kn-mt-sm">
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
                    <strong>Entrées susceptibles d'intercepter une route :</strong>
                    <?= $e(implode(', ', array_map(static fn (array $x): string => $x['type'] . ' « ' . $x['name'] . ' »', $shadows))) ?>.
                    Apache peut les servir au lieu de passer la main au routeur, et l'URL correspondante
                    renvoie alors « Not Found ». Un <strong>dossier</strong> portant exactement le nom d'une
                    route est à supprimer par FTP ; un <strong>fichier</strong> du type <code>tech.webmanifest</code>
                    est légitime et doit rester — c'est <code>Options -MultiViews</code> dans le
                    <code>.htaccess</code> ci-dessus qui neutralise son effet.
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
