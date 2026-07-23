<?php
/**
 * Partial : barre de filtres du calendrier (mode + technicien).
 * Réutilisé par month.php et week.php. Formulaire GET qui conserve la période
 * courante ($data['current']) et poste vers la page courante.
 *
 * @var array<string,mixed> $data
 * @var callable $e
 */
$action = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/calendrier', PHP_URL_PATH) ?? '/admin/calendrier';
?>
<form method="get" action="<?= $e($action) ?>" class="kn-cal-filters">
    <input type="hidden" name="date" value="<?= $e($data['current']) ?>">

    <div class="kn-field" style="margin:0;">
        <label for="f-mode">Type</label>
        <select id="f-mode" name="mode" onchange="this.form.submit()">
            <option value="">Tous</option>
            <option value="onsite" <?= ($data['filters']['mode'] ?? '') === 'onsite' ? 'selected' : '' ?>>À domicile</option>
            <option value="workshop" <?= ($data['filters']['mode'] ?? '') === 'workshop' ? 'selected' : '' ?>>Atelier</option>
        </select>
    </div>

    <div class="kn-field" style="margin:0;">
        <label for="f-tech">Technicien</label>
        <select id="f-tech" name="tech" onchange="this.form.submit()">
            <option value="0">Tous</option>
            <?php foreach ($data['technicians'] as $t): ?>
                <option value="<?= (int) $t['id'] ?>" <?= (int) ($data['filters']['technician_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>>
                    <?= $e(trim($t['first_name'] . ' ' . $t['last_name'])) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <noscript><button type="submit" class="kn-btn kn-btn-ghost kn-btn-sm">Filtrer</button></noscript>

    <div class="kn-cal-toggle">
        <?php if (isset($data['week_of'])): ?>
            <span class="kn-badge kn-badge-onsite">Mois</span>
            <a class="kn-btn kn-btn-ghost kn-btn-sm" href="/admin/calendrier/semaine?date=<?= $e($data['week_of']) . $data['filter_qs'] ?>">Vue semaine →</a>
        <?php else: ?>
            <a class="kn-btn kn-btn-ghost kn-btn-sm" href="/admin/calendrier?date=<?= $e($data['month_of']) . $data['filter_qs'] ?>">← Vue mois</a>
            <span class="kn-badge kn-badge-onsite">Semaine</span>
        <?php endif; ?>
    </div>
</form>
