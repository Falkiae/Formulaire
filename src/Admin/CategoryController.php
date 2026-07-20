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
 * CRUD des catégories du catalogue.
 */
final class CategoryController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly CatalogRepository $catalog,
    ) {
    }

    /**
     * POST /admin/catalogue/categorie — création.
     */
    public function create(Request $request): Response
    {
        $name = $request->string('name');
        if ($name === '') {
            $this->session->flash('catalog_ok', 'Le nom de la catégorie est requis.');

            return Response::redirect('/admin/catalogue');
        }

        $this->catalog->createCategory([
            'name' => $name,
            'parent_id' => $request->int('parent_id') > 0 ? $request->int('parent_id') : null,
            'description' => $request->string('description') ?: null,
            'icon' => $request->string('icon') ?: null,
            'is_visible' => $request->bool('is_visible', true) ? 1 : 0,
        ]);

        $this->session->flash('catalog_ok', 'Catégorie créée.');

        return Response::redirect('/admin/catalogue');
    }

    /**
     * GET /admin/catalogue/categorie/{id} — formulaire d'édition.
     */
    public function edit(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $category = $this->catalog->findCategory($id);
        if ($category === null) {
            throw new NotFoundException('Catégorie introuvable.');
        }

        return $this->view->render('admin/catalog/category', [
            'csrf' => $this->csrf->field(),
            'category' => $category,
            'categories' => $this->catalog->allCategories(),
            'deletable' => $this->catalog->categoryDeletable($id),
            'user_name' => $this->session->get('user_name'),
        ]);
    }

    /**
     * POST /admin/catalogue/categorie/{id} — mise à jour.
     */
    public function update(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        if ($this->catalog->findCategory($id) === null) {
            throw new NotFoundException('Catégorie introuvable.');
        }

        // Empêche une catégorie d'être son propre parent.
        $parentId = $request->int('parent_id') > 0 ? $request->int('parent_id') : null;
        if ($parentId === $id) {
            $parentId = null;
        }

        $this->catalog->updateCategory($id, [
            'name' => $request->string('name'),
            'parent_id' => $parentId,
            'description' => $request->string('description') ?: null,
            'icon' => $request->string('icon') ?: null,
            'is_visible' => $request->bool('is_visible') ? 1 : 0,
        ]);

        $this->session->flash('catalog_ok', 'Catégorie enregistrée.');

        return Response::redirect('/admin/catalogue');
    }

    /**
     * POST /admin/catalogue/categorie/{id}/supprimer — suppression si possible.
     */
    public function delete(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        if ($this->catalog->categoryDeletable($id)) {
            $this->catalog->deleteCategory($id);
            $this->session->flash('catalog_ok', 'Catégorie supprimée.');
        } else {
            $this->session->flash('catalog_ok', 'Catégorie non supprimable (contient des sous-catégories ou des prestations). Masquez-la plutôt.');
        }

        return Response::redirect('/admin/catalogue');
    }

    /**
     * POST /admin/catalogue/categories/ordre — réordonnancement (drag & drop).
     */
    public function reorder(Request $request): Response
    {
        $ids = array_map('intval', $request->array('order'));
        $this->catalog->reorder('categories', $ids);

        return Response::json(['status' => 'ok']);
    }
}
