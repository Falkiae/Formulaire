<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Core\Csrf;
use Keepnew\Core\Exception\NotFoundException;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Session;
use Keepnew\Core\View;
use Keepnew\Customer\CustomerRepository;

/**
 * Clients : liste (LTV / fréquence / segment), fiche détaillée et ÉDITION
 * complète (coordonnées, adresses, notes, création). Les comptables ont un
 * accès en lecture seule (les actions d'écriture sont réservées à ops via le
 * RoleMiddleware) ; les boutons d'édition sont masqués en conséquence.
 */
final class CustomerController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly CustomerRepository $customers,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->view->render('admin/customers', [
            'customers' => $this->customers->list($request->string('q')),
            'q' => $request->string('q'),
            'can_edit' => $this->canEdit(),
            'flash' => $this->session->pullFlash('customer_ok'),
            'user_name' => $this->session->get('user_name'),
        ]);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $customer = $this->customers->find($id);
        if ($customer === null) {
            throw new NotFoundException('Client introuvable.');
        }

        return $this->view->render('admin/customer', [
            'csrf' => $this->csrf->field(),
            'customer' => $customer,
            'bookings' => $this->customers->bookingsFor($id),
            'ltv_cents' => $this->customers->ltvFor($id),
            'addresses' => $this->customers->addressesFor($id),
            'notes' => $this->customers->notesFor($id),
            'can_edit' => $this->canEdit() && $customer['anonymized_at'] === null,
            'flash' => $this->session->pullFlash('customer_ok'),
            'user_name' => $this->session->get('user_name'),
        ]);
    }

    // --- Création / édition du client ------------------------------------------

    public function createForm(Request $request): Response
    {
        return $this->renderForm(null);
    }

    public function create(Request $request): Response
    {
        $error = $this->validate($request);
        if ($error !== null) {
            $this->session->flash('customer_error', $error);

            return Response::redirect('/admin/clients/nouveau');
        }

        $id = $this->customers->create($this->fromRequest($request));
        $this->session->flash('customer_ok', 'Client créé.');

        return Response::redirect('/admin/client/' . $id);
    }

    public function edit(Request $request): Response
    {
        $customer = $this->requireEditable($request);

        return $this->renderForm($customer);
    }

    public function update(Request $request): Response
    {
        $customer = $this->requireEditable($request);
        $error = $this->validate($request);
        if ($error !== null) {
            $this->session->flash('customer_error', $error);

            return Response::redirect('/admin/client/' . (int) $customer['id'] . '/editer');
        }

        $this->customers->update((int) $customer['id'], $this->fromRequest($request));
        $this->session->flash('customer_ok', 'Fiche client enregistrée.');

        return Response::redirect('/admin/client/' . (int) $customer['id']);
    }

    // --- Adresses --------------------------------------------------------------

    public function addAddress(Request $request): Response
    {
        $customer = $this->requireEditable($request);
        if ($request->string('street') === '' || $request->string('postal_code') === '' || $request->string('city') === '') {
            $this->session->flash('customer_ok', 'Rue, code postal et ville sont requis.');
        } else {
            $this->customers->addAddress((int) $customer['id'], $this->addressFromRequest($request));
            $this->session->flash('customer_ok', 'Adresse ajoutée.');
        }

        return Response::redirect('/admin/client/' . (int) $customer['id']);
    }

    public function updateAddress(Request $request): Response
    {
        $customer = $this->requireEditable($request);
        $this->customers->updateAddress(
            (int) $request->attribute('addrId'),
            (int) $customer['id'],
            $this->addressFromRequest($request),
        );
        $this->session->flash('customer_ok', 'Adresse enregistrée.');

        return Response::redirect('/admin/client/' . (int) $customer['id']);
    }

    public function deleteAddress(Request $request): Response
    {
        $customer = $this->requireEditable($request);
        $this->customers->deleteAddress((int) $request->attribute('addrId'), (int) $customer['id']);
        $this->session->flash('customer_ok', 'Adresse supprimée.');

        return Response::redirect('/admin/client/' . (int) $customer['id']);
    }

    // --- Notes -----------------------------------------------------------------

    public function addNote(Request $request): Response
    {
        $customer = $this->requireEditable($request);
        $body = $request->string('body');
        if ($body !== '') {
            $this->customers->addNote((int) $customer['id'], $this->session->userId(), $body);
            $this->session->flash('customer_ok', 'Note ajoutée.');
        }

        return Response::redirect('/admin/client/' . (int) $customer['id']);
    }

    // --- Helpers ---------------------------------------------------------------

    private function canEdit(): bool
    {
        return in_array((string) $this->session->get('user_role', ''), ['admin', 'dispatcher'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireEditable(Request $request): array
    {
        $customer = $this->customers->find((int) $request->attribute('id'));
        if ($customer === null) {
            throw new NotFoundException('Client introuvable.');
        }
        if ($customer['anonymized_at'] !== null) {
            $this->session->flash('customer_ok', 'Client anonymisé (RGPD) : non modifiable.');

            throw new NotFoundException('Client anonymisé.');
        }

        return $customer;
    }

    private function validate(Request $request): ?string
    {
        $email = $request->string('email');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Adresse e-mail invalide.';
        }
        if ($request->string('type') === 'b2b' && $request->string('company_name') === '') {
            return 'Le nom de société est requis pour un client B2B.';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function fromRequest(Request $request): array
    {
        return [
            'type' => $request->string('type', 'b2c'),
            'first_name' => $request->string('first_name'),
            'last_name' => $request->string('last_name'),
            'company_name' => $request->string('company_name'),
            'vat_number' => $request->string('vat_number'),
            'email' => $request->string('email'),
            'phone' => $request->string('phone'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function addressFromRequest(Request $request): array
    {
        return [
            'label' => $request->string('label'),
            'street' => $request->string('street'),
            'number' => $request->string('number'),
            'box' => $request->string('box'),
            'postal_code' => $request->string('postal_code'),
            'city' => $request->string('city'),
            'country' => $request->string('country', 'BE'),
            'access_notes' => $request->string('access_notes'),
        ];
    }

    /**
     * @param array<string, mixed>|null $customer
     */
    private function renderForm(?array $customer): Response
    {
        return $this->view->render('admin/customer/edit', [
            'csrf' => $this->csrf->field(),
            'customer' => $customer,
            'error' => $this->session->pullFlash('customer_error'),
            'user_name' => $this->session->get('user_name'),
        ]);
    }
}
