<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Core\Database;
use Keepnew\Core\Exception\NotFoundException;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Router;
use Keepnew\Core\Session;
use Keepnew\Core\View;

/**
 * Page de diagnostic (réservée à l'administrateur).
 *
 * Sert à répondre à distance, sans SSH, à la question « le code déployé est-il
 * bien celui attendu, et pourquoi telle URL ne répond-elle pas ? ». Elle
 * compare les fichiers présents sur le serveur à des marqueurs connus, rejoue
 * la résolution de quelques routes sur la table de routage réellement chargée,
 * et vérifie l'état des comptes techniciens.
 *
 * Ne divulgue AUCUN secret : ni contenu du .env, ni identifiants, ni hash.
 */
final class DiagnosticController
{
    /**
     * Fichiers du correctif « app technicien » et marqueur prouvant que la
     * version déployée est bien la nouvelle.
     */
    private const EXPECTED = [
        'views/tech/no-profile.php' => "n'est pas encore activée",
        'src/Tech/TechController.php' => 'technicianOrNull',
        'src/Admin/AuthController.php' => 'existsForUser',
        'src/Admin/UserController.php' => 'syncTechnicianProfile',
        'src/Technician/TechnicianRepository.php' => 'releaseAccountFromOtherProfiles',
        'src/Auth/UserRepository.php' => 'technician_id',
        'views/admin/users/index.php' => 'sans fiche technicien',
        'views/admin/users/edit.php' => 'technician_profile',
        'config/routes.php' => 'DiagnosticController',
        'config/bootstrap.php' => 'instance(Router::class',
    ];

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Database $db,
        private readonly Router $router,
    ) {
    }

    public function index(Request $request): Response
    {
        $root = dirname(__DIR__, 2);

        return $this->view->render('admin/diagnostic', [
            'files' => $this->files($root),
            'routes' => $this->routes(),
            'server' => $this->server($root),
            'rewrite' => $this->rewrite($root),
            'technicians' => $this->technicians(),
            'user_name' => $this->session->get('user_name'),
        ]);
    }

    /**
     * Présence + fraîcheur des fichiers du correctif.
     *
     * @return list<array{path:string, state:string, detail:string}>
     */
    private function files(string $root): array
    {
        $rows = [];
        foreach (self::EXPECTED as $path => $marker) {
            $full = $root . '/' . $path;
            if (!is_file($full)) {
                $rows[] = ['path' => $path, 'state' => 'absent', 'detail' => 'Fichier introuvable sur le serveur.'];
                continue;
            }
            $contents = (string) file_get_contents($full);
            $date = date('d/m/Y H:i', (int) filemtime($full));
            $rows[] = str_contains($contents, $marker)
                ? ['path' => $path, 'state' => 'ok', 'detail' => 'Version à jour — envoyé le ' . $date . '.']
                : ['path' => $path, 'state' => 'ancien', 'detail' => 'ANCIENNE version encore en place (fichier du ' . $date . ').'];
        }

        return $rows;
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

    /**
     * Réécriture d'URL : version du .htaccess et entrées de public/ capables
     * d'intercepter une route avant le routeur PHP.
     *
     * @return array{htaccess:string, shadows:list<string>}
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
                default => 'à jour',
            };
        }

        // Tout dossier de public/ portant le nom d'une route est suspect.
        $shadows = [];
        foreach ((array) @scandir($root . '/public') as $entry) {
            if (!is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($root . '/public/' . $entry) && !in_array($entry, ['assets', 'uploads'], true)) {
                $shadows[] = $entry;
            }
        }

        return ['htaccess' => $htaccess, 'shadows' => $shadows];
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
