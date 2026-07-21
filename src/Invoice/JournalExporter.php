<?php

declare(strict_types=1);

namespace Keepnew\Invoice;

use Keepnew\Core\Database;

/**
 * Journal des recettes (obligation TVA belge) : export CSV des factures émises
 * sur une période, avec base HTVA, TVA et total TVAC.
 */
final class JournalExporter
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Génère le CSV du journal des recettes entre deux dates (incluses).
     */
    public function csv(string $fromDate, string $toDate): string
    {
        $rows = $this->db->select(
            "SELECT i.number, i.issued_at, i.invoice_type, i.subtotal_cents, i.vat_cents, i.total_cents,
                    c.type AS customer_type, COALESCE(c.company_name, CONCAT(c.first_name,' ',c.last_name)) AS customer
             FROM invoices i JOIN customers c ON c.id = i.customer_id
             WHERE i.status <> 'cancelled' AND DATE(i.issued_at) BETWEEN :from AND :to
             ORDER BY i.number",
            ['from' => $fromDate, 'to' => $toDate],
        );

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Numéro', 'Date', 'Type', 'Client', 'B2B/B2C', 'HTVA (€)', 'TVA (€)', 'TVAC (€)'], ';');
        $totHt = 0;
        $totVat = 0;
        $totTvac = 0;
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['number'],
                substr((string) $r['issued_at'], 0, 10),
                $r['invoice_type'],
                $r['customer'],
                strtoupper((string) $r['customer_type']),
                number_format(((int) $r['subtotal_cents']) / 100, 2, ',', ''),
                number_format(((int) $r['vat_cents']) / 100, 2, ',', ''),
                number_format(((int) $r['total_cents']) / 100, 2, ',', ''),
            ], ';');
            $totHt += (int) $r['subtotal_cents'];
            $totVat += (int) $r['vat_cents'];
            $totTvac += (int) $r['total_cents'];
        }
        fputcsv($out, ['', '', '', '', 'TOTAUX', number_format($totHt / 100, 2, ',', ''), number_format($totVat / 100, 2, ',', ''), number_format($totTvac / 100, 2, ',', '')], ';');

        rewind($out);

        return (string) stream_get_contents($out);
    }
}
