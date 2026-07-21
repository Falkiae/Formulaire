<?php

declare(strict_types=1);

namespace Keepnew\Http\Api;

use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Form\FormRepository;

/**
 * API publique du formulaire : renvoie la version publiée pour que le widget
 * rende les questions d'intake dynamiquement (plus de champs codés en dur).
 */
final class FormApiController
{
    public function __construct(private readonly FormRepository $forms)
    {
    }

    /**
     * GET /api/form — formulaire publié (champs + options + conditions).
     */
    public function published(Request $request): Response
    {
        $form = $this->forms->publishedForm();
        if ($form === null) {
            return Response::json(['fields' => [], 'conditions' => []]);
        }

        return Response::json($form);
    }
}
