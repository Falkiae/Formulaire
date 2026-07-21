<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Core\Database;
use Keepnew\Core\Exception\NotFoundException;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Session;
use Keepnew\Core\View;

/**
 * Clients : liste avec LTV / fréquence / segment, et fiche détaillée.
 *
 * LTV = somme des totaux des commandes non annulées. Fréquence = nombre de
 * commandes. Le segment B2B/B2C vient du type client.
 */
final class CustomerController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Database $db,
    ) {
    }

    public function index(Request $request): Response
    {
        $q = $request->string('q');
        $params = [];
        $where = "WHERE c.anonymized_at IS NULL";
        if ($q !== '') {
            $where .= " AND (c.email LIKE :q OR c.last_name LIKE :q OR c.company_name LIKE :q)";
            $params['q'] = '%' . $q . '%';
        }

        $customers = $this->db->select(
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

        return $this->view->render('admin/customers', [
            'customers' => $customers,
            'q' => $q,
            'user_name' => $this->session->get('user_name'),
        ]);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $customer = $this->db->selectOne('SELECT * FROM customers WHERE id = :id', ['id' => $id]);
        if ($customer === null) {
            throw new NotFoundException('Client introuvable.');
        }

        $bookings = $this->db->select(
            'SELECT id, reference, status, total_cents, created_at FROM bookings WHERE customer_id = :id ORDER BY id DESC',
            ['id' => $id],
        );
        $ltv = (int) $this->db->scalar(
            "SELECT COALESCE(SUM(total_cents),0) FROM bookings WHERE customer_id = :id AND status <> 'cancelled'",
            ['id' => $id],
        );

        return $this->view->render('admin/customer', [
            'customer' => $customer,
            'bookings' => $bookings,
            'ltv_cents' => $ltv,
            'addresses' => $this->db->select('SELECT * FROM addresses WHERE customer_id = :id', ['id' => $id]),
            'notes' => $this->db->select('SELECT n.body, n.created_at, u.first_name FROM customer_notes n LEFT JOIN users u ON u.id=n.author_id WHERE n.customer_id = :id ORDER BY n.id DESC', ['id' => $id]),
            'user_name' => $this->session->get('user_name'),
        ]);
    }
}
