<?php

declare(strict_types=1);

namespace Keepnew\Booking;

use Keepnew\Catalog\CartPricingService;
use Keepnew\Catalog\CatalogRepository;
use Keepnew\Core\Database;
use Keepnew\Core\Exception\HttpException;
use Keepnew\Core\Exception\NotFoundException;
use Keepnew\Form\FormRepository;
use Keepnew\Geo\NominatimGeocoder;
use Keepnew\Pricing\CartQuote;
use Keepnew\Support\Clock;

/**
 * Logique de commande : transforme un panier en réservation, découpe en jobs
 * (domicile / atelier), pose les créneaux sous verrou pessimiste, et gère le
 * cycle de vie (annulation, planification différée d'une prestation sans date).
 *
 * Pas de paiement au lancement : payment_status = not_required.
 */
final class BookingService
{
    public function __construct(
        private readonly Database $db,
        private readonly CartService $cart,
        private readonly CatalogRepository $catalog,
        private readonly CartPricingService $pricing,
        private readonly HoldService $holds,
        private readonly FormRepository $forms,
        private readonly NominatimGeocoder $geocoder,
    ) {
    }

    /**
     * Crée une réservation à partir d'un panier et de créneaux choisis.
     *
     * @param array<string, mixed> $customer  email, first_name, last_name, phone, type, company_name, vat_number
     * @param array<string, mixed> $address   street, number, postal_code, city, lat, lng, access_notes
     * @param array{onsite?:array{technician_id:int,start_utc:string}, workshop?:array{technician_id:int,bay_id:int,start_utc:string}} $slots
     * @param array<string, mixed> $answers   réponses au formulaire (field_key => value)
     * @return array{reference:string, manage_token:string, booking_id:int}
     */
    public function createFromCart(string $token, array $customer, array $address, array $slots, array $answers = []): array
    {
        $cart = $this->cart->getOrFail($token);
        $lines = $this->cart->lines($token);
        if ($lines === []) {
            throw new HttpException(422, 'Le panier est vide.');
        }

        $couponCode = $cart['coupon_id'] !== null
            ? $this->db->scalar('SELECT code FROM coupons WHERE id = :id', ['id' => (int) $cart['coupon_id']])
            : null;

        // Recalcul autoritatif à la soumission (revalidation).
        $quote = $this->pricing->price($lines, $couponCode !== null ? (string) $couponCode : null);

        // Durées des jobs par mode (pour scheduled_end).
        $jobDurations = $this->jobDurations($lines);

        // Géocodage de l'adresse (pour la carte admin) — hors transaction :
        // appel réseau, ne doit jamais retenir un verrou DB. Jamais bloquant
        // (repli sur "pas de coordonnées", la commande se crée quand même).
        if (($address['street'] ?? '') !== '' && !isset($address['lat'], $address['lng'])) {
            $coords = $this->geocoder->geocode(
                (string) ($address['street'] ?? ''),
                (string) ($address['number'] ?? ''),
                (string) ($address['postal_code'] ?? ''),
                (string) ($address['city'] ?? ''),
            );
            if ($coords !== null) {
                $address['lat'] = $coords['lat'];
                $address['lng'] = $coords['lng'];
            }
        }

        // Correspondance field_key => field_id du formulaire tel qu'affiché pour
        // ce panier (traçabilité de booking_answers.field_id, colonne jusqu'ici
        // jamais renseignée).
        $serviceIds = array_values(array_unique(array_map(static fn (array $l): int => (int) $l['service_id'], $lines)));
        $form = $this->forms->publishedFormForServices($serviceIds);
        $fieldIdByKey = [];
        foreach ($form['fields'] ?? [] as $f) {
            $fieldIdByKey[$f['field_key']] = $f['id'];
        }

        return $this->db->transaction(function (Database $db) use ($cart, $lines, $customer, $address, $slots, $answers, $quote, $jobDurations, $fieldIdByKey): array {
            // 1. Verrou : vérifier que les créneaux choisis sont toujours libres.
            foreach (['onsite', 'workshop'] as $mode) {
                if (!isset($slots[$mode], $jobDurations[$mode])) {
                    continue;
                }
                $slot = $slots[$mode];
                $start = $slot['start_utc'];
                $end = (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))
                    ->modify('+' . $jobDurations[$mode]['active'] . ' minutes')->format('Y-m-d H:i:s');

                if (!$this->holds->technicianFree($db, (int) $slot['technician_id'], $start, $end)) {
                    throw new HttpException(409, 'Ce créneau vient d\'être réservé. Choisissez-en un autre.');
                }
                if ($mode === 'workshop' && isset($slot['bay_id'])) {
                    $bayEnd = (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))
                        ->modify('+' . $jobDurations['workshop']['occupancy'] . ' minutes')->format('Y-m-d H:i:s');
                    if (!$this->holds->bayFree($db, (int) $slot['bay_id'], $start, $bayEnd)) {
                        throw new HttpException(409, 'Ce créneau atelier vient d\'être réservé.');
                    }
                }
            }

            // 2. Client + adresse.
            $customerId = $this->findOrCreateCustomer($db, $customer);
            $addressId = null;
            if (isset($slots['onsite']) || ($address['street'] ?? '') !== '') {
                $addressId = $this->createAddress($db, $customerId, $address);
            }

            // 3. Réservation.
            $bookingId = $this->insertBooking($db, $customerId, $addressId, (int) $cart['id'], $quote, 'confirmed');

            // 4. Lignes + jobs.
            $this->insertItemsAndJobs($db, $bookingId, $addressId, $lines, $slots, $jobDurations);

            // 5. Réponses au formulaire + historique.
            $this->insertAnswers($db, $bookingId, $answers, $fieldIdByKey);
            $db->insert('booking_status_history', [
                'booking_id' => $bookingId, 'new_status' => 'confirmed', 'note' => 'Réservation créée depuis le tunnel',
            ]);

            // 6. Conversion du panier + libération des holds.
            $db->run("UPDATE carts SET status = 'converted' WHERE id = :id", ['id' => (int) $cart['id']]);
            $this->holds->releaseForCart($db, (int) $cart['id']);

            $reference = $this->reference($db, $bookingId);
            $manageToken = (string) $db->scalar('SELECT manage_token FROM bookings WHERE id = :id', ['id' => $bookingId]);

            return ['reference' => $reference, 'manage_token' => $manageToken, 'booking_id' => $bookingId];
        });
    }

    /**
     * Crée une prestation SANS DATE (préconfigurée par un opérateur) : jobs en
     * statut « unscheduled ». Le client choisit ensuite son créneau via le lien
     * de gestion (schedule()).
     *
     * @param array<string, mixed> $customer
     * @param list<array<string, mixed>> $lines  service_id, mode, variant_id?, extra_ids?, quantity?
     * @return array{reference:string, manage_token:string, booking_id:int}
     */
    public function createDateless(array $customer, array $lines, array $address = []): array
    {
        if ($lines === []) {
            throw new HttpException(422, 'Aucune prestation fournie.');
        }
        // Normalise l'address_key par mode.
        foreach ($lines as &$line) {
            $line['address_key'] = $line['mode'] ?? 'onsite';
        }
        unset($line);

        $quote = $this->pricing->price($lines);
        $jobDurations = $this->jobDurations($lines);

        return $this->db->transaction(function (Database $db) use ($customer, $lines, $address, $quote, $jobDurations): array {
            $customerId = $this->findOrCreateCustomer($db, $customer);
            $addressId = ($address['street'] ?? '') !== '' ? $this->createAddress($db, $customerId, $address) : null;

            $bookingId = $this->insertBooking($db, $customerId, $addressId, null, $quote, 'pending');
            $this->insertItemsAndJobs($db, $bookingId, $addressId, $lines, [], $jobDurations); // jobs unscheduled
            $db->insert('booking_status_history', [
                'booking_id' => $bookingId, 'new_status' => 'pending', 'note' => 'Prestation préconfigurée sans date',
            ]);

            $reference = $this->reference($db, $bookingId);
            $manageToken = (string) $db->scalar('SELECT manage_token FROM bookings WHERE id = :id', ['id' => $bookingId]);

            return ['reference' => $reference, 'manage_token' => $manageToken, 'booking_id' => $bookingId];
        });
    }

    /**
     * Planifie les jobs d'une réservation via son token de gestion (le client
     * choisit ses créneaux sur une prestation sans date).
     *
     * @param array{onsite?:array{technician_id:int,start_utc:string}, workshop?:array{technician_id:int,bay_id:int,start_utc:string}} $slots
     */
    public function schedule(string $manageToken, array $slots): void
    {
        $booking = $this->findByManageToken($manageToken);

        $this->db->transaction(function (Database $db) use ($booking, $slots): void {
            $jobs = $db->select('SELECT * FROM jobs WHERE booking_id = :b', ['b' => (int) $booking['id']]);
            foreach ($jobs as $job) {
                $mode = $job['mode'];
                if (!isset($slots[$mode])) {
                    continue;
                }
                $slot = $slots[$mode];
                $active = (int) $job['active_duration_min'];
                $occupancy = max((int) $job['occupancy_duration_min'], $active);
                $start = $slot['start_utc'];
                $endActive = (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))->modify("+{$active} minutes")->format('Y-m-d H:i:s');

                if (!$this->holds->technicianFree($db, (int) $slot['technician_id'], $start, $endActive)) {
                    throw new HttpException(409, 'Ce créneau vient d\'être réservé.');
                }

                $scheduledEnd = $mode === 'workshop'
                    ? (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))->modify("+{$occupancy} minutes")->format('Y-m-d H:i:s')
                    : $endActive;

                $db->run(
                    "UPDATE jobs SET status = 'scheduled', technician_id = :t, bay_id = :bay,
                            scheduled_start = :s, scheduled_end = :e,
                            arrival_from = :af, arrival_to = :at
                     WHERE id = :id",
                    [
                        't' => (int) $slot['technician_id'],
                        'bay' => $slot['bay_id'] ?? null,
                        's' => $start,
                        'e' => $scheduledEnd,
                        'af' => $start,
                        'at' => (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))->modify('+120 minutes')->format('Y-m-d H:i:s'),
                        'id' => (int) $job['id'],
                    ],
                );
            }

            $db->run("UPDATE bookings SET status = 'confirmed' WHERE id = :id", ['id' => (int) $booking['id']]);
            $db->insert('booking_status_history', [
                'booking_id' => (int) $booking['id'], 'old_status' => $booking['status'],
                'new_status' => 'confirmed', 'note' => 'Créneau choisi par le client',
            ]);
        });
    }

    /**
     * Annulation via token de gestion, selon la politique de préavis.
     */
    public function cancel(string $manageToken): void
    {
        $booking = $this->findByManageToken($manageToken);
        if (in_array($booking['status'], ['cancelled', 'completed'], true)) {
            throw new HttpException(409, 'Cette réservation ne peut plus être annulée.');
        }

        $this->db->transaction(function (Database $db) use ($booking): void {
            $db->run("UPDATE bookings SET status = 'cancelled' WHERE id = :id", ['id' => (int) $booking['id']]);
            $db->run("UPDATE jobs SET status = 'cancelled' WHERE booking_id = :id AND status NOT IN ('completed')", ['id' => (int) $booking['id']]);
            $db->insert('booking_status_history', [
                'booking_id' => (int) $booking['id'], 'old_status' => $booking['status'],
                'new_status' => 'cancelled', 'note' => 'Annulée par le client',
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function findByManageToken(string $manageToken): array
    {
        $booking = $this->db->selectOne('SELECT * FROM bookings WHERE manage_token = :t', ['t' => $manageToken]);
        if ($booking === null) {
            throw new NotFoundException('Réservation introuvable.');
        }

        return $booking;
    }

    /**
     * Vue complète d'une réservation (pour le lien de gestion client).
     *
     * @return array<string, mixed>
     */
    public function view(string $manageToken): array
    {
        $booking = $this->findByManageToken($manageToken);
        $items = $this->db->select('SELECT label_snapshot, mode, quantity, line_total_cents FROM booking_items WHERE booking_id = :b', ['b' => (int) $booking['id']]);
        $jobs = $this->db->select('SELECT mode, status, scheduled_start, arrival_from, arrival_to FROM jobs WHERE booking_id = :b', ['b' => (int) $booking['id']]);

        return [
            'reference' => $booking['reference'],
            'status' => $booking['status'],
            'payment_status' => $booking['payment_status'],
            'total_cents' => (int) $booking['total_cents'],
            'items' => $items,
            'jobs' => array_map(static fn (array $j): array => [
                'mode' => $j['mode'],
                'status' => $j['status'],
                'scheduled_local' => $j['scheduled_start'] !== null
                    ? Clock::format(new \DateTimeImmutable((string) $j['scheduled_start'], new \DateTimeZone('UTC')), 'd/m/Y H:i')
                    : null,
            ], $jobs),
        ];
    }

    // --- Helpers internes ------------------------------------------------------

    /**
     * Durées active/occupation par mode, agrégées depuis les lignes.
     *
     * @param list<array<string, mixed>> $lines
     * @return array<string, array{active:int, occupancy:int, skills:list<int>}>
     */
    private function jobDurations(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $mode = (string) $line['mode'];
            $serviceId = (int) $line['service_id'];
            $variantId = $line['variant_id'] ?? null;
            $modeRow = $this->catalog->serviceMode($serviceId, $mode);
            $service = $this->catalog->findService($serviceId);
            $active = $modeRow !== null && $modeRow['active_duration_min'] !== null
                ? (int) $modeRow['active_duration_min']
                : (int) ($service['base_duration_min'] ?? 60);
            if ($variantId !== null) {
                $variant = $this->catalog->findVariant((int) $variantId);
                $active += $variant !== null ? (int) $variant['duration_delta_min'] : 0;
            }
            $active *= max(1, (int) ($line['quantity'] ?? 1));
            $occupancy = $modeRow !== null && $modeRow['occupancy_duration_min'] !== null
                ? (int) $modeRow['occupancy_duration_min']
                : $active;

            $out[$mode] ??= ['active' => 0, 'occupancy' => 0, 'skills' => []];
            $out[$mode]['active'] += $active;
            $out[$mode]['occupancy'] += max($occupancy, $active);
            foreach ($this->catalog->serviceSkillIds($serviceId) as $sk) {
                if (!in_array($sk, $out[$mode]['skills'], true)) {
                    $out[$mode]['skills'][] = $sk;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $customer
     */
    private function findOrCreateCustomer(Database $db, array $customer): int
    {
        $email = (string) ($customer['email'] ?? '');
        $existing = $email !== '' ? $db->selectOne('SELECT id FROM customers WHERE email = :e', ['e' => $email]) : null;
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return $db->insert('customers', [
            'type' => ($customer['type'] ?? 'b2c') === 'b2b' ? 'b2b' : 'b2c',
            'first_name' => $customer['first_name'] ?? null,
            'last_name' => $customer['last_name'] ?? null,
            'company_name' => $customer['company_name'] ?? null,
            'vat_number' => $customer['vat_number'] ?? null,
            'email' => $email,
            'phone' => $customer['phone'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $address
     */
    private function createAddress(Database $db, int $customerId, array $address): int
    {
        return $db->insert('addresses', [
            'customer_id' => $customerId,
            'street' => $address['street'] ?? '',
            'number' => $address['number'] ?? null,
            'postal_code' => $address['postal_code'] ?? '',
            'city' => $address['city'] ?? '',
            'lat' => $address['lat'] ?? null,
            'lng' => $address['lng'] ?? null,
            'access_notes' => $address['access_notes'] ?? null,
        ]);
    }

    private function insertBooking(Database $db, int $customerId, ?int $addressId, ?int $cartId, CartQuote $quote, string $status): int
    {
        $terms = $db->scalar('SELECT id FROM terms_versions WHERE is_current = 1 LIMIT 1');

        return $db->insert('bookings', [
            'reference' => 'TMP-' . bin2hex(random_bytes(6)),
            'customer_id' => $customerId,
            'address_id' => $addressId,
            'source_cart_id' => $cartId,
            'booking_mode' => 'instant',
            'status' => $status,
            'payment_status' => 'not_required',
            'subtotal_cents' => $quote->linesSubtotalCents,
            'discount_cents' => $quote->totalDiscountCents(),
            'travel_surcharge_cents' => $quote->travelSurchargeCents,
            'vat_cents' => $quote->vatCents,
            'total_cents' => $quote->totalTvacCents,
            'vat_rate_bp' => $quote->vatRateBp,
            'terms_version_id' => $terms !== null ? (int) $terms : null,
            'total_duration_min' => $quote->totalActiveDurationMin,
            'manage_token' => bin2hex(random_bytes(24)),
        ]);
    }

    /**
     * Crée les lignes de commande et les jobs (un par mode présent), et relie
     * chaque ligne à son job.
     *
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $slots
     * @param array<string, array{active:int, occupancy:int, skills:list<int>}> $jobDurations
     */
    private function insertItemsAndJobs(Database $db, int $bookingId, ?int $addressId, array $lines, array $slots, array $jobDurations): void
    {
        // Crée un job par mode.
        $jobIdByMode = [];
        foreach ($jobDurations as $mode => $dur) {
            $scheduled = $slots[$mode] ?? null;
            $start = $scheduled['start_utc'] ?? null;
            $active = $dur['active'];
            $occupancy = $mode === 'workshop' ? max($dur['occupancy'], $active) : $active;

            $scheduledStart = $start;
            $scheduledEnd = null;
            $arrivalFrom = null;
            $arrivalTo = null;
            if ($start !== null) {
                $startDt = new \DateTimeImmutable($start, new \DateTimeZone('UTC'));
                $scheduledEnd = $startDt->modify('+' . ($mode === 'workshop' ? $occupancy : $active) . ' minutes')->format('Y-m-d H:i:s');
                $arrivalFrom = $start;
                $arrivalTo = $startDt->modify('+120 minutes')->format('Y-m-d H:i:s');
            }

            $jobIdByMode[$mode] = $db->insert('jobs', [
                'booking_id' => $bookingId,
                'mode' => $mode,
                'location_id' => $mode === 'workshop' ? ($this->defaultWorkshopId($db)) : null,
                'bay_id' => $scheduled['bay_id'] ?? null,
                'technician_id' => $scheduled['technician_id'] ?? null,
                'address_id' => $mode === 'onsite' ? $addressId : null,
                'scheduled_start' => $scheduledStart,
                'scheduled_end' => $scheduledEnd,
                'arrival_from' => $arrivalFrom,
                'arrival_to' => $arrivalTo,
                'active_duration_min' => $active,
                'occupancy_duration_min' => $occupancy,
                'status' => $start !== null ? 'scheduled' : 'unscheduled',
            ]);
        }

        // Crée les lignes, reliées à leur job de mode.
        foreach ($lines as $line) {
            $mode = (string) $line['mode'];
            $serviceId = (int) $line['service_id'];
            $variantId = $line['variant_id'] ?? null;
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $service = $this->catalog->findService($serviceId);
            $modeRow = $this->catalog->serviceMode($serviceId, $mode);
            $unitPrice = $modeRow !== null && $modeRow['price_cents'] !== null ? (int) $modeRow['price_cents'] : (int) ($service['base_price_cents'] ?? 0);
            $variantLabel = null;
            if ($variantId !== null) {
                $variant = $this->catalog->findVariant((int) $variantId);
                if ($variant !== null) {
                    $unitPrice += (int) $variant['price_delta_cents'];
                    $variantLabel = $variant['label'];
                }
            }
            $extraTotal = 0;
            $available = [];
            foreach ($this->catalog->serviceExtras($serviceId) as $se) {
                $available[(int) $se['extra_id']] = $se;
            }
            $itemId = $db->insert('booking_items', [
                'booking_id' => $bookingId,
                'job_id' => $jobIdByMode[$mode] ?? null,
                'service_id' => $serviceId,
                'variant_id' => $variantId,
                'mode' => $mode,
                'quantity' => $quantity,
                'unit_price_cents' => $unitPrice,
                'unit_duration_min' => 0,
                'line_total_cents' => 0, // renseigné après extras
                'label_snapshot' => ($service['name'] ?? 'Prestation') . ($variantLabel !== null ? ' — ' . $variantLabel : ''),
            ]);
            foreach ((array) ($line['extra_ids'] ?? []) as $extraId) {
                $extraId = (int) $extraId;
                if (isset($available[$extraId])) {
                    $se = $available[$extraId];
                    $extraTotal += (int) $se['eff_price_cents'];
                    $db->insert('booking_item_extras', [
                        'booking_item_id' => $itemId,
                        'extra_id' => $extraId,
                        'unit_price_cents' => (int) $se['eff_price_cents'],
                        'label_snapshot' => $se['label'],
                    ]);
                }
            }
            $db->run('UPDATE booking_items SET line_total_cents = :t WHERE id = :id', [
                't' => ($unitPrice + $extraTotal) * $quantity,
                'id' => $itemId,
            ]);
        }
    }

    private function defaultWorkshopId(Database $db): ?int
    {
        $id = $db->scalar('SELECT id FROM locations WHERE is_active = 1 ORDER BY sort_order LIMIT 1');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @param array<string, mixed> $answers
     */
    /**
     * @param array<string, int> $fieldIdByKey
     */
    private function insertAnswers(Database $db, int $bookingId, array $answers, array $fieldIdByKey = []): void
    {
        foreach ($answers as $key => $value) {
            $db->insert('booking_answers', [
                'booking_id' => $bookingId,
                'field_id' => $fieldIdByKey[(string) $key] ?? null,
                'field_key' => (string) $key,
                'value_text' => is_scalar($value) ? (string) $value : null,
                'value_json' => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : null,
            ]);
        }
    }

    private function reference(Database $db, int $bookingId): string
    {
        $year = (int) Clock::nowUtc()->format('Y');
        $reference = sprintf('KN-%d-%06d', $year, $bookingId);
        $db->run('UPDATE bookings SET reference = :r WHERE id = :id', ['r' => $reference, 'id' => $bookingId]);

        return $reference;
    }
}
