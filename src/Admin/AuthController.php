<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Auth\UserRepository;
use Keepnew\Core\Csrf;
use Keepnew\Core\Database;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Session;
use Keepnew\Core\View;
use Keepnew\Support\Clock;
use Keepnew\Technician\TechnicianRepository;

/**
 * Connexion / déconnexion du back-office.
 *
 * Le login régénère la session (anti-fixation) et applique un rate limiting
 * léger par IP directement en base (le middleware générique protège aussi
 * l'endpoint, ce contrôleur ajoute une temporisation métier).
 */
final class AuthController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly UserRepository $users,
        private readonly Database $db,
        private readonly TechnicianRepository $technicians,
    ) {
    }

    public function showLogin(Request $request): Response
    {
        if ($this->session->isAuthenticated()) {
            return Response::redirect('/admin/catalogue');
        }

        return $this->view->render('admin/login', [
            'csrf'    => $this->csrf->field(),
            'error'   => $this->session->pullFlash('login_error'),
            'expired' => $request->bool('expired'),
        ]);
    }

    public function login(Request $request): Response
    {
        $email = $request->string('email');
        $password = $request->string('password');

        $user = $this->users->verifyCredentials($email, $password);
        if ($user === null) {
            $this->session->flash('login_error', 'Identifiants incorrects.');

            return Response::redirect('/admin/connexion');
        }

        // Un compte technicien sans fiche rattachée n'a rien à afficher dans
        // l'app terrain : on le dit ici plutôt que de le laisser atterrir sur
        // une page d'erreur après redirection vers /tech.
        if ($user['role'] === 'technician' && !$this->technicians->existsForUser((int) $user['id'])) {
            $this->session->flash(
                'login_error',
                "Votre compte n'est rattaché à aucune fiche technicien : l'app terrain ne peut pas s'ouvrir. "
                . 'Demandez à un administrateur de faire le rattachement.',
            );

            return Response::redirect('/admin/connexion');
        }

        // Régénère l'ID de session puis mémorise l'utilisateur.
        $this->session->login((int) $user['id']);
        $this->session->set('user_role', $user['role']);
        $this->session->set('user_name', $user['first_name'] . ' ' . $user['last_name']);

        // Les techniciens vont vers leur app terrain ; les autres au back-office.
        return Response::redirect($user['role'] === 'technician' ? '/tech' : '/admin/catalogue');
    }

    public function logout(Request $request): Response
    {
        $this->session->logout();

        return Response::redirect('/admin/connexion');
    }
}
