<?php

declare(strict_types=1);

namespace Keepnew\Customer;

use Keepnew\Core\Database;
use Keepnew\Geo\NominatimGeocoder;

/**
 * Accès en lecture et écriture aux clients, à leurs adresses et à leurs notes.
 *
 * Remplace l'accès SQL direct qui vivait dans CustomerController et ajoute
 * l'écriture (création/édition, adresses, notes). Respecte l'anonymisation
 * RGPD : un client anonymisé n'est plus éditable.
 */
final class CustomerRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly NominatimGeocoder $geocoder,
    ) {
    }

    // --- Lecture liste / fiche -------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $q): array
    {
        $where = 'WHERE c.anonymized_at IS NULL';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (c.email LIKE :q OR c.last_name LIKE :q OR c.company_name LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }

        return $this->db->select(
            "SELECT c.id, c.type, c.first_name, c.last_name, c.company_name, c.email, c.phone,
                    COUNT(DISTINCT b.id) AS bookings_count,
                    COALESCE(SUM(CASE WHEN b.status <> 'cancelled' THEN b.total_cents ELSE 0 END), 0) AS ltv_cents,
                    MAX(b.created_at) AS last_booking
             FROM customers c
             LEFT JOIN bookings b ON b.customer_id = c.id
             {$where}
             GROUP BY c.id
             ORDER BY ltv_cents DESC
             LIMIT 200",
            $params,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM customers WHERE id = :id', ['id' => $id]);
    }

    /**
     * Commandes du client, chacune avec ses prestations (une commande peut en
     * donner plusieurs — ex. domicile + atelier — pour permettre un lien
     * direct vers chaque fiche prestation depuis la fiche client.
     *
     * @return list<array<string, mixed>>
     */
    public function bookingsFor(int $id): array
    {
        $rows = $this->db->select(
            'SELECT b.id, b.reference, b.status, b.total_cents, b.created_at,
                    j.id AS job_id, j.mode AS job_mode, j.status AS job_status, j.scheduled_start AS job_start
               FROM bookings b
               LEFT JOIN jobs j ON j.booking_id = b.id
              WHERE b.customer_id = :id
              ORDER BY b.id DESC, j.sequence_no',
            ['id' => $id],
        );

        $bookings = [];
        foreach ($rows as $r) {
            $bid = (int) $r['id'];
            if (!isset($bookings[$bid])) {
                $bookings[$bid] = [
                    'id' => $bid,
                    'reference' => $r['reference'],
                    'status' => $r['status'],
                    'total_cents' => $r['total_cents'],
                    'created_at' => $r['created_at'],
                    'jobs' => [],
                ];
            }
            if ($r['job_id'] !== null) {
                $bookings[$bid]['jobs'][] = [
                    'id' => (int) $r['job_id'],
                    'mode' => $r['job_mode'],
                    'status' => $r['job_status'],
                    'scheduled_start' => $r['job_start'],
                ];
            }
        }

        return array_values($bookings);
    }

    public function ltvFor(int $id): int
    {
        return (int) $this->db->scalar(
            "SELECT COALESCE(SUM(total_cents),0) FROM bookings WHERE customer_id = :id AND status <> 'cancelled'",
            ['id' => $id],
        );
    }

    // --- Écriture client -------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return $this->db->insert('customers', $this->columns($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $cols = $this->columns($data);
        $set = implode(', ', array_map(static fn (string $c): string => "$c = :$c", array_keys($cols)));
        $cols['id'] = $id;
        $this->db->run("UPDATE customers SET $set WHERE id = :id", $cols);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        return [
            'type' => in_array($data['type'] ?? 'b2c', ['b2c', 'b2b'], true) ? $data['type'] : 'b2c',
            'first_name' => ($data['first_name'] ?? '') !== '' ? (string) $data['first_name'] : null,
            'last_name' => ($data['last_name'] ?? '') !== '' ? (string) $data['last_name'] : null,
            'company_name' => ($data['company_name'] ?? '') !== '' ? (string) $data['company_name'] : null,
            'vat_number' => ($data['vat_number'] ?? '') !== '' ? (string) $data['vat_number'] : null,
            'email' => (string) ($data['email'] ?? ''),
            'phone' => ($data['phone'] ?? '') !== '' ? (string) $data['phone'] : null,
        ];
    }

    public function isAnonymized(int $id): bool
    {
        return $this->db->scalar('SELECT anonymized_at FROM customers WHERE id = :id', ['id' => $id]) !== null;
    }

    // --- Adresses --------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function addressesFor(int $customerId): array
    {
        return $this->db->select('SELECT * FROM addresses WHERE customer_id = :id ORDER BY id', ['id' => $customerId]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function addAddress(int $customerId, array $data): void
    {
        $this->db->insert('addresses', $this->addressColumns($data) + ['customer_id' => $customerId]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateAddress(int $addressId, int $customerId, array $data): void
    {
        $cols = $this->addressColumns($data);
        $set = implode(', ', array_map(static fn (string $c): string => "$c = :$c", array_keys($cols)));
        $cols['id'] = $addressId;
        $cols['cid'] = $customerId;
        $this->db->run("UPDATE addresses SET $set WHERE id = :id AND customer_id = :cid", $cols);
    }

    public function deleteAddress(int $addressId, int $customerId): void
    {
        $this->db->run(
            'DELETE FROM addresses WHERE id = :id AND customer_id = :cid',
            ['id' => $addressId, 'cid' => $customerId],
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function addressColumns(array $data): array
    {
        $street = (string) ($data['street'] ?? '');
        $postalCode = (string) ($data['postal_code'] ?? '');
        $city = (string) ($data['city'] ?? '');
        // Géocode à chaque création/modification, pour que la carte du panneau
        // job dispose de coordonnées dès la saisie (jamais bloquant : la fiche
        // s'enregistre même si le géocodage échoue).
        $coords = $this->geocoder->geocode($street, (string) ($data['number'] ?? ''), $postalCode, $city);

        $cols = [
            'label' => ($data['label'] ?? '') !== '' ? (string) $data['label'] : null,
            'street' => $street,
            'number' => ($data['number'] ?? '') !== '' ? (string) $data['number'] : null,
            'box' => ($data['box'] ?? '') !== '' ? (string) $data['box'] : null,
            'postal_code' => $postalCode,
            'city' => $city,
            'country' => ($data['country'] ?? '') !== '' ? (string) $data['country'] : 'BE',
            'access_notes' => ($data['access_notes'] ?? '') !== '' ? (string) $data['access_notes'] : null,
        ];
        // Uniquement si le géocodage réussit : un échec ponctuel ne doit jamais
        // effacer des coordonnées déjà enregistrées (ex. modification d'une
        // adresse existante pour corriger juste une note d'accès).
        if ($coords !== null) {
            $cols['lat'] = $coords['lat'];
            $cols['lng'] = $coords['lng'];
        }

        return $cols;
    }

    // --- Notes -----------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function notesFor(int $customerId): array
    {
        return $this->db->select(
            'SELECT n.body, n.created_at, u.first_name
               FROM customer_notes n
               LEFT JOIN users u ON u.id = n.author_id
              WHERE n.customer_id = :id
              ORDER BY n.id DESC',
            ['id' => $customerId],
        );
    }

    public function addNote(int $customerId, ?int $authorId, string $body): void
    {
        $this->db->insert('customer_notes', [
            'customer_id' => $customerId,
            'author_id' => $authorId,
            'body' => $body,
        ]);
    }
}
