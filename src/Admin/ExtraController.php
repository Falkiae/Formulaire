<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Catalog\ExtraRepository;
use Keepnew\Core\Csrf;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Session;
use Keepnew\Core\View;
use Keepnew\Support\ImageUpload;

/**
 * Catalogue central des extras + rattachement aux services (pivot).
 */
final class ExtraController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly ExtraRepository $extras,
        private readonly ImageUpload $images,
    ) {
    }

    /**
     * Résout l'image après soumission (retrait / upload / conservation).
     * Une image invalide est ignorée avec un flash.
     */
    private function handleImage(Request $request, ?string $current): ?string
    {
        if ($request->bool('remove_image')) {
            $current = null;
        }
        $file = $request->file('image');
        if ($file !== null) {
            try {
                $current = $this->images->store($file, 'catalog');
            } catch (\RuntimeException $e) {
                $this->session->flash('extras_ok', 'Image ignorée : ' . $e->getMessage());
            }
        }

        return $current;
    }

    /**
     * GET /admin/extras — liste du catalogue central.
     */
    public function index(Request $request): Response
    {
        return $this->view->render('admin/catalog/extras', [
            'csrf' => $this->csrf->field(),
            'extras' => $this->extras->all(),
            'user_name' => $this->session->get('user_name'),
            'flash' => $this->session->pullFlash('extras_ok'),
        ]);
    }

    /**
     * POST /admin/extras — création d'un extra mutualisé.
     */
    public function create(Request $request): Response
    {
        $label = $request->string('label');
        if ($label === '') {
            $this->session->flash('extras_ok', 'Le libellé est requis.');

            return Response::redirect('/admin/extras');
        }

        $id = $this->extras->create([
            'code' => null,
            'label' => $label,
            'description' => $request->string('description') ?: null,
            'default_price_cents' => $this->eurosToCents($request->string('default_price')),
            'default_duration_min' => max(0, $request->int('default_duration')),
            'is_active' => 1,
        ]);

        $image = $this->handleImage($request, null);
        if ($image !== null) {
            $this->extras->setImage($id, $image);
        }

        $this->session->flash('extras_ok', 'Extra créé.');

        return Response::redirect('/admin/extras');
    }

    /**
     * POST /admin/extras/{id} — mise à jour d'un extra.
     */
    public function update(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $current = $this->extras->find($id);
        $this->extras->update($id, [
            'label' => $request->string('label'),
            'description' => $request->string('description') ?: null,
            'default_price_cents' => $this->eurosToCents($request->string('default_price')),
            'default_duration_min' => max(0, $request->int('default_duration')),
            'is_active' => $request->bool('is_active') ? 1 : 0,
        ]);

        $this->extras->setImage($id, $this->handleImage($request, $current['image_path'] ?? null));

        $this->session->flash('extras_ok', 'Extra enregistré.');

        return Response::redirect('/admin/extras');
    }

    /**
     * POST /admin/extras/{id}/supprimer — suppression ou désactivation.
     */
    public function delete(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $action = $this->extras->deleteOrDeactivate($id);
        $this->session->flash(
            'extras_ok',
            $action === 'deleted' ? 'Extra supprimé.' : 'Extra utilisé sur des commandes : désactivé plutôt que supprimé.',
        );

        return Response::redirect('/admin/extras');
    }

    /**
     * POST /admin/catalogue/service/{id}/extras — rattache un extra au service.
     */
    public function attach(Request $request): Response
    {
        $serviceId = (int) $request->attribute('id');
        $extraId = $request->int('extra_id');

        if ($extraId > 0) {
            $this->extras->attach(
                $serviceId,
                $extraId,
                $request->string('price') !== '' ? $this->eurosToCents($request->string('price')) : null,
                $request->has('duration') && $request->string('duration') !== '' ? max(0, $request->int('duration')) : null,
                $request->string('selection_type', 'checkbox') === 'radio' ? 'radio' : 'checkbox',
                $request->string('exclusive_group') ?: null,
            );
            $this->session->flash('catalog_ok', 'Extra rattaché.');
        }

        return Response::redirect("/admin/catalogue/service/{$serviceId}");
    }

    /**
     * POST /admin/catalogue/service/{id}/extras/{extraId}/detacher — détache.
     */
    public function detach(Request $request): Response
    {
        $serviceId = (int) $request->attribute('id');
        $extraId = (int) $request->attribute('extraId');
        $this->extras->detach($serviceId, $extraId);
        $this->session->flash('catalog_ok', 'Extra détaché.');

        return Response::redirect("/admin/catalogue/service/{$serviceId}");
    }

    private function eurosToCents(string $euros): int
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($euros));
        if ($normalized === '' || !is_numeric($normalized)) {
            return 0;
        }

        return (int) round(((float) $normalized) * 100);
    }
}
