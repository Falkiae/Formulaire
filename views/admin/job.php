<?php
/**
 * Vue : fiche job.
 * @var array<string,mixed> $data
 * @var callable $e
 */
$j = $data['job'];
$eur = static fn (int $c): string => number_format($c / 100, 2, ',', ' ');
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Job <?= $e($j['reference']) ?> — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <?php include __DIR__ . '/_nav.php'; ?>

    <main class="kn-wrap">
        <p><a href="/admin/dispatch">← Dispatch</a></p>
        <?php if (!empty($data['flash'])): ?><p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p><?php endif; ?>

        <h1>Rendez-vous <?= $e($j['reference']) ?></h1>
        <p>
            <span class="kn-badge kn-badge-<?= $j['mode'] ?>"><?= $j['mode'] === 'onsite' ? 'À domicile' : 'Atelier' ?></span>
            · Statut : <strong><?= $e($j['status']) ?></strong>
            <?php if ($j['tech_first']): ?> · Technicien : <?= $e($j['tech_first'] . ' ' . $j['tech_last']) ?><?php endif; ?>
        </p>

        <div class="kn-grid kn-grid-2">
            <section class="kn-card">
                <h2>Client</h2>
                <p>
                    <a href="/admin/client/<?= (int) $j['customer_id'] ?>"><strong><?= $e($j['first_name'] . ' ' . $j['last_name']) ?></strong></a><br>
                    <?= $e($j['phone'] ?? '') ?> · <?= $e($j['email'] ?? '') ?><br>
                    <?= $e(trim(($j['street'] ?? '') . ' ' . ($j['number'] ?? '') . ', ' . ($j['postal_code'] ?? '') . ' ' . ($j['city'] ?? ''))) ?>
                </p>
                <?php if (!empty($j['access_notes'])): ?><p class="kn-muted">Accès : <?= $e($j['access_notes']) ?></p><?php endif; ?>
            </section>

            <section class="kn-card">
                <h2>Prestations</h2>
                <p class="kn-muted" style="font-size:.8rem;">Prix catalogue HT (hors remise/TVA — le montant facturé est sur la fiche client/facture).</p>
                <div class="kn-table-wrap">
                    <table class="kn-table">
                        <?php foreach ($data['items'] as $it): ?>
                            <tr><td><?= $e($it['label_snapshot']) ?> ×<?= (int) $it['quantity'] ?></td><td class="kn-num"><?= $e($eur((int) $it['line_total_cents'])) ?> €</td></tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </section>
        </div>

        <section class="kn-card" style="margin-top:24px;">
            <h2>Réponses au formulaire</h2>
            <?php if ($data['answers'] === []): ?><p class="kn-muted">Aucune.</p><?php endif; ?>
            <?php foreach ($data['answers'] as $a): ?>
                <p><strong><?= $e($a['field_key']) ?></strong> : <?= $e($a['value_text']) ?></p>
            <?php endforeach; ?>
        </section>

        <section class="kn-card" style="margin-top:24px;">
            <h2>Photos avant / après</h2>
            <?php if ($data['photos'] === []): ?><p class="kn-muted">Aucune photo pour le moment.</p><?php endif; ?>
            <?php foreach ($data['photos'] as $p): ?><p><?= $e($p['kind']) ?> : <?= $e($p['file_path']) ?></p><?php endforeach; ?>
        </section>

        <section class="kn-card" style="margin-top:24px;">
            <h2>Replanifier le rendez-vous</h2>
            <p class="kn-muted">Date/heure en heure belge. Un conflit d'agenda (ou de poste, en atelier) est refusé ; un trajet trop serré est signalé mais appliqué. Changer le mode est un acte opérationnel : le prix facturé reste inchangé.</p>
            <form method="post" action="/admin/job/<?= (int) $j['id'] ?>/planifier" id="kn-resched" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;">
                <?= $data['csrf'] ?>
                <div class="kn-field" style="margin:0;">
                    <label for="sched">Date et heure</label>
                    <input type="datetime-local" id="sched" name="scheduled_start" value="<?= $e($data['scheduled_local'] ?? '') ?>" required>
                </div>
                <div class="kn-field" style="margin:0;min-width:200px;">
                    <label for="tech">Technicien</label>
                    <select id="tech" name="technician_id" required>
                        <option value="0">— Choisir —</option>
                        <?php foreach ($data['technicians'] as $t): ?>
                            <option value="<?= (int) $t['id'] ?>" <?= (int) ($j['technician_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>>
                                <?= $e(trim($t['first_name'] . ' ' . $t['last_name'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="kn-field" style="margin:0;">
                    <label for="mode">Mode</label>
                    <select id="mode" name="mode">
                        <option value="onsite" <?= $j['mode'] === 'onsite' ? 'selected' : '' ?>>À domicile</option>
                        <option value="workshop" <?= $j['mode'] === 'workshop' ? 'selected' : '' ?>>Atelier</option>
                    </select>
                </div>
                <div class="kn-field kn-mode-workshop" style="margin:0;min-width:200px;">
                    <label for="bay">Poste d'atelier</label>
                    <select id="bay" name="bay_id">
                        <option value="0">— Choisir —</option>
                        <?php foreach ($data['active_bays'] as $b): ?>
                            <option value="<?= (int) $b['id'] ?>" <?= (int) ($j['bay_id'] ?? 0) === (int) $b['id'] ? 'selected' : '' ?>>
                                <?= $e($b['location_name'] . ' — ' . $b['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="kn-field kn-mode-onsite" style="margin:0;min-width:220px;">
                    <label for="addr">Adresse (domicile)</label>
                    <select id="addr" name="address_id">
                        <option value="0">— Choisir —</option>
                        <?php foreach ($data['customer_addresses'] as $a): ?>
                            <option value="<?= (int) $a['id'] ?>" <?= (int) ($j['address_id'] ?? 0) === (int) $a['id'] ? 'selected' : '' ?>>
                                <?= $e(trim((($a['label'] ?? '') !== '' ? $a['label'] . ' · ' : '') . ($a['street'] ?? '') . ' ' . ($a['number'] ?? '') . ', ' . ($a['postal_code'] ?? '') . ' ' . ($a['city'] ?? ''))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($data['customer_addresses'] === []): ?>
                        <p class="kn-muted" style="margin:4px 0 0;font-size:.8rem;">Aucune adresse : ajoutez-en une sur la <a href="/admin/client/<?= (int) $j['customer_id'] ?>">fiche client</a>.</p>
                    <?php endif; ?>
                </div>
                <button type="submit" class="kn-btn kn-btn-primary">Déplacer</button>
            </form>
        </section>
        <script>
        (function () {
            var form = document.getElementById('kn-resched');
            if (!form) return;
            var mode = document.getElementById('mode');
            function sync() {
                var ws = mode.value === 'workshop';
                form.querySelectorAll('.kn-mode-workshop').forEach(function (el) { el.style.display = ws ? '' : 'none'; });
                form.querySelectorAll('.kn-mode-onsite').forEach(function (el) { el.style.display = ws ? 'none' : ''; });
            }
            mode.addEventListener('change', sync);
            sync();
        })();
        </script>

        <div class="kn-grid kn-grid-2" style="margin-top:24px;">
            <section class="kn-card">
                <h2>Changer le statut</h2>
                <form method="post" action="/admin/job/<?= (int) $j['id'] ?>/statut" style="display:flex;gap:8px;align-items:end;">
                    <?= $data['csrf'] ?>
                    <div class="kn-field" style="margin:0;flex:1;">
                        <label>Statut</label>
                        <select name="status">
                            <?php foreach ($data['statuses'] as $s): ?>
                                <option value="<?= $e($s) ?>" <?= $s === $j['status'] ? 'selected' : '' ?>><?= $e($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="kn-btn kn-btn-primary">Appliquer</button>
                </form>

                <h3 style="margin-top:16px;">Note interne</h3>
                <form method="post" action="/admin/job/<?= (int) $j['id'] ?>/note">
                    <?= $data['csrf'] ?>
                    <input type="hidden" name="customer_id" value="<?= (int) $j['customer_id'] ?>">
                    <div class="kn-field">
                        <textarea name="body" rows="3" style="width:100%;padding:8px;border:1px solid var(--kn-line);border-radius:8px;resize:vertical;"></textarea>
                    </div>
                    <button type="submit" class="kn-btn kn-btn-ghost">Ajouter la note</button>
                </form>
            </section>

            <section class="kn-card">
                <h2>Historique</h2>
                <?php foreach ($data['history'] as $h): ?>
                    <p class="kn-muted"><?= $e($h['created_at']) ?> — <?= $e($h['old_status'] ?? '∅') ?> → <strong><?= $e($h['new_status']) ?></strong> <?= $e($h['note'] ?? '') ?></p>
                <?php endforeach; ?>
            </section>
        </div>
    </main>
</body>
</html>
