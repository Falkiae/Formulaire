<?php
/**
 * Partial : navigation back-office responsive.
 * Inclus dans toutes les vues admin via include __DIR__ . '/_nav.php'
 * (ou dirname(__DIR__) . '/_nav.php' depuis les sous-dossiers).
 * Utilise $_SERVER['REQUEST_URI'] pour marquer la page active.
 *
 * @var callable $e
 */
$_knPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$_knActive = static fn (string $prefix): string =>
    str_starts_with($_knPath, $prefix) ? ' aria-current="page"' : '';
?>
<header class="kn-header" id="kn-header">
    <a href="/admin/dispatch" class="kn-logo">Keepnew</a>

    <button class="kn-nav-btn" id="kn-nav-btn"
            aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="kn-nav">
        <span></span><span></span><span></span>
    </button>

    <nav class="kn-nav" id="kn-nav" aria-label="Navigation principale">
        <a href="/admin/dispatch"<?= $_knActive('/admin/dispatch') ?>>Dispatch</a>
        <a href="/admin/clients"<?= ($_knActive('/admin/client')) ?>>Clients</a>
        <a href="/admin/factures"<?= $_knActive('/admin/factures') ?>>Factures</a>
        <a href="/admin/rapports"<?= $_knActive('/admin/rapports') ?>>Rapports</a>
        <a href="/admin/catalogue"<?= $_knActive('/admin/catalogue') ?>>Catalogue</a>
        <a href="/admin/extras"<?= $_knActive('/admin/extras') ?>>Extras</a>
        <a href="/admin/formulaire"<?= $_knActive('/admin/formulaire') ?>>Formulaire</a>
        <a href="/admin/ateliers"<?= $_knActive('/admin/ateliers') ?>>Ateliers</a>
        <a href="/admin/simulateur"<?= $_knActive('/admin/simulateur') ?>>Simulateur</a>
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
