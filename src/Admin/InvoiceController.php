<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Core\Csrf;
use Keepnew\Core\Database;
use Keepnew\Core\Exception\NotFoundException;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Session;
use Keepnew\Core\View;
use Keepnew\Invoice\InvoiceService;
use Keepnew\Invoice\JournalExporter;
use Keepnew\Invoice\UblGenerator;
use Keepnew\Support\Clock;

/**
 * Factures : liste, génération depuis une commande, export UBL (Peppol) et
 * journal des recettes.
 */
final class InvoiceController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Database $db,
        private readonly InvoiceService $invoices,
        private readonly UblGenerator $ubl,
        private readonly JournalExporter $journal,
    ) {
    }

    public function index(Request $request): Response
    {
        $invoices = $this->db->select(
            "SELECT i.*, COALESCE(c.company_name, CONCAT(c.first_name,' ',c.last_name)) AS customer
             FROM invoices i JOIN customers c ON c.id = i.customer_id
             ORDER BY i.number DESC LIMIT 200",
        );
        // Commandes complétées sans facture (à facturer).
        $toInvoice = $this->db->select(
            "SELECT b.id, b.reference, b.total_cents FROM bookings b
             LEFT JOIN invoices i ON i.booking_id = b.id
             WHERE i.id IS NULL AND b.status IN ('confirmed','completed')
             ORDER BY b.id DESC LIMIT 50",
        );

        return $this->view->render('admin/invoice/index', [
            'csrf' => $this->csrf->field(),
            'invoices' => $invoices,
            'to_invoice' => $toInvoice,
            'user_name' => $this->session->get('user_name'),
            'flash' => $this->session->pullFlash('inv_ok'),
        ]);
    }

    public function generate(Request $request): Response
    {
        $bookingId = (int) $request->attribute('bookingId');
        $invoice = $this->invoices->createFromBooking($bookingId);
        $this->session->flash('inv_ok', 'Facture ' . ($invoice['number'] ?? '') . ' générée.');

        return Response::redirect('/admin/factures');
    }

    /**
     * GET /admin/factures/{id}/ubl — télécharge l'UBL BIS 3.0 (B2B / Peppol).
     */
    public function ubl(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $invoice = $this->invoices->find($id);
        if ($invoice === null) {
            throw new NotFoundException('Facture introuvable.');
        }
        $customer = $this->db->selectOne('SELECT * FROM customers WHERE id = :id', ['id' => (int) $invoice['customer_id']]);

        $supplier = [
            'name' => (string) ($this->db->scalar("SELECT `value` FROM settings WHERE `key`='company.name'") ?? 'Keepnew SRL'),
            'vat' => (string) ($this->db->scalar("SELECT `value` FROM settings WHERE `key`='company.vat'") ?? 'BE1009875116'),
        ];
        $customerParty = [
            'name' => $customer['company_name'] ?: trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')),
            'vat' => $customer['vat_number'] ?? '',
        ];

        $xml = $this->ubl->generate($invoice, $supplier, $customerParty, $this->invoices->lines($id));

        // Marque l'export Peppol comme envoyé (le hook d'access point serait ici).
        $this->db->run("UPDATE invoices SET peppol_status = CASE WHEN peppol_status='pending' THEN 'sent' ELSE peppol_status END, peppol_sent_at = UTC_TIMESTAMP() WHERE id = :id", ['id' => $id]);

        return Response::html($xml)
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="facture-' . $invoice['number'] . '.xml"');
    }

    /**
     * GET /admin/factures/journal?from=&to= — export CSV du journal des recettes.
     */
    public function journal(Request $request): Response
    {
        $from = $request->string('from') ?: Clock::format(Clock::nowUtc()->modify('-1 month'), 'Y-m-d');
        $to = $request->string('to') ?: Clock::format(Clock::nowUtc(), 'Y-m-d');
        $csv = $this->journal->csv($from, $to);

        return Response::html($csv)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="journal-recettes-' . $from . '_' . $to . '.csv"');
    }
}
