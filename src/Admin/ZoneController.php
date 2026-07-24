<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Core\Csrf;
use Keepnew\Core\Exception\NotFoundException;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Session;
use Keepnew\Core\View;
use Keepnew\Zone\ZoneRepository;

/**
 * Administration de la zone de chalandise : zones de service (rayon ou codes
 * postaux), règles tarifaires (refus/supplément/remise) et affectation des
 * techniciens couvrants. Pilote les tables lues par ZoneResolver
 * (src/Availability) — la logique de résolution n'est pas modifiée ici.
 */
final class ZoneController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly ZoneRepository $zones,
    ) {
    }

    /**
     * GET /admin/zones — liste.
     */
    public function index(Request $request): Response
    {
        return $this->view->render('admin/zones/index', [
            'zones' => $this->zones->all(),
            'flash' => $this->session->pullFlash('zone_ok'),
            'user_name' => $this->session->get('user_name'),
        ]);
    }

    /**
     * GET /admin/zones/nouvelle — formulaire de création.
     */
    public function createForm(Request $request): Response
    {
        return $this->renderForm(null);
    }

    /**
     * POST /admin/zones — création.
     */
    public function create(Request $request): Response
    {
        if ($request->string('name') === '') {
            $this->session->flash('zone_error', 'Le nom de la zone est requis.');

            return Response::redirect('/admin/zones/nouvelle');
        }

        $id = $this->zones->create($this->fromRequest($request));
        $this->zones->syncPostalCodes($id, $this->splitPostalCodes($request->string('postal_codes')));

        $this->session->flash('zone_ok', 'Zone créée.');

        return Response::redirect('/admin/zones/' . $id);
    }

    /**
     * GET /admin/zones/{id} — fiche éditable + sous-ressources.
     */
    public function edit(Request $request): Response
    {
        $zone = $this->requireZone($request);

        return $this->renderForm($zone);
    }

    /**
     * POST /admin/zones/{id} — mise à jour de la fiche + codes postaux.
     */
    public function update(Request $request): Response
    {
        $zone = $this->requireZone($request);
        $id = (int) $zone['id'];

        $this->zones->update($id, $this->fromRequest($request));
        $this->zones->syncPostalCodes($id, $this->splitPostalCodes($request->string('postal_codes')));

        $this->session->flash('zone_ok', 'Zone enregistrée.');

        return Response::redirect('/admin/zones/' . $id);
    }

    /**
     * POST /admin/zones/{id}/supprimer
     */
    public function delete(Request $request): Response
    {
        $zone = $this->requireZone($request);
        $this->zones->delete((int) $zone['id']);
        $this->session->flash('zone_ok', 'Zone supprimée.');

        return Response::redirect('/admin/zones');
    }

    /**
     * POST /admin/zones/{id}/regle — ajoute une règle tarifaire.
     */
    public function addModifier(Request $request): Response
    {
        $zone = $this->requireZone($request);
        $type = $request->string('modifier_type');
        $calcType = $request->string('calc_type');
        $label = $request->string('label');

        if (!in_array($type, ['refuse', 'surcharge', 'discount'], true)) {
            $this->session->flash('zone_ok', 'Type de règle invalide.');

            return Response::redirect('/admin/zones/' . (int) $zone['id']);
        }

        // Le refus n'a pas de montant ; supplément/remise en centimes ou points de base.
        $calcValue = $type === 'refuse' ? null : $this->eurosOrPercentToValue($request->string('calc_value'), $calcType);

        $this->zones->addModifier((int) $zone['id'], $type, $type === 'refuse' ? null : $calcType, $calcValue, $label);
        $this->session->flash('zone_ok', 'Règle ajoutée.');

        return Response::redirect('/admin/zones/' . (int) $zone['id']);
    }

    /**
     * POST /admin/zones/{id}/regle/{modifierId}/supprimer
     */
    public function deleteModifier(Request $request): Response
    {
        $zone = $this->requireZone($request);
        $this->zones->deleteModifier((int) $request->attribute('modifierId'), (int) $zone['id']);
        $this->session->flash('zone_ok', 'Règle supprimée.');

        return Response::redirect('/admin/zones/' . (int) $zone['id']);
    }

    /**
     * POST /admin/zones/{id}/techniciens — synchronise les techniciens couvrants.
     */
    public function syncTechnicians(Request $request): Response
    {
        $zone = $this->requireZone($request);
        $technicianIds = array_map('intval', $request->array('technicians'));
        $this->zones->syncTechnicians((int) $zone['id'], $technicianIds);
        $this->session->flash('zone_ok', 'Techniciens mis à jour.');

        return Response::redirect('/admin/zones/' . (int) $zone['id']);
    }

    // --- Helpers ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function requireZone(Request $request): array
    {
        $zone = $this->zones->find((int) $request->attribute('id'));
        if ($zone === null) {
            throw new NotFoundException('Zone introuvable.');
        }

        return $zone;
    }

    /**
     * @return array<string, mixed>
     */
    private function fromRequest(Request $request): array
    {
        return [
            'name' => $request->string('name'),
            'priority' => $request->int('priority', 100),
            'is_active' => $request->bool('is_active'),
        ];
    }

    /**
     * Découpe la saisie libre (un code par ligne ou séparé par des virgules).
     *
     * @return list<string>
     */
    private function splitPostalCodes(string $raw): array
    {
        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $c): bool => $c !== ''));
    }

    /**
     * Convertit une saisie euros (calc_type=fixed) en centimes, ou un nombre
     * de points de base (calc_type=percent) tel quel.
     */
    private function eurosOrPercentToValue(string $raw, string $calcType): int
    {
        $normalized = str_replace(',', '.', $raw);
        if (!is_numeric($normalized)) {
            return 0;
        }

        return $calcType === 'percent'
            ? (int) round((float) $normalized * 100) // ex. 10 % → 1000 points de base
            : (int) round((float) $normalized * 100); // ex. 15 € → 1500 centimes
    }

    /**
     * @param array<string, mixed>|null $zone
     */
    private function renderForm(?array $zone): Response
    {
        $zoneId = $zone !== null ? (int) $zone['id'] : 0;

        return $this->view->render('admin/zones/edit', [
            'csrf' => $this->csrf->field(),
            'zone' => $zone,
            'postal_codes' => $zoneId > 0 ? $this->zones->postalCodesFor($zoneId) : [],
            'modifiers' => $zoneId > 0 ? $this->zones->modifiersFor($zoneId) : [],
            'technicians' => $this->zones->activeTechnicians(),
            'zone_technician_ids' => $zoneId > 0 ? $this->zones->technicianIdsFor($zoneId) : [],
            'error' => $this->session->pullFlash('zone_error'),
            'flash' => $this->session->pullFlash('zone_ok'),
            'user_name' => $this->session->get('user_name'),
        ]);
    }
}
