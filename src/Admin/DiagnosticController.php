<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Core\Csrf;
use Keepnew\Core\Database;
use Keepnew\Core\Exception\NotFoundException;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Router;
use Keepnew\Core\Session;
use Keepnew\Core\View;
use Keepnew\Support\Clock;

/**
 * Page de diagnostic (réservée à l'administrateur).
 *
 * Sert à répondre à distance, sans SSH, à la question « pourquoi telle URL ne
 * répond-elle pas ? » : elle rejoue la résolution de quelques routes sur la
 * table réellement chargée, vérifie la réécriture d'URL et l'état des comptes
 * techniciens.
 *
 * Elle héberge aussi le jeu de rendez-vous de démonstration, qui est un outil
 * de la même famille : on s'en sert pour voir si le planning tient debout.
 *
 * Ne divulgue AUCUN secret : ni contenu du .env, ni identifiants, ni hash.
 */
final class DiagnosticController
{
    /** Période couverte par le jeu de démonstration (heure belge). */
    private const DEMO_FROM = '2026-11-01';
    private const DEMO_TO = '2026-12-31';

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Database $db,
        private readonly Router $router,
        private readonly Csrf $csrf,
        private readonly DemoDataService $demo,
    ) {
    }

    public function index(Request $request): Response
    {
        $root = dirname(__DIR__, 2);

        return $this->view->render('admin/diagnostic', [
            'routes' => $this->routes(),
            'server' => $this->server($root),
            'rewrite' => $this->rewrite($root),
            'technicians' => $this->technicians(),
            'demo_count' => $this->demo->count(),
            'demo_blockers' => $this->demo->blockers(),
            'demo_from' => self::DEMO_FROM,
            'demo_to' => self::DEMO_TO,
            'csrf' => $this->csrf->field(),
            'flash' => $this->session->pullFlash('diag_ok'),
            'error' => $this->session->pullFlash('diag_error'),
            'user_name' => $this->session->get('user_name'),
        ]);
    }

    /**
     * POST /admin/diagnostic/demo — crée le jeu de rendez-vous fictifs.
     */
    public function generateDemo(Request $request): Response
    {
        try {
            $created = $this->demo->generate(
                new \DateTimeImmutable(self::DEMO_FROM . ' 00:00:00', new \DateTimeZone(Clock::DISPLAY_TZ)),
                new \DateTimeImmutable(self::DEMO_TO . ' 00:00:00', new \DateTimeZone(Clock::DISPLAY_TZ)),
            );
            $this->session->flash(
                'diag_ok',
                $created === 0
                    ? "Aucun rendez-vous créé : les créneaux de la période sont déjà occupés."
                    : "{$created} rendez-vous de démonstration créés. Ils portent la référence « " . DemoDataService::REFERENCE_PREFIX . "… » et se retirent d'un clic.",
            );
        } catch (\RuntimeException $e) {
            $this->session->flash('diag_error', $e->getMessage());
        }

        return Response::redirect('/admin/diagnostic');
    }

    /**
     * POST /admin/diagnostic/demo/supprimer — retire le jeu.
     */
    public function purgeDemo(Request $request): Response
    {
        $removed = $this->demo->purge();
        $this->session->flash(
            'diag_ok',
            $removed === 0
                ? 'Aucune donnée de démonstration à retirer.'
                : "{$removed} rendez-vous de démonstration retirés. Les vraies données n'ont pas été touchées.",
        );

        return Response::redirect('/admin/diagnostic');
    }

    /**
     * Résolution de quelques routes sur la table réellement chargée.
     *
     * @return list<array{route:string, state:string, detail:string}>
     */
    private function routes(): array
    {
        $probes = [
            ['GET', '/tech'],
            ['GET', '/tech/job/1'],
            ['GET', '/admin/utilisateurs'],
            ['GET', '/health'],
        ];

        $rows = [];
        foreach ($probes as [$method, $path]) {
            try {
                [, $handler] = $this->router->match(new Request($method, $path));
                $target = is_array($handler)
                    ? substr((string) $handler[0], strrpos((string) $handler[0], '\\') + 1) . '::' . $handler[1]
                    : 'Closure';
                $rows[] = ['route' => $method . ' ' . $path, 'state' => 'ok', 'detail' => $target];
            } catch (NotFoundException $e) {
                $rows[] = ['route' => $method . ' ' . $path, 'state' => 'ko', 'detail' => 'Aucune route déclarée.'];
            }
        }

        return $rows;
    }

    /**
     * Contexte serveur — révèle notamment une installation en sous-dossier
     * (chemin d'URL préfixé), qui ferait échouer toutes les routes absolues.
     *
     * @return array<string, string>
     */
    private function server(string $root): array
    {
        $modules = function_exists('apache_get_modules') ? apache_get_modules() : null;

        return [
            'Version de PHP' => PHP_VERSION,
            'Racine du projet' => $root,
            'DOCUMENT_ROOT' => (string) ($_SERVER['DOCUMENT_ROOT'] ?? '—'),
            'URL demandée (REQUEST_URI)' => (string) ($_SERVER['REQUEST_URI'] ?? '—'),
            'Script exécuté' => (string) ($_SERVER['SCRIPT_NAME'] ?? '—'),
            'mod_rewrite' => $modules === null
                ? 'indéterminé (PHP non lancé en module Apache)'
                : (in_array('mod_rewrite', $modules, true) ? 'actif' : 'ABSENT'),
        ];
    }

    /** Premiers segments d'URL servis par le routeur PHP. */
    private const ROUTE_PREFIXES = ['admin', 'tech', 'api', 'rdv', 'health'];

    /**
     * Réécriture d'URL : version du .htaccess et entrées de public/ capables
     * d'intercepter une route avant le routeur PHP.
     *
     * Un 404 émis par Apache (« The requested URL was not found on this
     * server ») sur une seule route signifie presque toujours qu'une entrée
     * réelle de public/ porte ce nom : la condition « !-f » (ou « !-d » sur
     * l'ancienne version) échoue, la réécriture est sautée, et Apache cherche
     * un fichier qui n'existe pas.
     *
     * @return array{htaccess:string, htaccess_date:string, entries:list<array{name:string, type:string, shadow:bool}>}
     */
    private function rewrite(string $root): array
    {
        $file = $root . '/public/.htaccess';
        if (!is_file($file)) {
            $htaccess = 'ABSENT — sans lui, aucune URL autre que l\'accueil ne fonctionne.';
        } else {
            // On ne teste que les directives actives : le fichier à jour
            // mentionne « !-d » dans un commentaire pour expliquer son absence.
            $directives = array_filter(
                array_map('trim', explode("\n", (string) file_get_contents($file))),
                static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#'),
            );
            $active = implode("\n", $directives);

            $htaccess = match (true) {
                !str_contains($active, 'RewriteRule') => 'présent mais SANS règle de réécriture — les routes ne peuvent pas fonctionner.',
                str_contains($active, '!-d') => 'ANCIENNE version (règle « !-d » active) : un dossier présent dans public/ peut détourner une route.',
                !str_contains($active, '-MultiViews') => 'version intermédiaire : il manque « Options -MultiViews », sans quoi Apache peut détourner /tech vers tech.webmanifest.',
                default => 'à jour',
            };
        }

        // Contenu réel de public/ : toute entrée (fichier OU dossier) portant le
        // nom d'un préfixe de route intercepte cette route avant PHP.
        $entries = [];
        foreach ((array) @scandir($root . '/public') as $entry) {
            if (!is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }
            // Nom exact d'une route → interception directe.
            // Nom « route.quelquechose » → interception possible par MultiViews
            // (négociation de contenu), qui est la cause la plus fréquente d'un
            // 404 Apache sur une seule route.
            $base = strstr($entry, '.', true);
            $shadow = in_array($entry, self::ROUTE_PREFIXES, true)
                || ($base !== false && in_array($base, self::ROUTE_PREFIXES, true));

            $entries[] = [
                'name' => $entry,
                'type' => is_dir($root . '/public/' . $entry) ? 'dossier' : 'fichier',
                'shadow' => $shadow,
            ];
        }

        return [
            'htaccess' => $htaccess,
            'htaccess_date' => is_file($file) ? date('d/m/Y H:i', (int) filemtime($file)) : '—',
            'entries' => $entries,
        ];
    }

    /**
     * Comptes techniciens et fiche rattachée.
     *
     * @return list<array<string, mixed>>
     */
    private function technicians(): array
    {
        return $this->db->select(
            "SELECT u.id, u.email, u.is_active, t.id AS technician_id, t.is_active AS tech_active,
                    (SELECT COUNT(*) FROM technician_skills ts WHERE ts.technician_id = t.id) AS skills,
                    (SELECT COUNT(*) FROM technician_availability ta WHERE ta.technician_id = t.id) AS availability,
                    (SELECT COUNT(*) FROM jobs j WHERE j.technician_id = t.id) AS jobs
               FROM users u
               LEFT JOIN technicians t ON t.user_id = u.id
              WHERE u.role = 'technician'
              ORDER BY u.email",
        );
    }
}
