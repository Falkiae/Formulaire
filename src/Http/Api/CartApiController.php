<?php

declare(strict_types=1);

namespace Keepnew\Http\Api;

use Keepnew\Booking\CartService;
use Keepnew\Catalog\CartPricingService;
use Keepnew\Core\Exception\HttpException;
use Keepnew\Core\Request;
use Keepnew\Core\Response;

/**
 * API panier — création, ajout/retrait de prestations, coupon, instantané.
 */
final class CartApiController
{
    public function __construct(
        private readonly CartService $cart,
        private readonly CartPricingService $pricing,
    ) {
    }

    /**
     * POST /api/quote — prix live d'une configuration, SANS panier (étape 3 du
     * tunnel : le prix s'affiche dès la configuration).
     *
     * Corps : { lines:[{service_id, mode, variant_id?, extra_ids?, quantity?}] }
     */
    public function quote(Request $request): Response
    {
        $lines = $request->array('lines');
        if ($lines === []) {
            return Response::json(['error' => 'Aucune configuration.'], 422);
        }
        foreach ($lines as &$line) {
            $line['address_key'] = $line['mode'] ?? 'onsite';
        }
        unset($line);

        try {
            return Response::json($this->pricing->format($this->pricing->price($lines)));
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/cart — crée un panier et renvoie son token.
     */
    public function create(Request $request): Response
    {
        return Response::json(['token' => $this->cart->create()], 201);
    }

    /**
     * GET /api/cart/{token} — instantané (lignes + devis recalculé).
     */
    public function show(Request $request): Response
    {
        return Response::json($this->cart->snapshot((string) $request->attribute('token')));
    }

    /**
     * POST /api/cart/{token}/items — ajoute une prestation.
     */
    public function addItem(Request $request): Response
    {
        $token = (string) $request->attribute('token');
        $serviceId = $request->int('service_id');
        $mode = $request->string('mode', 'onsite');
        if ($serviceId <= 0 || !in_array($mode, ['onsite', 'workshop'], true)) {
            throw new HttpException(422, 'Prestation ou mode invalide.');
        }

        try {
            $this->cart->addItem(
                $token,
                $serviceId,
                $mode,
                $request->int('variant_id') > 0 ? $request->int('variant_id') : null,
                array_map('intval', $request->array('extra_ids')),
                max(1, $request->int('quantity', 1)),
            );
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return Response::json($this->cart->snapshot($token), 201);
    }

    /**
     * PATCH /api/cart/{token}/items/{itemId} — modifie la quantité.
     */
    public function updateItem(Request $request): Response
    {
        $token = (string) $request->attribute('token');
        $this->cart->updateItemQuantity($token, (int) $request->attribute('itemId'), $request->int('quantity', 1));

        return Response::json($this->cart->snapshot($token));
    }

    /**
     * DELETE /api/cart/{token}/items/{itemId} — retire une ligne.
     */
    public function removeItem(Request $request): Response
    {
        $token = (string) $request->attribute('token');
        $this->cart->removeItem($token, (int) $request->attribute('itemId'));

        return Response::json($this->cart->snapshot($token));
    }

    /**
     * POST /api/cart/{token}/coupon — applique (ou retire) un coupon.
     */
    public function setCoupon(Request $request): Response
    {
        $token = (string) $request->attribute('token');
        $this->cart->setCoupon($token, $request->string('code') ?: null);

        return Response::json($this->cart->snapshot($token));
    }
}
