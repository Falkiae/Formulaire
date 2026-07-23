<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Auth\UserRepository;
use Keepnew\Core\Csrf;
use Keepnew\Core\Exception\NotFoundException;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Session;
use Keepnew\Core\View;

/**
 * Gestion des comptes du back-office et de l'app technicien (réservé admin).
 *
 * Crée / édite les utilisateurs et leur rôle. Le rôle détermine ensuite l'accès
 * aux sections via RoleMiddleware.
 */
final class UserController
{
    /** Rôles valides (miroir de l'ENUM users.role). */
    private const ROLES = ['admin', 'dispatcher', 'technician', 'accountant'];

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * GET /admin/utilisateurs — liste des comptes.
     */
    public function index(Request $request): Response
    {
        return $this->view->render('admin/users/index', [
            'csrf' => $this->csrf->field(),
            'users' => $this->users->all(),
            'self_id' => $this->session->userId(),
            'flash' => $this->session->pullFlash('users_ok'),
            'user_name' => $this->session->get('user_name'),
        ]);
    }

    /**
     * GET /admin/utilisateurs/nouveau — formulaire de création.
     */
    public function createForm(Request $request): Response
    {
        return $this->renderForm(null);
    }

    /**
     * POST /admin/utilisateurs — création d'un compte.
     */
    public function create(Request $request): Response
    {
        $error = $this->validate($request, null);
        if ($error !== null) {
            $this->session->flash('users_error', $error);

            return Response::redirect('/admin/utilisateurs/nouveau');
        }

        $this->users->create([
            'email' => $request->string('email'),
            'password' => $request->string('password'),
            'first_name' => $request->string('first_name'),
            'last_name' => $request->string('last_name'),
            'phone' => $request->string('phone'),
            'role' => $request->string('role'),
            'is_active' => $request->bool('is_active'),
        ]);

        $this->session->flash('users_ok', 'Compte créé.');

        return Response::redirect('/admin/utilisateurs');
    }

    /**
     * GET /admin/utilisateurs/{id} — formulaire d'édition.
     */
    public function edit(Request $request): Response
    {
        $user = $this->users->find((int) $request->attribute('id'));
        if ($user === null) {
            throw new NotFoundException('Compte introuvable.');
        }

        return $this->renderForm($user);
    }

    /**
     * POST /admin/utilisateurs/{id} — mise à jour (mot de passe optionnel).
     */
    public function update(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $user = $this->users->find($id);
        if ($user === null) {
            throw new NotFoundException('Compte introuvable.');
        }

        $error = $this->validate($request, $id);
        if ($error !== null) {
            $this->session->flash('users_error', $error);

            return Response::redirect('/admin/utilisateurs/' . $id);
        }

        // Empêche un admin de retirer son propre accès (rôle ou désactivation).
        $isSelf = $this->session->userId() === $id;
        $message = 'Compte enregistré.';
        $role = $request->string('role');
        $isActive = $request->bool('is_active');
        if ($isSelf && ($role !== 'admin' || !$isActive)) {
            $role = 'admin';
            $isActive = true;
            $message = 'Compte enregistré (votre propre rôle admin et votre accès ont été conservés).';
        }

        $this->users->update($id, [
            'email' => $request->string('email'),
            'first_name' => $request->string('first_name'),
            'last_name' => $request->string('last_name'),
            'phone' => $request->string('phone'),
            'role' => $role,
            'is_active' => $isActive,
        ]);

        $newPassword = $request->string('password');
        if ($newPassword !== '') {
            $this->users->updatePassword($id, $newPassword);
        }

        $this->session->flash('users_ok', $message);

        return Response::redirect('/admin/utilisateurs');
    }

    /**
     * POST /admin/utilisateurs/{id}/actif — bascule actif/inactif.
     */
    public function toggleActive(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $user = $this->users->find($id);
        if ($user === null) {
            throw new NotFoundException('Compte introuvable.');
        }

        if ($this->session->userId() === $id) {
            $this->session->flash('users_ok', 'Vous ne pouvez pas désactiver votre propre compte.');

            return Response::redirect('/admin/utilisateurs');
        }

        $this->users->setActive($id, (int) $user['is_active'] !== 1);
        $this->session->flash('users_ok', (int) $user['is_active'] === 1 ? 'Compte désactivé.' : 'Compte réactivé.');

        return Response::redirect('/admin/utilisateurs');
    }

    /**
     * Valide les champs communs. Renvoie un message d'erreur ou null.
     */
    private function validate(Request $request, ?int $exceptId): ?string
    {
        $email = $request->string('email');
        $first = $request->string('first_name');
        $last = $request->string('last_name');
        $role = $request->string('role');
        $password = $request->string('password');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Adresse e-mail invalide.';
        }
        if ($first === '' || $last === '') {
            return 'Prénom et nom sont requis.';
        }
        if (!in_array($role, self::ROLES, true)) {
            return 'Rôle invalide.';
        }
        if ($this->users->emailExists($email, $exceptId)) {
            return 'Cette adresse e-mail est déjà utilisée.';
        }
        // Mot de passe requis à la création ; optionnel en édition.
        if ($exceptId === null && strlen($password) < 8) {
            return 'Le mot de passe doit contenir au moins 8 caractères.';
        }
        if ($exceptId !== null && $password !== '' && strlen($password) < 8) {
            return 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $user
     */
    private function renderForm(?array $user): Response
    {
        return $this->view->render('admin/users/edit', [
            'csrf' => $this->csrf->field(),
            'user' => $user,
            'roles' => self::ROLES,
            'is_self' => $user !== null && $this->session->userId() === (int) $user['id'],
            'error' => $this->session->pullFlash('users_error'),
            'user_name' => $this->session->get('user_name'),
        ]);
    }
}
