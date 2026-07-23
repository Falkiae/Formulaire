<?php
/**
 * Vue : fiche client (lecture + édition si autorisé).
 * @var array<string,mixed> $data
 * @var callable $e
 */
$c = $data['customer'];
$eur = static fn (int $v): string => number_format($v / 100, 2, ',', ' ');
$name = $c['type'] === 'b2b' && $c['company_name'] ? $c['company_name'] : trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
$canEdit = !empty($data['can_edit']);
$cid = (int) $c['id'];

/** Rend les champs d'une adresse (partagé entre ajout et édition). */
$addressFields = static function (array $a, callable $e): string {
    ob_start();
    ?>
    <div class="kn-grid kn-grid-2">
        <div class="kn-field" style="margin-bottom:8px;"><label>Libellé</label><input type="text" name="label" value="<?= $e($a['label'] ?? '') ?>" placeholder="Domicile, Garage…"></div>
        <div class="kn-field" style="margin-bottom:8px;"><label>Rue</label><input type="text" name="street" value="<?= $e($a['street'] ?? '') ?>" required></div>
    </div>
    <div class="kn-grid kn-grid-2">
        <div class="kn-field" style="margin-bottom:8px;"><label>Numéro</label><input type="text" name="number" value="<?= $e($a['number'] ?? '') ?>"></div>
        <div class="kn-field" style="margin-bottom:8px;"><label>Boîte</label><input type="text" name="box" value="<?= $e($a['box'] ?? '') ?>"></div>
    </div>
    <div class="kn-grid kn-grid-2">
        <div class="kn-field" style="margin-bottom:8px;"><label>Code postal</label><input type="text" name="postal_code" value="<?= $e($a['postal_code'] ?? '') ?>" required></div>
        <div class="kn-field" style="margin-bottom:8px;"><label>Ville</label><input type="text" name="city" value="<?= $e($a['city'] ?? '') ?>" required></div>
    </div>
    <div class="kn-field" style="margin-bottom:8px;"><label>Notes d'accès</label><input type="text" name="access_notes" value="<?= $e($a['access_notes'] ?? '') ?>" placeholder="Étage, parking, digicode…"></div>
    <?php
    return (string) ob_get_clean();
};
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
    <?php include __DIR__ . '/_nav.php'; ?>

    <main class="kn-wrap">
        <p><a href="/admin/clients">← Clients</a></p>
        <?php if (!empty($data['flash'])): ?><p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p><?php endif; ?>

        <div class="kn-daynav">
            <h1 style="margin:0;"><?= $e($name ?: $c['email']) ?></h1>
            <?php if ($canEdit): ?>
                <a class="kn-btn kn-btn-ghost kn-btn-sm" href="/admin/client/<?= $cid ?>/editer">Modifier la fiche</a>
            <?php endif; ?>
        </div>
        <p>
            <span class="kn-badge"><?= strtoupper($e($c['type'])) ?></span>
            · <?= $e($c['email']) ?> · <?= $e($c['phone'] ?? '') ?>
            <?php if ($c['vat_number']): ?> · TVA <?= $e($c['vat_number']) ?><?php endif; ?>
        </p>
        <p><strong>LTV :</strong> <?= $e($eur((int) $data['ltv_cents'])) ?> € · <strong><?= count($data['bookings']) ?></strong> commande(s)</p>

        <div class="kn-grid kn-grid-2">
            <section class="kn-card">
                <h2>Commandes</h2>
                <div class="kn-table-wrap">
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
                </div>
            </section>

            <section class="kn-card">
                <h2>Adresses</h2>
                <?php foreach ($data['addresses'] as $a): ?>
                    <div style="padding:8px 0;border-bottom:1px solid var(--kn-line);">
                        <p style="margin:0;">
                            <?php if (($a['label'] ?? '') !== ''): ?><strong><?= $e($a['label']) ?></strong> — <?php endif; ?>
                            <?= $e(trim(($a['street'] ?? '') . ' ' . ($a['number'] ?? '') . (($a['box'] ?? '') !== '' ? '/' . $a['box'] : '') . ', ' . ($a['postal_code'] ?? '') . ' ' . ($a['city'] ?? ''))) ?>
                        </p>
                        <?php if (($a['access_notes'] ?? '') !== ''): ?><p class="kn-muted" style="margin:2px 0 0;font-size:.85rem;">Accès : <?= $e($a['access_notes']) ?></p><?php endif; ?>
                        <?php if ($canEdit): ?>
                            <div style="display:flex;gap:8px;margin-top:6px;">
                                <details>
                                    <summary class="kn-muted" style="font-size:.85rem;cursor:pointer;">Modifier</summary>
                                    <form method="post" action="/admin/client/<?= $cid ?>/adresse/<?= (int) $a['id'] ?>" style="margin-top:8px;">
                                        <?= $data['csrf'] ?>
                                        <?= $addressFields($a, $e) ?>
                                        <button type="submit" class="kn-btn kn-btn-ghost kn-btn-sm">Enregistrer</button>
                                    </form>
                                </details>
                                <form method="post" action="/admin/client/<?= $cid ?>/adresse/<?= (int) $a['id'] ?>/supprimer" onsubmit="return confirm('Supprimer cette adresse ?');">
                                    <?= $data['csrf'] ?>
                                    <button type="submit" class="kn-btn kn-btn-danger kn-btn-sm">Supprimer</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($data['addresses'] === []): ?><p class="kn-muted">Aucune.</p><?php endif; ?>

                <?php if ($canEdit): ?>
                    <details style="margin-top:12px;">
                        <summary>Ajouter une adresse</summary>
                        <form method="post" action="/admin/client/<?= $cid ?>/adresse" style="margin-top:12px;">
                            <?= $data['csrf'] ?>
                            <?= $addressFields([], $e) ?>
                            <button type="submit" class="kn-btn kn-btn-ghost">Ajouter l'adresse</button>
                        </form>
                    </details>
                <?php endif; ?>

                <h3>Notes</h3>
                <?php foreach ($data['notes'] as $n): ?>
                    <p class="kn-muted"><?= $e(substr((string) $n['created_at'], 0, 10)) ?> (<?= $e($n['first_name'] ?? 'système') ?>) : <?= $e($n['body']) ?></p>
                <?php endforeach; ?>
                <?php if ($data['notes'] === []): ?><p class="kn-muted">Aucune note.</p><?php endif; ?>

                <?php if ($canEdit): ?>
                    <form method="post" action="/admin/client/<?= $cid ?>/note" style="margin-top:12px;">
                        <?= $data['csrf'] ?>
                        <div class="kn-field">
                            <label for="note">Nouvelle note</label>
                            <textarea id="note" name="body" rows="2" style="width:100%;padding:8px;border:1px solid var(--kn-line);border-radius:8px;resize:vertical;"></textarea>
                        </div>
                        <button type="submit" class="kn-btn kn-btn-ghost">Ajouter la note</button>
                    </form>
                <?php endif; ?>
            </section>
        </div>
    </main>
</body>
</html>
