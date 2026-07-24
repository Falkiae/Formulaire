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
use Keepnew\Form\FormRepository;

/**
 * Form builder : gestion des versions, champs, options et conditions du
 * formulaire dynamique. On édite un brouillon puis on le publie.
 */
final class FormBuilderController
{
    private const FIELD_TYPES = ['text', 'textarea', 'number', 'select', 'radio', 'checkbox', 'cards', 'stepper', 'date', 'photo', 'address', 'coupon', 'consent'];

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly FormRepository $forms,
        private readonly CatalogRepository $catalog,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->view->render('admin/form/index', [
            'csrf' => $this->csrf->field(),
            'versions' => $this->forms->versions(),
            'user_name' => $this->session->get('user_name'),
            'flash' => $this->session->pullFlash('form_ok'),
        ]);
    }

    public function createVersion(Request $request): Response
    {
        $id = $this->forms->createVersion($request->string('label') ?: null, $this->session->userId());
        $this->session->flash('form_ok', 'Brouillon créé.');

        return Response::redirect("/admin/formulaire/{$id}");
    }

    public function edit(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $version = $this->forms->findVersion($id);
        if ($version === null) {
            throw new NotFoundException('Version introuvable.');
        }

        // Prestations groupées par catégorie, pour la checklist d'assignation par champ.
        $servicesByCategory = [];
        foreach ($this->catalog->allServices() as $svc) {
            $servicesByCategory[$svc['category_name']][] = ['id' => (int) $svc['id'], 'name' => $svc['name']];
        }

        return $this->view->render('admin/form/edit', [
            'csrf' => $this->csrf->field(),
            'csrf_token' => $this->csrf->token(),
            'version' => $version,
            'fields' => $this->forms->fields($id),
            'conditions' => $this->forms->conditions($id),
            'field_types' => self::FIELD_TYPES,
            'services_by_category' => $servicesByCategory,
            'user_name' => $this->session->get('user_name'),
            'flash' => $this->session->pullFlash('form_ok'),
        ]);
    }

    public function addField(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $type = $request->string('field_type');
        if ($request->string('label') !== '' && in_array($type, self::FIELD_TYPES, true)) {
            $this->forms->addField($id, [
                'field_key' => $request->string('field_key') ?: \Keepnew\Support\Slug::make($request->string('label')),
                'label' => $request->string('label'),
                'help_text' => $request->string('help_text') ?: null,
                'field_type' => $type,
                'step' => max(1, $request->int('step', 5)),
                'is_required' => $request->bool('is_required') ? 1 : 0,
            ]);
            $this->session->flash('form_ok', 'Champ ajouté.');
        }

        return Response::redirect("/admin/formulaire/{$id}");
    }

    public function deleteField(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $this->forms->deleteField((int) $request->attribute('fieldId'));
        $this->session->flash('form_ok', 'Champ supprimé.');

        return Response::redirect("/admin/formulaire/{$id}");
    }

    public function reorderFields(Request $request): Response
    {
        $this->forms->reorderFields(array_map('intval', $request->array('order')));

        return Response::json(['status' => 'ok']);
    }

    /**
     * POST /admin/formulaire/{id}/champ/{fieldId}/services — prestations
     * auxquelles ce champ est assigné (liste vide = toutes les prestations).
     */
    public function updateFieldServices(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $fieldId = (int) $request->attribute('fieldId');
        $this->forms->syncFieldServices($fieldId, array_map('intval', $request->array('service_ids')));
        $this->session->flash('form_ok', 'Prestations assignées mises à jour.');

        return Response::redirect("/admin/formulaire/{$id}");
    }

    public function addOption(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $fieldId = (int) $request->attribute('fieldId');
        if ($request->string('label') !== '') {
            $this->forms->addOption($fieldId, [
                'value' => $request->string('value') ?: \Keepnew\Support\Slug::make($request->string('label')),
                'label' => $request->string('label'),
                'duration_modifier_type' => in_array($request->string('duration_modifier_type'), ['none', 'fixed', 'percent', 'multiplier'], true) ? $request->string('duration_modifier_type') : 'none',
                'duration_modifier_value' => $request->int('duration_modifier_value'),
            ]);
            $this->session->flash('form_ok', 'Option ajoutée.');
        }

        return Response::redirect("/admin/formulaire/{$id}");
    }

    public function deleteOption(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $this->forms->deleteOption((int) $request->attribute('optionId'));

        return Response::redirect("/admin/formulaire/{$id}");
    }

    public function addCondition(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        if ($request->int('source_field_id') > 0 && $request->int('target_field_id') > 0) {
            $this->forms->addCondition($id, [
                'source_field_id' => $request->int('source_field_id'),
                'operator' => $request->string('operator', 'eq'),
                'compare_value' => $request->string('compare_value') ?: null,
                'action' => $request->string('action', 'show'),
                'target_field_id' => $request->int('target_field_id'),
            ]);
            $this->session->flash('form_ok', 'Condition ajoutée.');
        }

        return Response::redirect("/admin/formulaire/{$id}");
    }

    public function deleteCondition(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $this->forms->deleteCondition((int) $request->attribute('conditionId'));

        return Response::redirect("/admin/formulaire/{$id}");
    }

    public function publish(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $this->forms->publish($id);
        $this->session->flash('form_ok', 'Version publiée — c\'est le formulaire en ligne.');

        return Response::redirect("/admin/formulaire/{$id}");
    }
}
