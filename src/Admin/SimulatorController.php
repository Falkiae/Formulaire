<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Catalog\CartPricingService;
use Keepnew\Catalog\CatalogRepository;
use Keepnew\Catalog\SimulatorService;
use Keepnew\Core\Csrf;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Session;
use Keepnew\Core\View;

/**
 * Simulateur de prix du back-office.
 *
 * - GET /admin/simulateur : page avec la liste des prestations (config live).
 * - GET /admin/simulateur/service/{id} : JSON de configuration d'une prestation
 *   (modes, variantes, extras) pour alimenter le formulaire dynamique.
 * - POST /admin/simulateur/calcul : JSON → devis détaillé (HTVA/TVA/TVAC + détail).
 *
 * Le calcul en direct se fait par appel JSON depuis la page (Alpine.js), sans
 * rechargement, exactement comme le prix live du tunnel public.
 */
final class SimulatorController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly CatalogRepository $catalog,
        private readonly SimulatorService $simulator,
        private readonly CartPricingService $cartPricing,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->view->render('admin/simulator', [
            'csrf_token' => $this->csrf->token(),
            'services' => $this->catalog->allServices(),
            'user_name' => $this->session->get('user_name'),
        ]);
    }

    /**
     * Renvoie la configuration d'une prestation (modes/variantes/extras) en JSON.
     */
    public function serviceConfig(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $service = $this->catalog->findService($id);
        if ($service === null) {
            return Response::json(['error' => 'Prestation inconnue.'], 404);
        }

        return Response::json([
            'id' => (int) $service['id'],
            'name' => $service['name'],
            'variant_type' => $service['variant_type'],
            'modes' => array_map(
                static fn (array $m): array => ['mode' => $m['mode']],
                $this->catalog->serviceModes($id),
            ),
            'variants' => array_map(
                static fn (array $v): array => ['id' => (int) $v['id'], 'label' => $v['label']],
                $this->catalog->serviceVariants($id),
            ),
            'extras' => array_map(
                static fn (array $e): array => [
                    'id' => (int) $e['extra_id'],
                    'label' => $e['label'],
                    'selection_type' => $e['selection_type'],
                    'exclusive_group' => $e['exclusive_group'],
                ],
                $this->catalog->serviceExtras($id),
            ),
        ]);
    }

    /**
     * Calcule un devis de ligne à partir d'une configuration JSON.
     */
    public function calculate(Request $request): Response
    {
        try {
            $quote = $this->simulator->quote(
                serviceId: $request->int('service_id'),
                mode: $request->string('mode', 'onsite'),
                variantId: $request->has('variant_id') && $request->int('variant_id') > 0 ? $request->int('variant_id') : null,
                extraIds: array_map('intval', $request->array('extra_ids')),
                quantity: max(1, $request->int('quantity', 1)),
            );

            return Response::json($quote);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Calcule le devis d'un PANIER multi-lignes (remise cumul, coupon, TVA).
     *
     * Corps JSON : { lines: [{service_id, mode, variant_id?, extra_ids?,
     * quantity?, address_key?}], coupon_code?, travel_surcharge_cents? }
     */
    public function cart(Request $request): Response
    {
        $lines = $request->array('lines');
        if ($lines === []) {
            return Response::json(['error' => 'Panier vide.'], 422);
        }

        try {
            $quote = $this->cartPricing->price(
                $lines,
                $request->string('coupon_code') ?: null,
                max(0, $request->int('travel_surcharge_cents')),
            );

            return Response::json($this->cartPricing->format($quote));
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }
}
