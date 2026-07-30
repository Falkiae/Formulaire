<?php
/**
 * Partial : navigation back-office à deux niveaux, filtrée par rôle.
 * Inclus dans toutes les vues admin via include __DIR__ . '/_nav.php'
 * (ou dirname(__DIR__) . '/_nav.php' depuis les sous-dossiers).
 *
 * Niveau 1 — six groupes métier dans l'en-tête, au lieu des quinze entrées
 * plates d'avant (un admin les voyait toutes, sur deux lignes en desktop et
 * dans un tiroir interminable en mobile).
 * Niveau 2 — les sous-onglets du groupe courant, juste sous l'en-tête.
 * Volontairement PAS de menu déroulant : la structure reste visible, l'usage
 * au doigt reste possible, et on n'ajoute aucun piège clavier/focus.
 *
 * Le sous-menu est rendu ICI : toute vue incluant ce partial en hérite sans
 * modification.
 *
 * Le rôle courant est lu dans la session ($_SESSION['user_role'], posé au
 * login). Chaque entrée déclare ses rôles (miroir de RoleMiddleware dans
 * routes.php) ; un groupe n'apparaît que si au moins un enfant est autorisé.
 *
 * @var callable $e
 */
$_knPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$_knRole = $_SESSION['user_role'] ?? '';

/**
 * `match` est un préfixe d'URL servant à détecter la page courante, et c'est
 * le préfixe le PLUS LONG qui gagne : « /admin/formulaire/textes » l'emporte
 * donc sur « /admin/formulaire » indépendamment de l'ordre d'affichage.
 * `also` rattache au groupe les pages sans onglet propre (la fiche d'un
 * rendez-vous relève du Planning sans en être un sous-onglet).
 *
 * @var list<array{label:string, also?:list<string>, children:list<array{label:string,href:string,match:string,roles:list<string>}>}> $_knGroups
 */
$_knGroups = [
    [
        'label' => 'Planning',
        'also' => ['/admin/job'],
        'children' => [
            ['label' => 'Jour',    'href' => '/admin/dispatch',           'match' => '/admin/dispatch',           'roles' => ['admin', 'dispatcher']],
            ['label' => 'Semaine', 'href' => '/admin/calendrier/semaine', 'match' => '/admin/calendrier/semaine', 'roles' => ['admin', 'dispatcher', 'accountant']],
            ['label' => 'Mois',    'href' => '/admin/calendrier',         'match' => '/admin/calendrier',         'roles' => ['admin', 'dispatcher', 'accountant']],
        ],
    ],
    [
        'label' => 'Clients',
        'children' => [
            ['label' => 'Liste',      'href' => '/admin/clients',    'match' => '/admin/client',     'roles' => ['admin', 'dispatcher', 'accountant']],
            ['label' => 'Simulateur', 'href' => '/admin/simulateur', 'match' => '/admin/simulateur', 'roles' => ['admin', 'dispatcher']],
        ],
    ],
    [
        'label' => 'Services',
        'children' => [
            ['label' => 'Catalogue', 'href' => '/admin/catalogue', 'match' => '/admin/catalogue', 'roles' => ['admin']],
            ['label' => 'Extras',    'href' => '/admin/extras',    'match' => '/admin/extras',    'roles' => ['admin']],
        ],
    ],
    [
        'label' => 'Équipe',
        'children' => [
            ['label' => 'Techniciens', 'href' => '/admin/techniciens', 'match' => '/admin/technicien',  'roles' => ['admin', 'dispatcher']],
            ['label' => 'Compétences', 'href' => '/admin/competences', 'match' => '/admin/competences', 'roles' => ['admin']],
            ['label' => 'Zones',       'href' => '/admin/zones',       'match' => '/admin/zones',       'roles' => ['admin']],
            ['label' => 'Ateliers',    'href' => '/admin/ateliers',    'match' => '/admin/ateliers',    'roles' => ['admin', 'dispatcher']],
        ],
    ],
    [
        'label' => 'Finances',
        'children' => [
            ['label' => 'Factures', 'href' => '/admin/factures', 'match' => '/admin/factures', 'roles' => ['admin', 'accountant']],
            ['label' => 'Rapports', 'href' => '/admin/rapports', 'match' => '/admin/rapports', 'roles' => ['admin', 'accountant']],
        ],
    ],
    [
        'label' => 'Réglages',
        'children' => [
            ['label' => 'Général',          'href' => '/admin/reglages',          'match' => '/admin/reglages',          'roles' => ['admin']],
            ['label' => 'Formulaire',       'href' => '/admin/formulaire',        'match' => '/admin/formulaire',        'roles' => ['admin']],
            ['label' => 'Textes du tunnel', 'href' => '/admin/formulaire/textes', 'match' => '/admin/formulaire/textes', 'roles' => ['admin']],
            ['label' => 'Notifications',    'href' => '/admin/notifications',     'match' => '/admin/notifications',     'roles' => ['admin']],
            ['label' => 'Utilisateurs',     'href' => '/admin/utilisateurs',      'match' => '/admin/utilisateurs',      'roles' => ['admin']],
            ['label' => 'Diagnostic',       'href' => '/admin/diagnostic',        'match' => '/admin/diagnostic',        'roles' => ['admin']],
        ],
    ],
];

