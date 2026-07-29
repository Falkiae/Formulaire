<?php
/**
 * Vue : création / édition d'une zone de service (chalandise).
 * $data['zone'] === null → création (les sous-sections n'apparaissent qu'en édition).
 * @var array<string,mixed> $data
 * @var callable $e
 */
$zone = $data['zone'];
$isNew = $zone === null;
$zoneId = $isNew ? 0 : (int) $zone['id'];
$wasRadius = !$isNew && ($zone['zone_type'] ?? '') === 'radius';
$modifierLabels = ['refuse' => 'Refus', 'surcharge' => 'Supplément', 'discount' => 'Remise'];
$techZoneIds = $data['zone_technician_ids'];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $isNew ? 'Nouvelle zone' : $e($zone['name']) ?> — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <?php include dirname(__DIR__) . '/_nav.php'; ?>

    <main class="kn-wrap">
        <p><a href="/admin/zones">← Zones de service</a></p>

        <?php if (!empty($data['error'])): ?><p class="kn-alert kn-alert-error"><?= $e($data['error']) ?></p><?php endif; ?>
        <?php if (!empty($data['flash'])): ?><p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p><?php endif; ?>
        <?php if ($wasRadius): ?>
            <p class="kn-alert">Cette zone était de type « rayon », qui ne fonctionnait jamais réellement dans le tunnel public (le code postal saisi n'était jamais converti en coordonnées). Elle a été convertie en « codes postaux » — complétez la liste ci-dessous et enregistrez pour qu'elle redevienne active.</p>
        <?php endif; ?>

        <h1><?= $isNew ? 'Nouvelle zone' : $e($zone['name']) ?></h1>

        <!-- Fiche principale -->
        <form method="post" action="<?= $isNew ? '/admin/zones' : '/admin/zones/' . $zoneId ?>" class="kn-card" id="kn-zone-form">
            <?= $data['csrf'] ?>

            <div class="kn-field">
                <label for="name">Nom</label>
                <input type="text" id="name" name="name" value="<?= $e($zone['name'] ?? '') ?>" required>
            </div>

            <div class="kn-field">
                <label for="postal_codes">Codes postaux couverts (un par ligne, ou séparés par des virgules)</label>
                <textarea id="postal_codes" name="postal_codes" rows="5" style="width:100%;padding:8px;border:1px solid var(--kn-line);border-radius:4px;resize:vertical;"><?= $e(implode("\n", $data['postal_codes'])) ?></textarea>
            </div>

            <div class="kn-grid kn-grid-2">
                <div class="kn-field">
                    <label for="priority">Priorité (plus petit = évalué en premier)</label>
                    <input type="number" id="priority" name="priority" value="<?= (int) ($zone['priority'] ?? 100) ?>">
                </div>
                <div class="kn-field" style="display:flex;align-items:end;">
                    <label class="kn-check" style="margin:0;">
                        <input type="checkbox" name="is_active" value="1" <?= ($isNew || (int) ($zone['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                        Zone active
                    </label>
                </div>
            </div>

            <button type="submit" class="kn-btn kn-btn-primary"><?= $isNew ? 'Créer la zone' : 'Enregistrer' ?></button>
        </form>

        <?php if (!$isNew): ?>
            <form method="post" action="/admin/zones/<?= $zoneId ?>/supprimer" style="margin-top:12px;" onsubmit="return confirm('Supprimer cette zone ? Les codes postaux, règles et affectations techniciens associés seront aussi supprimés.');">
                <?= $data['csrf'] ?>
                <button type="submit" class="kn-btn kn-btn-danger kn-btn-sm">Supprimer la zone</button>
            </form>

            <!-- Règles tarifaires -->
            <section class="kn-card" style="margin-top:24px;">
                <h2>Règles tarifaires</h2>
                <p class="kn-muted">Une adresse qui tombe dans cette zone est acceptée par défaut. Ajoutez une règle « Refus » pour la bloquer, ou « Supplément »/« Remise » pour ajuster le trajet.</p>

                <div class="kn-table-wrap">
                    <table class="kn-table">
                        <thead><tr><th>Type</th><th>Calcul</th><th>Libellé</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($data['modifiers'] as $m): ?>
                                <tr>
                                    <td><span class="kn-badge"><?= $e($modifierLabels[$m['modifier_type']] ?? $m['modifier_type']) ?></span></td>
                                    <td class="kn-muted">
                                        <?php if ($m['modifier_type'] === 'refuse'): ?>
                                            —
                                        <?php elseif ($m['calc_type'] === 'percent'): ?>
                                            <?= $e(number_format(((int) $m['calc_value']) / 100, 2, ',', ' ')) ?> %
                                        <?php else: ?>
                                            <?= $e(number_format(((int) $m['calc_value']) / 100, 2, ',', ' ')) ?> €
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $e($m['label'] ?? '—') ?></td>
                                    <td>
                                        <form method="post" action="/admin/zones/<?= $zoneId ?>/regle/<?= (int) $m['id'] ?>/supprimer" onsubmit="return confirm('Supprimer cette règle ?');">
                                            <?= $data['csrf'] ?>
                                            <button type="submit" class="kn-btn kn-btn-danger kn-btn-sm">Suppr.</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($data['modifiers'] === []): ?><tr><td colspan="4" class="kn-muted">Aucune règle — la zone est acceptée sans supplément.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <details style="margin-top:12px;">
                    <summary>Ajouter une règle</summary>
                    <form method="post" action="/admin/zones/<?= $zoneId ?>/regle" id="kn-mod-form" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:12px;">
                        <?= $data['csrf'] ?>
                        <div class="kn-field" style="margin:0;"><label>Type</label><select name="modifier_type" id="kn-mod-type">
                            <option value="refuse">Refus</option>
                            <option value="surcharge">Supplément</option>
                            <option value="discount">Remise</option>
                        </select></div>
                        <div class="kn-field kn-mod-calc" style="margin:0;"><label>Calcul</label><select name="calc_type">
                            <option value="fixed">Montant fixe (€)</option>
                            <option value="percent">Pourcentage (%)</option>
                        </select></div>
                        <div class="kn-field kn-mod-calc" style="margin:0;"><label>Valeur</label><input type="text" name="calc_value" inputmode="decimal" placeholder="ex. 15" style="max-width:100px;"></div>
                        <div class="kn-field" style="margin:0;flex:1;"><label>Libellé</label><input type="text" name="label" placeholder="ex. Supplément déplacement"></div>
                        <button type="submit" class="kn-btn kn-btn-ghost">Ajouter</button>
                    </form>
                    <script>
                    (function () {
                        var form = document.getElementById('kn-mod-form');
                        if (!form) return;
                        var typeSel = document.getElementById('kn-mod-type');
                        function sync() {
                            var refuse = typeSel.value === 'refuse';
                            form.querySelectorAll('.kn-mod-calc').forEach(function (el) { el.style.display = refuse ? 'none' : ''; });
                        }
                        typeSel.addEventListener('change', sync);
                        sync();
                    })();
                    </script>
                </details>
            </section>

            <!-- Techniciens couvrant cette zone -->
            <section class="kn-card" style="margin-top:24px;">
                <h2>Techniciens couvrant cette zone</h2>
                <?php if ($data['technicians'] === []): ?>
                    <p class="kn-muted">Aucun technicien actif. <a href="/admin/techniciens">En créer</a>.</p>
                <?php else: ?>
                    <form method="post" action="/admin/zones/<?= $zoneId ?>/techniciens">
                        <?= $data['csrf'] ?>
                        <div style="display:flex;flex-wrap:wrap;gap:8px 20px;margin-bottom:16px;">
                            <?php foreach ($data['technicians'] as $t): ?>
                                <label class="kn-check" style="margin:0;">
                                    <input type="checkbox" name="technicians[]" value="<?= (int) $t['id'] ?>" <?= in_array((int) $t['id'], $techZoneIds, true) ? 'checked' : '' ?>>
                                    <?= $e(trim($t['first_name'] . ' ' . $t['last_name'])) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" class="kn-btn kn-btn-ghost">Enregistrer les techniciens</button>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
