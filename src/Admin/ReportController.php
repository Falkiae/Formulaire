<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Core\Database;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Session;
use Keepnew\Core\View;

/**
 * Rapports : chiffre d'affaires par service / technicien, taux de conversion du
 * tunnel, taux d'annulation, panier moyen, km parcourus.
 *
 * Le CA est compté sur les commandes non annulées.
 */
final class ReportController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Database $db,
    ) {
    }

    public function index(Request $request): Response
    {
        $paid = "status <> 'cancelled'";

        $totals = [
            'bookings' => (int) $this->db->scalar("SELECT COUNT(*) FROM bookings WHERE {$paid}"),
            'revenue_cents' => (int) $this->db->scalar("SELECT COALESCE(SUM(total_cents),0) FROM bookings WHERE {$paid}"),
            'avg_basket_cents' => (int) round((float) $this->db->scalar("SELECT COALESCE(AVG(total_cents),0) FROM bookings WHERE {$paid}")),
            'carts' => (int) $this->db->scalar("SELECT COUNT(*) FROM carts"),
            'converted' => (int) $this->db->scalar("SELECT COUNT(*) FROM carts WHERE status = 'converted'"),
            'cancelled' => (int) $this->db->scalar("SELECT COUNT(*) FROM bookings WHERE status = 'cancelled'"),
            'total_bookings' => (int) $this->db->scalar("SELECT COUNT(*) FROM bookings"),
            'km' => (float) $this->db->scalar("SELECT COALESCE(SUM(distance_km),0) FROM mobility_allowances"),
        ];

        // Taux dérivés (en pourcentage).
        $conversionRate = $totals['carts'] > 0 ? round($totals['converted'] / $totals['carts'] * 100, 1) : 0.0;
        $cancelRate = $totals['total_bookings'] > 0 ? round($totals['cancelled'] / $totals['total_bookings'] * 100, 1) : 0.0;

        // booking_items.line_total_cents est HT (prix catalogue avant remise
        // panier/TVA) ; bookings.total_cents est TVAC (après remise + TVA).
        // Pour que "par service"/"par technicien" reste comparable au "chiffre
        // d'affaires" (TVAC) ci-dessus, chaque ligne est ramenée à sa part
        // proportionnelle du total TVAC réellement facturé sur sa commande —
        // ceci convertit la TVA ET répercute la remise groupée éventuelle,
        // contrairement à une simple multiplication par le taux de TVA qui
        // ignorerait la remise. La somme des lignes d'une commande retombe
        // ainsi exactement sur bookings.total_cents de cette commande.
        $bookingHtTotals = '(SELECT booking_id, SUM(line_total_cents) AS ht_sum FROM booking_items GROUP BY booking_id)';

        $byService = $this->db->select(
            "SELECT s.name, COUNT(*) AS lines_count,
                    COALESCE(SUM(ROUND(bi.line_total_cents * b.total_cents / NULLIF(bt.ht_sum, 0))),0) AS revenue_cents
             FROM booking_items bi
             JOIN services s ON s.id = bi.service_id
             JOIN bookings b ON b.id = bi.booking_id
             JOIN {$bookingHtTotals} bt ON bt.booking_id = b.id
             WHERE b.status <> 'cancelled'
             GROUP BY s.id ORDER BY revenue_cents DESC",
        );

        $byTechnician = $this->db->select(
            "SELECT t.first_name, t.last_name, COUNT(DISTINCT j.id) AS jobs_count,
                    COALESCE(SUM(ROUND(bi.line_total_cents * b.total_cents / NULLIF(bt.ht_sum, 0))),0) AS revenue_cents
             FROM jobs j
             JOIN technicians t ON t.id = j.technician_id
             JOIN bookings b ON b.id = j.booking_id
             LEFT JOIN booking_items bi ON bi.job_id = j.id
             LEFT JOIN {$bookingHtTotals} bt ON bt.booking_id = b.id
             WHERE b.status <> 'cancelled' AND j.status <> 'cancelled'
             GROUP BY t.id ORDER BY revenue_cents DESC",
        );

        $byMonth = $this->db->select(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS n, COALESCE(SUM(total_cents),0) AS revenue_cents
             FROM bookings WHERE status <> 'cancelled'
             GROUP BY month ORDER BY month DESC LIMIT 12",
        );

        return $this->view->render('admin/report/index', [
            'totals' => $totals,
            'conversion_rate' => $conversionRate,
            'cancel_rate' => $cancelRate,
            'by_service' => $byService,
            'by_technician' => $byTechnician,
            'by_month' => $byMonth,
            'user_name' => $this->session->get('user_name'),
        ]);
    }
}
