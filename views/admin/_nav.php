<?php
/**
 * Partial : navigation back-office responsive, filtrée par rôle.
 * Inclus dans toutes les vues admin via include __DIR__ . '/_nav.php'
 * (ou dirname(__DIR__) . '/_nav.php' depuis les sous-dossiers).
 *
 * Le rôle courant est lu dans la session ($_SESSION['user_role'], posé au login).
 * Chaque lien déclare les rôles autorisés (miroir de RoleMiddleware dans routes.php).
 *
 * @var callable $e
 */
$_knPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$_knActive = static fn (string $prefix): string =>
    str_starts_with($_knPath, $prefix) ? ' aria-current="page"' : '';
$_knRole = $_SESSION['user_role'] ?? '';

/** @var list<array{href:string,label:string,match:string,roles:list<string>}> $_knItems */
$_knItems = [
    ['href' => '/admin/calendrier',    'label' => 'Calendrier',   'match' => '/admin/calendrier',   'roles' => ['admin', 'dispatcher', 'accountant']],
    ['href' => '/admin/dispatch',      'label' => 'Dispatch',     'match' => '/admin/dispatch',     'roles' => ['admin', 'dispatcher']],
    ['href' => '/admin/clients',       'label' => 'Clients',      'match' => '/admin/client',       'roles' => ['admin', 'dispatcher', 'accountant']],
    ['href' => '/admin/factures',      'label' => 'Factures',     'match' => '/admin/factures',     'roles' => ['admin', 'accountant']],
    ['href' => '/admin/rapports',      'label' => 'Rapports',     'match' => '/admin/rapports',     'roles' => ['admin', 'accountant']],
    ['href' => '/admin/catalogue',     'label' => 'Catalogue',    'match' => '/admin/catalogue',    'roles' => ['admin']],
    ['href' => '/admin/extras',        'label' => 'Extras',       'match' => '/admin/extras',       'roles' => ['admin']],
    ['href' => '/admin/formulaire',    'label' => 'Formulaire',   'match' => '/admin/formulaire',   'roles' => ['admin']],
    ['href' => '/admin/techniciens',   'label' => 'Techniciens',  'match' => '/admin/technicien',   'roles' => ['admin', 'dispatcher']],
    ['href' => '/admin/zones',         'label' => 'Zones',        'match' => '/admin/zones',        'roles' => ['admin']],
    ['href' => '/admin/ateliers',      'label' => 'Ateliers',     'match' => '/admin/ateliers',     'roles' => ['admin', 'dispatcher']],
    ['href' => '/admin/simulateur',    'label' => 'Simulateur',   'match' => '/admin/simulateur',   'roles' => ['admin', 'dispatcher']],
    ['href' => '/admin/utilisateurs',  'label' => 'Utilisateurs', 'match' => '/admin/utilisateurs', 'roles' => ['admin']],
];
?>
<header class="kn-header" id="kn-header">
    <a href="<?= $_knRole === 'accountant' ? '/admin/factures' : '/admin/dispatch' ?>" class="kn-logo">Keepnew</a>

    <button class="kn-nav-btn" id="kn-nav-btn"
            aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="kn-nav">
        <span></span><span></span><span></span>
    </button>

    <nav class="kn-nav" id="kn-nav" aria-label="Navigation principale">
        <?php foreach ($_knItems as $_item): ?>
            <?php if ($_knRole === '' || in_array($_knRole, $_item['roles'], true)): ?>
                <a href="<?= $_item['href'] ?>"<?= $_knActive($_item['match']) ?>><?= $e($_item['label']) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
        <a href="/admin/deconnexion" class="kn-nav-logout">Déconnexion</a>
    </nav>
</header>

<script>
(function () {
    var btn = document.getElementById('kn-nav-btn');
    var hdr = document.getElementById('kn-header');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var open = hdr.classList.toggle('is-open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        btn.setAttribute('aria-label', open ? 'Fermer le menu' : 'Ouvrir le menu');
    });
    // Ferme le menu si on clique en dehors
    document.addEventListener('click', function (e) {
        if (!hdr.contains(e.target)) hdr.classList.remove('is-open');
    });
})();
</script>
