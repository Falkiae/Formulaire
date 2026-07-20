<?php

declare(strict_types=1);

namespace Keepnew\Tests\Booking;

use Keepnew\Booking\BookingService;
use Keepnew\Booking\CartService;
use Keepnew\Booking\HoldService;
use Keepnew\Catalog\CartPricingService;
use Keepnew\Catalog\CatalogRepository;
use Keepnew\Catalog\LineResolver;
use Keepnew\Core\Database;
use Keepnew\Core\Exception\HttpException;
use Keepnew\Pricing\CartPricer;
use Keepnew\Pricing\PriceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Test d'intégration du flux de commande (panier → réservation → jobs).
 *
 * Nécessite une base chargée (schema + seed). Fournir le DSN via la variable
 * d'environnement KN_TEST_DSN (+ KN_TEST_USER / KN_TEST_PASSWORD). Le test est
 * ignoré proprement si la base n'est pas disponible — le flux complet est par
 * ailleurs validé en conditions réelles via le script de fumée HTTP.
 */
final class BookingFlowTest extends TestCase
{
    private ?Database $db = null;
    private BookingService $bookings;
    private CartService $cart;

    protected function setUp(): void
    {
        $dsn = getenv('KN_TEST_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('KN_TEST_DSN non défini : test d\'intégration ignoré.');
        }

        try {
            $pdo = new \PDO($dsn, getenv('KN_TEST_USER') ?: 'root', getenv('KN_TEST_PASSWORD') ?: '', [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        } catch (\PDOException $e) {
            self::markTestSkipped('Base indisponible : ' . $e->getMessage());
        }

        $this->db = new Database($pdo);
        $catalog = new CatalogRepository($this->db);
        $resolver = new LineResolver($catalog);
        $calc = new PriceCalculator();
        $pricing = new CartPricingService($this->db, $resolver, new CartPricer($calc));
        $this->cart = new CartService($this->db, $catalog, $resolver, $calc, $pricing);
        $this->bookings = new BookingService($this->db, $this->cart, $catalog, $pricing, new HoldService($this->db));
    }

    public function testCartToBookingSplitsIntoJobs(): void
    {
        $token = $this->cart->create();
        $this->cart->addItem($token, 4, 'onsite', 21, [], 1); // canapé 3 places
        $this->cart->addItem($token, 5, 'workshop', 27, [], 1); // matelas 160 atelier

        $snapshot = $this->cart->snapshot($token);
        self::assertSame(2, $snapshot['item_count']);

        $start = (new \DateTimeImmutable('+3 days 08:00', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $result = $this->bookings->createFromCart(
            $token,
            ['email' => 'test-' . uniqid() . '@keepnew.be', 'first_name' => 'Test'],
            ['street' => 'Rue Test', 'postal_code' => '4000', 'city' => 'Liège', 'lat' => 50.64, 'lng' => 5.57],
            [
                'onsite' => ['start_utc' => $start, 'technician_id' => 4],
                'workshop' => ['start_utc' => $start, 'technician_id' => 3, 'bay_id' => 1],
            ],
            ['water_access' => 'yes'],
        );

        self::assertStringStartsWith('KN-', $result['reference']);

        // Une commande mixte domicile+atelier doit générer DEUX jobs liés.
        $jobs = $this->db->select('SELECT mode, status FROM jobs WHERE booking_id = :b', ['b' => $result['booking_id']]);
        self::assertCount(2, $jobs);
        $modes = array_column($jobs, 'mode');
        self::assertContains('onsite', $modes);
        self::assertContains('workshop', $modes);
    }

    public function testDoubleBookingIsRejected(): void
    {
        $start = (new \DateTimeImmutable('+4 days 09:00', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $customer = ['email' => 'dbl-' . uniqid() . '@keepnew.be'];
        $address = ['street' => 'A', 'postal_code' => '4000', 'city' => 'Liège', 'lat' => 50.64, 'lng' => 5.57];

        $t1 = $this->cart->create();
        $this->cart->addItem($t1, 4, 'onsite', 21, [], 1);
        $this->bookings->createFromCart($t1, $customer, $address, ['onsite' => ['start_utc' => $start, 'technician_id' => 5]]);

        $t2 = $this->cart->create();
        $this->cart->addItem($t2, 4, 'onsite', 21, [], 1);

        $this->expectException(HttpException::class);
        $this->bookings->createFromCart($t2, $customer, $address, ['onsite' => ['start_utc' => $start, 'technician_id' => 5]]);
    }
}
