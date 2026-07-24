<?php

declare(strict_types=1);

namespace Keepnew\Http\Api;

use Keepnew\Booking\CartService;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Form\FormRepository;

/**
 * API publique du formulaire : renvoie la version publiée pour que le widget
 * rende les questions d'intake dynamiquement (plus de champs codés en dur).
 */
final class FormApiController
{
    public function __construct(
        private readonly FormRepository $forms,
        private readonly CartService $cart,
    ) {
    }

    /**
     * GET /api/form?token=... — formulaire publié (champs + options + conditions),
     * filtré aux prestations du panier si un token est fourni (sinon liste
     * globale non filtrée, comportement historique).
     */
    public function published(Request $request): Response
    {
        $token = $request->string('token');
        $form = $token !== '' ? $this->publishedFormForToken($token) : $this->forms->publishedForm();
        if ($form === null) {
            return Response::json(['fields' => [], 'conditions' => []]);
        }

        return Response::json($form);
    }

    private function publishedFormForToken(string $token): ?array
    {
        try {
            $serviceIds = array_values(array_unique(array_map(
                static fn (array $line): int => (int) $line['service_id'],
                $this->cart->lines($token),
            )));
        } catch (\Throwable) {
            // Token inconnu/expiré : repli sur la liste globale non filtrée.
            return $this->forms->publishedForm();
        }

        return $serviceIds === [] ? $this->forms->publishedForm() : $this->forms->publishedFormForServices($serviceIds);
    }
}
