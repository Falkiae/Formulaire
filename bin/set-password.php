<?php

declare(strict_types=1);

/**
 * Définit (ou réinitialise) le mot de passe d'un utilisateur du back-office.
 *
 * Usage (en ligne de commande / SSH, ou en tâche cron ponctuelle) :
 *   php bin/set-password.php email@exemple.be "MonNouveauMotDePasse"
 *
 * Le mot de passe est haché en Argon2id. Après usage en tâche cron OVH,
 * pensez à supprimer la tâche.
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

$email = $argv[1] ?? null;
$password = $argv[2] ?? null;

if ($email === null || $password === null || strlen($password) < 8) {
    fwrite(STDERR, "Usage : php bin/set-password.php <email> <mot_de_passe_min_8_caracteres>\n");
    exit(1);
}

$config = new Config(require $root . '/config/config.php');
$db = Database::fromConfig($config);

$user = $db->selectOne('SELECT id, email FROM users WHERE email = :e', ['e' => $email]);
if ($user === null) {
    fwrite(STDERR, "Utilisateur introuvable : {$email}\n");
    exit(2);
}

$hash = password_hash($password, PASSWORD_ARGON2ID);
$db->run('UPDATE users SET password_hash = :h WHERE id = :id', ['h' => $hash, 'id' => (int) $user['id']]);

echo "Mot de passe mis à jour pour {$email}.\n";
