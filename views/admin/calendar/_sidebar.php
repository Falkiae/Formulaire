<?php
/**
 * Partial : sidebar de filtres du calendrier (territoires + assignation).
 * Réutilisé par month.php et week.php. Liens GET simples (pas de JS requis),
 * qui préservent la période courante et les autres filtres actifs.
 *
 * Inclus via `include` (pas `$view->capture()`) : partage la portée de la
 * vue appelante, qui doit définir $initials et $avatarColor avant l'include.
 *
 * @var array<string,mixed> $data
 * @var callable $e
 * @var callable $initials
 * @var callable $avatarColor
 */
$filters = $data['filters'];
$buildLink = static function (array $overrides) use ($data): string {
    $params = array_filter(
        array_merge(
            [
                'date' => $data['current'],
                'mode' => $data['filters']['mode'],
                'tech' => $data['filters']['technician_id'] ?: null,
                'zone_id' => $data['filters']['zone_id'] ?: null,
                'location_id' => $data['filters']['location_id'] ?: null,
            ],
            $overrides,
        ),
        static fn ($v): bool => $v !== null && $v !== '',
    );

    return $data['base_path'] . '?' . http_build_query($params);
};
?>
<aside class="kn-cal-sidebar">
    <div class="kn-cal-sidebar-section">
        <h3>Type</h3>
        <a class="kn-cal-sidebar-link<?= $filters['mode'] === '' ? ' is-active' : '' ?>" href="<?= $e($buildLink(['mode' => null])) ?>">Tous</a>
        <a class="kn-cal-sidebar-link<?= $filters['mode'] === 'onsite' ? ' is-active' : '' ?>" href="<?= $e($buildLink(['mode' => 'onsite'])) ?>">À domicile</a>
        <a class="kn-cal-sidebar-link<?= $filters['mode'] === 'workshop' ? ' is-active' : '' ?>" href="<?= $e($buildLink(['mode' => 'workshop'])) ?>">Atelier</a>
    </div>

    <div class="kn-cal-sidebar-section">
        <h3>Territoires</h3>
        <a class="kn-cal-sidebar-link<?= $filters['zone_id'] === 0 && $filters['location_id'] === 0 ? ' is-active' : '' ?>"
           href="<?= $e($buildLink(['zone_id' => null, 'location_id' => null])) ?>">Toutes les zones</a>
        <?php foreach ($data['territories']['zones'] as $z): ?>
            <a class="kn-cal-sidebar-link<?= $filters['zone_id'] === (int) $z['id'] ? ' is-active' : '' ?>"
               href="<?= $e($buildLink(['zone_id' => (int) $z['id'], 'location_id' => null])) ?>"><?= $e($z['name']) ?></a>
        <?php endforeach; ?>
        <?php foreach ($data['territories']['locations'] as $loc): ?>
            <a class="kn-cal-sidebar-link<?= $filters['location_id'] === (int) $loc['id'] ? ' is-active' : '' ?>"
               href="<?= $e($buildLink(['location_id' => (int) $loc['id'], 'zone_id' => null])) ?>">🏭 <?= $e($loc['name']) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="kn-cal-sidebar-section">
        <h3>Assigné à</h3>
        <a class="kn-cal-sidebar-link<?= $filters['technician_id'] === 0 ? ' is-active' : '' ?>" href="<?= $e($buildLink(['tech' => null])) ?>">Tous les jobs</a>
        <?php foreach ($data['technicians'] as $t): ?>
            <a class="kn-cal-sidebar-link kn-cal-sidebar-link-tech<?= $filters['technician_id'] === (int) $t['id'] ? ' is-active' : '' ?>"
               href="<?= $e($buildLink(['tech' => (int) $t['id']])) ?>">
                <span class="kn-avatar" style="background:<?= $avatarColor((int) $t['id']) ?>"><?= $e($initials($t['first_name'], $t['last_name'])) ?></span>
                <?= $e(trim($t['first_name'] . ' ' . $t['last_name'])) ?>
            </a>
        <?php endforeach; ?>
    </div>
</aside>