// --- Filtrage par rôle : un groupe sans enfant autorisé disparaît.
$_knMenu = [];
foreach ($_knGroups as $_g) {
    $_children = array_values(array_filter(
        $_g['children'],
        static fn (array $c): bool => $_knRole === '' || in_array($_knRole, $c['roles'], true),
    ));
    if ($_children !== []) {
        $_g['children'] = $_children;
        $_knMenu[] = $_g;
    }
}

// --- Page courante : préfixe le plus long, parmi les seuls enfants visibles.
$_knActiveGroup = null;
$_knActiveChild = null;
$_knBest = 0;
foreach ($_knMenu as $_i => $_g) {
    foreach ($_g['children'] as $_c) {
        $_len = strlen($_c['match']);
        if ($_len > $_knBest && str_starts_with($_knPath, $_c['match'])) {
            $_knBest = $_len;
            $_knActiveGroup = $_i;
            $_knActiveChild = $_c['match'];
        }
    }
    foreach ($_g['also'] ?? [] as $_m) {
        $_len = strlen($_m);
        if ($_len > $_knBest && str_starts_with($_knPath, $_m)) {
            $_knBest = $_len;
            $_knActiveGroup = $_i;
            $_knActiveChild = null; // rattachée au groupe, sans onglet propre
        }
    }
}

// Accueil = premier groupe accessible : dispatch pour l'exploitation, factures
// pour la comptabilité — cohérent avec RoleMiddleware::landingFor().
$_knHome = $_knMenu[0]['children'][0]['href'] ?? '/admin/connexion';

// Le sous-menu n'a de sens qu'à partir de deux onglets : un comptable ne voit
// que « Liste » sous Clients, une barre d'un seul élément serait du bruit.
$_knSub = $_knActiveGroup !== null ? $_knMenu[$_knActiveGroup]['children'] : [];
$_knShowSub = count($_knSub) > 1;

/**
 * Cibles contextuelles (optionnel). Une vue peut, AVANT d'inclure ce partial,
 * définir $_knTabLinks = [match => href] pour que ses sous-onglets conservent
 * le contexte courant.
 *
 * C'est indispensable au Planning : passer d'une semaine à un mois doit garder
 * la période et les filtres affichés, or les trois vues n'attendent pas le même
 * format de date (`YYYY-MM-DD` pour Jour/Semaine, `YYYY-MM` pour Mois). Les
 * ancres sont calculées par les contrôleurs ; les recalculer ici dupliquerait
 * leur logique dans une vue.
 *
 * L'onglet actif pointe vers l'URL courante : inutile de la faire fournir.
 */
$_knTabLinks = $_knTabLinks ?? [];
?>
<header class="kn-header" id="kn-header">
    <a href="<?= $e($_knHome) ?>" class="kn-logo">Keepnew</a>

    <button class="kn-nav-btn" id="kn-nav-btn"
            aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="kn-nav">
        <span></span><span></span><span></span>
    </button>

    <nav class="kn-nav" id="kn-nav" aria-label="Navigation principale">
        <?php foreach ($_knMenu as $_i => $_g): ?>
            <a href="<?= $e($_g['children'][0]['href']) ?>"<?= $_i === $_knActiveGroup ? ' aria-current="true"' : '' ?>><?= $e($_g['label']) ?></a>
        <?php endforeach; ?>
        <a href="/admin/deconnexion" class="kn-nav-logout">Déconnexion</a>
    </nav>
</header>

<?php if ($_knShowSub): ?>
    <nav class="kn-subnav" aria-label="<?= $e($_knMenu[$_knActiveGroup]['label']) ?>">
        <div class="kn-subnav-inner">
            <?php foreach ($_knSub as $_c): ?>
                <?php
                $_isActive = $_c['match'] === $_knActiveChild;
                $_href = $_isActive ? $_knPath . (($_q = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY)) ? '?' . $_q : '')
                    : ($_knTabLinks[$_c['match']] ?? $_c['href']);
                ?>
                <a href="<?= $e($_href) ?>"<?= $_isActive ? ' aria-current="page"' : '' ?>><?= $e($_c['label']) ?></a>
            <?php endforeach; ?>
        </div>
    </nav>
<?php endif; ?>

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
