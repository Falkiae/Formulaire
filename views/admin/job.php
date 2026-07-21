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
