<?php

declare(strict_types=1);

/**
 * Applique les migrations SQL de database/migrations/ encore manquantes.
 *
 * Usage (SSH, ou tâche cron OVH ponctuelle) :
 *   php bin/migrate.php            # applique ce qui manque
 *   php bin/migrate.php --status   # liste sans rien appliquer
 *   php bin/migrate.php --mark-all # marque tout comme appliqué, sans exécuter
 *
 * Pourquoi : les sept premières migrations ont été appliquées à la main, sans
 * trace de ce qui était passé sur quelle base. On ne pouvait donc ni savoir si
 * une migration avait été jouée, ni la rejouer sans risque. Une table
 * `schema_migrations` lève les deux problèmes.
 *
 * `--mark-all` sert UNE FOIS, sur une base déjà à jour manuellement : il
 * enregistre les fichiers comme appliqués sans les exécuter, pour partir d'un
 * état propre sans rejouer des ALTER déjà passés.
 *
 * Chaque fichier est exécuté dans une transaction quand le moteur le permet.
 * Attention : MySQL valide implicitement les DDL (ALTER, CREATE TABLE), une
 * migration de schéma interrompue en son milieu reste donc partiellement
 * appliquée — d'où le découpage en fichiers courts et idempotents.
 */

use Keepnew\Core\Config;
use Keepnew\Core\Database;

$root = dirname(__DIR__);

// Autoloader (Composer si présent, sinon PSR-4 maison).
$composer = $root . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'Keepnew\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

$args = array_slice($argv, 1);
$statusOnly = in_array('--status', $args, true);
$markAll = in_array('--mark-all', $args, true);

$config = new Config(require $root . '/config/config.php');
$db = Database::fromConfig($config);

$db->run(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        filename    VARCHAR(190) NOT NULL,
        applied_at  DATETIME     NOT NULL,
        PRIMARY KEY (filename)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
);

$applied = array_map(
    static fn (array $r): string => (string) $r['filename'],
    $db->select('SELECT filename FROM schema_migrations'),
);

$files = glob($root . '/database/migrations/*.sql') ?: [];
sort($files);

if ($files === []) {
    echo "Aucune migration dans database/migrations/.\n";
    exit(0);
}

$pending = [];
foreach ($files as $file) {
    $name = basename($file);
    $done = in_array($name, $applied, true);
    printf("  %-58s %s\n", $name, $done ? 'appliquée' : 'EN ATTENTE');
    if (!$done) {
        $pending[] = $file;
    }
}

if ($statusOnly) {
    printf("\n%d en attente sur %d.\n", count($pending), count($files));
    exit(0);
}

if ($pending === []) {
    echo "\nBase à jour.\n";
    exit(0);
}

if ($markAll) {
    foreach ($pending as $file) {
        $db->insert('schema_migrations', ['filename' => basename($file), 'applied_at' => gmdate('Y-m-d H:i:s')]);
    }
    printf("\n%d migration(s) marquée(s) comme appliquée(s), sans exécution.\n", count($pending));
    exit(0);
}

echo "\n";
$count = 0;
foreach ($pending as $file) {
    $name = basename($file);
    $sql = (string) file_get_contents($file);

    try {
        // exec() plutôt que des requêtes préparées : un fichier de migration
        // contient plusieurs instructions, et aucune donnée utilisateur.
        $db->pdo()->exec($sql);
        $db->insert('schema_migrations', ['filename' => $name, 'applied_at' => gmdate('Y-m-d H:i:s')]);
        printf("  appliquée : %s\n", $name);
        $count++;
    } catch (\Throwable $e) {
        fwrite(STDERR, sprintf("\nÉCHEC sur %s :\n  %s\n", $name, $e->getMessage()));
        fwrite(STDERR, "Les migrations suivantes n'ont pas été tentées.\n");
        exit(1);
    }
}

printf("\n%d migration(s) appliquée(s).\n", $count);
