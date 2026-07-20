<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Catalog\CatalogRepository;
use Keepnew\Core\Csrf;
use Keepnew\Core\Exception\NotFoundException;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Session;
use Keepnew\Core\View;

/**
 * Back-office catalogue : liste des catégories/services, fiche service éditable
 * (prix/durée de base, prix/durée par mode, activation), avec historisation des
 * prix. Le simulateur de prix est piloté par SimulatorController.
 */
final class CatalogController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly CatalogRepository $catalog,
    ) {
    }

    /**
     * GET /admin/catalogue — arborescence des catégories + liste des services.
     */
    public function index(Request $request): Response
    {
        return $this->view->render('admin/catalog/index', [
            'tree' => $this->catalog->categoryTree(),
            'services' => $this->catalog->allServices(),
            'user_name' => $this->session->get('user_name'),
            'flash' => $this->session->pullFlash('catalog_ok'),
        ]);
    }

    /**
     * GET /admin/catalogue/service/{id} — fiche service éditable.
     */
    public function editService(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $service = $this->catalog->findService($id);
        if ($service === null) {
            throw new NotFoundException('Prestation introuvable.');
        }

        return $this->view->render('admin/catalog/service', [
            'csrf' => $this->csrf->field(),
            'service' => $service,
            'modes' => $this->catalog->serviceModes($id),
            'variants' => $this->catalog->serviceVariants($id, false),
            'extras' => $this->catalog->serviceExtras($id, false),
            'user_name' => $this->session->get('user_name'),
            'flash' => $this->session->pullFlash('catalog_ok'),
        ]);
    }

    /**
     * POST /admin/catalogue/service/{id} — enregistre le prix/durée de base,
     * l'activation, et le prix/durée de chaque mode.
     */
    public function saveService(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $service = $this->catalog->findService($id);
        if ($service === null) {
            throw new NotFoundException('Prestation introuvable.');
        }

        $userId = $this->session->userId();

        // Prix en euros saisis côté formulaire → conversion en centimes.
        $this->catalog->updateServiceBase($id, [
            'base_price_cents' => $this->eurosToCents($request->string('base_price')),
            'base_duration_min' => max(0, $request->int('base_duration')),
            'is_active' => $request->bool('is_active') ? 1 : 0,
        ], $userId);

        // Un bloc de champs par mode : price_onsite, duration_onsite, occupancy_onsite…
        foreach (['onsite', 'workshop'] as $mode) {
            if (!$request->has("price_{$mode}") && !$request->has("duration_{$mode}")) {
                continue;
            }
            $this->catalog->updateModePricing(
                $id,
                $mode,
                $request->string("price_{$mode}") !== '' ? $this->eurosToCents($request->string("price_{$mode}")) : null,
                $request->has("duration_{$mode}") ? max(0, $request->int("duration_{$mode}")) : null,
                $request->has("occupancy_{$mode}") ? max(0, $request->int("occupancy_{$mode}")) : null,
                $userId,
            );
        }

        $this->session->flash('catalog_ok', 'Prestation enregistrée.');

        return Response::redirect("/admin/catalogue/service/{$id}");
    }

    /**
     * Convertit un montant saisi en euros (« 79 » ou « 79,50 ») en centimes.
     */
    private function eurosToCents(string $euros): int
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($euros));
        if ($normalized === '' || !is_numeric($normalized)) {
            return 0;
        }

        return (int) round(((float) $normalized) * 100);
    }
}
