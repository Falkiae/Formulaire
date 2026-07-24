<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Catalog\CatalogRepository;
use Keepnew\Catalog\ExtraRepository;
use Keepnew\Core\Csrf;
use Keepnew\Core\Exception\NotFoundException;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Session;
use Keepnew\Core\View;
use Keepnew\Support\ImageUpload;

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
        private readonly ExtraRepository $extras,
        private readonly ImageUpload $images,
    ) {
    }

    /**
     * GET /admin/catalogue — arborescence des catégories + liste des services.
     */
    public function index(Request $request): Response
    {
        return $this->view->render('admin/catalog/index', [
            'csrf' => $this->csrf->field(),
            'tree' => $this->catalog->categoryTree(),
            'categories' => $this->catalog->allCategories(),
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

        // Extras disponibles à rattacher = catalogue central moins ceux déjà liés.
        $attached = array_map(static fn (array $e): int => (int) $e['extra_id'], $this->catalog->serviceExtras($id, false));
        $available = array_filter(
            $this->extras->all(true),
            static fn (array $e): bool => !in_array((int) $e['id'], $attached, true),
        );

        return $this->view->render('admin/catalog/service', [
            'csrf' => $this->csrf->field(),
            'service' => $service,
            'categories' => $this->catalog->allCategories(),
            'modes' => $this->catalog->serviceModes($id),
            'variants' => $this->catalog->serviceVariants($id, false),
            'extras' => $this->catalog->serviceExtras($id, false),
            'available_extras' => array_values($available),
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

        // Identité : renommage / recatégorisation (le slug reste stable).
        $name = $request->string('name');
        $categoryId = $request->int('category_id');
        if ($name !== '' && $categoryId > 0) {
            $this->catalog->updateServiceMeta($id, [
                'name' => $name,
                'category_id' => $categoryId,
                'short_description' => $request->string('short_description'),
                'badge_label' => $request->string('badge_label'),
            ]);
        }

        // Prix en euros saisis côté formulaire → conversion en centimes.
        $this->catalog->updateServiceBase($id, [
            'base_price_cents' => $this->eurosToCents($request->string('base_price')),
            'base_duration_min' => max(0, $request->int('base_duration')),
            'is_active' => $request->bool('is_active') ? 1 : 0,
        ], $userId);

        // Un bloc de champs par mode : mode_onsite (case "proposé"), price_onsite,
        // duration_onsite, occupancy_onsite… La case pilote la création/suppression
        // de la ligne service_delivery_modes (présence = mode réservable, cf.
        // LineResolver::resolve()) ; au moins un mode doit rester proposé.
        $anyModeEnabled = false;
        foreach (['onsite', 'workshop'] as $mode) {
            if ($request->bool("mode_{$mode}")) {
                $anyModeEnabled = true;
            }
        }
        if (!$anyModeEnabled) {
            $this->session->flash('catalog_ok', 'Au moins un mode (domicile ou atelier) doit rester proposé.');

            return Response::redirect("/admin/catalogue/service/{$id}");
        }

        foreach (['onsite', 'workshop'] as $mode) {
            if (!$request->bool("mode_{$mode}")) {
                $this->catalog->deleteServiceMode($id, $mode);
                continue;
            }
            $this->catalog->createServiceMode($id, $mode);
            $this->catalog->updateModePricing(
                $id,
                $mode,
                $request->string("price_{$mode}") !== '' ? $this->eurosToCents($request->string("price_{$mode}")) : null,
                $request->has("duration_{$mode}") ? max(0, $request->int("duration_{$mode}")) : null,
                $request->has("occupancy_{$mode}") ? max(0, $request->int("occupancy_{$mode}")) : null,
                $userId,
            );
        }

        // Image de la prestation (upload optionnel ou retrait).
        $image = $service['image_path'] ?? null;
        if ($request->bool('remove_image')) {
            $image = null;
        }
        $file = $request->file('image');
        if ($file !== null) {
            try {
                $image = $this->images->store($file, 'catalog');
            } catch (\RuntimeException $e) {
                $this->session->flash('catalog_ok', 'Image ignorée : ' . $e->getMessage());
            }
        }
        $this->catalog->setServiceImage($id, $image);

        $this->session->flash('catalog_ok', 'Prestation enregistrée.');

        return Response::redirect("/admin/catalogue/service/{$id}");
    }

    /**
     * POST /admin/catalogue/service — création d'une prestation.
     */
    public function createService(Request $request): Response
    {
        $name = $request->string('name');
        $categoryId = $request->int('category_id');
        if ($name === '' || $categoryId <= 0) {
            $this->session->flash('catalog_ok', 'Nom et catégorie sont requis pour créer une prestation.');

            return Response::redirect('/admin/catalogue');
        }

        $id = $this->catalog->createService([
            'category_id' => $categoryId,
            'name' => $name,
            'base_price_cents' => $this->eurosToCents($request->string('base_price')),
            'base_duration_min' => max(0, $request->int('base_duration', 60)),
        ]);

        $this->session->flash('catalog_ok', 'Prestation créée — complétez sa configuration puis activez-la.');

        return Response::redirect("/admin/catalogue/service/{$id}");
    }

    /**
     * POST /admin/catalogue/service/{id}/dupliquer — duplication en un clic.
     */
    public function duplicateService(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $newId = $this->catalog->duplicateService($id);
        if ($newId === null) {
            throw new NotFoundException('Prestation introuvable.');
        }

        $this->session->flash('catalog_ok', 'Prestation dupliquée (inactive).');

        return Response::redirect("/admin/catalogue/service/{$newId}");
    }

    /**
     * POST /admin/catalogue/service/{id}/supprimer — suppression ou désactivation.
     */
    public function deleteService(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $action = $this->catalog->deleteOrDeactivateService($id);
        $this->session->flash(
            'catalog_ok',
            $action === 'deleted' ? 'Prestation supprimée.' : 'Prestation déjà commandée : désactivée plutôt que supprimée.',
        );

        return Response::redirect('/admin/catalogue');
    }

    // --- Variantes -----------------------------------------------------------

    /**
     * POST /admin/catalogue/service/{id}/variante — ajout d'une variante.
     */
    public function createVariant(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $label = $request->string('label');
        if ($label !== '') {
            $this->catalog->createVariant($id, [
                'label' => $label,
                'price_delta_cents' => $this->eurosToCents($request->string('price_delta')),
                'duration_delta_min' => $request->int('duration_delta'),
                'is_active' => 1,
            ]);
            $this->session->flash('catalog_ok', 'Variante ajoutée.');
        }

        return Response::redirect("/admin/catalogue/service/{$id}");
    }

    /**
     * POST /admin/catalogue/service/{id}/variante/{variantId} — mise à jour.
     */
    public function updateVariant(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $variantId = (int) $request->attribute('variantId');
        $this->catalog->updateVariant($variantId, $id, [
            'label' => $request->string('label'),
            'price_delta_cents' => $this->eurosToCents($request->string('price_delta')),
            'duration_delta_min' => $request->int('duration_delta'),
            'is_active' => $request->bool('is_active') ? 1 : 0,
            'is_default' => $request->bool('is_default'),
        ]);
        $this->session->flash('catalog_ok', 'Variante enregistrée.');

        return Response::redirect("/admin/catalogue/service/{$id}");
    }

    /**
     * POST /admin/catalogue/service/{id}/variante/{variantId}/supprimer.
     */
    public function deleteVariant(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $variantId = (int) $request->attribute('variantId');
        $deleted = $this->catalog->deleteVariant($variantId);
        $this->session->flash(
            'catalog_ok',
            $deleted ? 'Variante supprimée.' : 'Variante déjà utilisée : désactivée plutôt que supprimée.',
        );

        return Response::redirect("/admin/catalogue/service/{$id}");
    }

    // --- Réordonnancement ----------------------------------------------------

    /**
     * POST /admin/catalogue/services/ordre — réordonnancement (JSON, drag & drop).
     */
    public function reorderServices(Request $request): Response
    {
        $this->catalog->reorder('services', array_map('intval', $request->array('order')));

        return Response::json(['status' => 'ok']);
    }

    /**
     * POST /admin/catalogue/service/{id}/variantes/ordre — réordonnancement.
     */
    public function reorderVariants(Request $request): Response
    {
        $this->catalog->reorder('variants', array_map('intval', $request->array('order')));

        return Response::json(['status' => 'ok']);
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
