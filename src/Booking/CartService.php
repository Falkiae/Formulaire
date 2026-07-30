<?php

declare(strict_types=1);

namespace Keepnew\Booking;

use Keepnew\Catalog\CartPricingService;
use Keepnew\Catalog\CatalogRepository;
use Keepnew\Catalog\LineResolver;
use Keepnew\Core\Database;
use Keepnew\Core\Exception\HttpException;
use Keepnew\Core\Exception\NotFoundException;
use Keepnew\Pricing\PriceCalculator;
use Keepnew\Support\Clock;

/**
 * Panier persistant côté serveur (table `carts`), à token anonyme.
 *
 * Les prix/durées sont FIGÉS à l'ajout (snapshot), mais le devis affiché est
 * TOUJOURS recalculé par CartPricingService à partir du catalogue (source de
 * vérité). Un écart éventuel est ainsi détectable à la soumission finale.
 */
final class CartService
{
    private const EXPIRY_DAYS = 7;

    public function __construct(
        private readonly Database $db,
        private readonly CatalogRepository $catalog,
        private readonly LineResolver $resolver,
        private readonly PriceCalculator $calculator,
        private readonly CartPricingService $pricing,
    ) {
    }

    /**
     * Crée un panier vide et renvoie son token.
     */
    public function create(): string
    {
        $token = bin2hex(random_bytes(20)); // 40 caractères
        $expires = Clock::nowUtc()->modify('+' . self::EXPIRY_DAYS . ' days')->format('Y-m-d H:i:s');

        $this->db->insert('carts', [
            'token' => $token,
            'status' => 'active',
            'expires_at' => $expires,
        ]);

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrFail(string $token): array
    {
        $cart = $this->db->selectOne(
            "SELECT * FROM carts WHERE token = :t AND status = 'active'",
            ['t' => $token],
        );
        if ($cart === null) {
            throw new NotFoundException('Panier introuvable ou expiré.');
        }

        return $cart;
    }

    /**
     * Ajoute une prestation au panier (prix/durée figés au moment de l'ajout).
     *
     * @param list<int> $extraIds
     */
    public function addItem(string $token, int $serviceId, string $mode, ?int $variantId, array $extraIds, int $quantity): int
    {
        $cart = $this->getOrFail($token);
        $service = $this->catalog->findService($serviceId);
        if ($service === null) {
            throw new NotFoundException('Prestation inconnue.');
        }
        $this->assertSameMode((int) $cart['id'], $mode);

        // Résolution + calcul → snapshot de prix/durée et libellé.
        $input = $this->resolver->resolve($serviceId, $mode, $variantId, $extraIds, $quantity);
        $line = $this->calculator->calculateLine($input);

        $variantLabel = null;
        if ($variantId !== null) {
            $variant = $this->catalog->findVariant($variantId);
            $variantLabel = $variant['label'] ?? null;
        }

        return $this->db->transaction(function (Database $db) use ($cart, $serviceId, $mode, $variantId, $extraIds, $quantity, $line, $service, $variantLabel): int {
            $nextOrder = (int) $db->scalar('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM cart_items WHERE cart_id = :c', ['c' => (int) $cart['id']]);

            $itemId = $db->insert('cart_items', [
                'cart_id' => (int) $cart['id'],
                'service_id' => $serviceId,
                'variant_id' => $variantId,
                'mode' => $mode,
                'quantity' => $quantity,
                'unit_price_cents' => $line->unitPriceCents,
                'unit_duration_min' => $line->unitDurationMin,
                'label_snapshot' => $service['name'] . ($variantLabel !== null ? ' — ' . $variantLabel : ''),
                'config_snapshot' => json_encode([
                    'mode' => $mode,
                    'variant' => $variantLabel,
                ], JSON_UNESCAPED_UNICODE),
                'sort_order' => $nextOrder,
            ]);

            // Extras figés.
            $available = [];
            foreach ($this->catalog->serviceExtras($serviceId) as $se) {
                $available[(int) $se['extra_id']] = $se;
            }
            foreach ($extraIds as $extraId) {
                if (isset($available[$extraId])) {
                    $se = $available[$extraId];
                    $db->insert('cart_item_extras', [
                        'cart_item_id' => $itemId,
                        'extra_id' => $extraId,
                        'unit_price_cents' => (int) $se['eff_price_cents'],
                        'unit_duration_min' => (int) $se['eff_duration_min'],
                        'label_snapshot' => $se['label'],
                    ]);
                }
            }

            $this->touch($db, (int) $cart['id']);

            return $itemId;
        });
    }

    /**
     * Un panier ne mélange jamais domicile et atelier.
     *
     * Une commande donne lieu à UN rendez-vous, donc à un seul créneau : mêler
     * les deux modes produirait deux interventions à planifier séparément, ce
     * qui ne correspond à aucune réalité d'exploitation. Le tunnel public fait
     * déjà choisir le mode à la première étape et n'expose ensuite que les
     * prestations compatibles ; cette vérification ferme la porte côté serveur
     * (appel direct à POST /api/cart/{token}/items).
     */
    private function assertSameMode(int $cartId, string $mode): void
    {
        $existing = $this->db->scalar(
            'SELECT mode FROM cart_items WHERE cart_id = :c LIMIT 1',
            ['c' => $cartId],
        );

        if ($existing !== null && (string) $existing !== $mode) {
            throw new HttpException(
                422,
                'Une même commande ne peut pas mélanger une prestation à domicile et une prestation en atelier. '
                . 'Terminez cette commande, puis passez-en une seconde pour l\'autre formule.',
            );
        }
    }

    public function updateItemQuantity(string $token, int $itemId, int $quantity): void
    {
        $cart = $this->getOrFail($token);
        $this->db->run(
            'UPDATE cart_items SET quantity = :q WHERE id = :id AND cart_id = :c',
            ['q' => max(1, $quantity), 'id' => $itemId, 'c' => (int) $cart['id']],
        );
        $this->touch($this->db, (int) $cart['id']);
    }

    public function removeItem(string $token, int $itemId): void
    {
        $cart = $this->getOrFail($token);
        $this->db->run('DELETE FROM cart_items WHERE id = :id AND cart_id = :c', ['id' => $itemId, 'c' => (int) $cart['id']]);
        $this->touch($this->db, (int) $cart['id']);
    }

    /**
     * Applique un coupon (validé au calcul). Stocke le code sur le panier.
     */
    public function setCoupon(string $token, ?string $code): void
    {
        $cart = $this->getOrFail($token);
        $couponId = null;
        if ($code !== null && $code !== '') {
            $coupon = $this->db->selectOne('SELECT id FROM coupons WHERE code = :c AND is_active = 1', ['c' => $code]);
            $couponId = $coupon !== null ? (int) $coupon['id'] : null;
        }
        $this->db->run('UPDATE carts SET coupon_id = :cp WHERE id = :id', ['cp' => $couponId, 'id' => (int) $cart['id']]);
    }

    /**
     * Lignes du panier au format attendu par le moteur de prix / disponibilité.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(string $token): array
    {
        $cart = $this->getOrFail($token);
        $items = $this->db->select('SELECT * FROM cart_items WHERE cart_id = :c ORDER BY sort_order', ['c' => (int) $cart['id']]);
        $lines = [];
        foreach ($items as $item) {
            $extraIds = array_map(
                static fn (array $r): int => (int) $r['extra_id'],
                $this->db->select('SELECT extra_id FROM cart_item_extras WHERE cart_item_id = :i', ['i' => (int) $item['id']]),
            );
            $lines[] = [
                'cart_item_id' => (int) $item['id'],
                'service_id' => (int) $item['service_id'],
                'mode' => $item['mode'],
                'variant_id' => $item['variant_id'] !== null ? (int) $item['variant_id'] : null,
                'extra_ids' => $extraIds,
                'quantity' => (int) $item['quantity'],
                'address_key' => $item['mode'], // regroupement cumul par mode
            ];
        }

        return $lines;
    }

    /**
     * Instantané complet du panier : lignes affichables + devis recalculé.
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $token): array
    {
        $cart = $this->getOrFail($token);
        $lines = $this->lines($token);

        $couponCode = null;
        if ($cart['coupon_id'] !== null) {
            $couponCode = $this->db->scalar('SELECT code FROM coupons WHERE id = :id', ['id' => (int) $cart['coupon_id']]);
        }

        $items = $this->db->select('SELECT * FROM cart_items WHERE cart_id = :c ORDER BY sort_order', ['c' => (int) $cart['id']]);
        $displayItems = [];
        foreach ($items as $item) {
            $extras = $this->db->select('SELECT label_snapshot, unit_price_cents FROM cart_item_extras WHERE cart_item_id = :i', ['i' => (int) $item['id']]);
            $displayItems[] = [
                'id' => (int) $item['id'],
                'label' => $item['label_snapshot'],
                'mode' => $item['mode'],
                'quantity' => (int) $item['quantity'],
                'unit_price_cents' => (int) $item['unit_price_cents'],
                'unit_duration_min' => (int) $item['unit_duration_min'],
                'extras' => array_map(static fn (array $e): array => ['label' => $e['label_snapshot'], 'price_cents' => (int) $e['unit_price_cents']], $extras),
            ];
        }

        $pricing = $lines !== []
            ? $this->pricing->format($this->pricing->price($lines, $couponCode !== null ? (string) $couponCode : null))
            : null;

        return [
            'token' => $token,
            'item_count' => count($displayItems),
            'items' => $displayItems,
            'coupon_code' => $couponCode,
            'pricing' => $pricing,
        ];
    }

    private function touch(Database $db, int $cartId): void
    {
        $db->run('UPDATE carts SET updated_at = UTC_TIMESTAMP() WHERE id = :id', ['id' => $cartId]);
    }
}
