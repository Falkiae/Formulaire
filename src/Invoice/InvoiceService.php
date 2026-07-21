<?php

declare(strict_types=1);

namespace Keepnew\Invoice;

use Keepnew\Core\Database;
use Keepnew\Core\Exception\NotFoundException;
use Keepnew\Support\Clock;

/**
 * Facturation conforme TVA belge :
 *  - numérotation séquentielle SANS TROU (compteur verrouillé par année) ;
 *  - facture simplifiée si total TVAC < seuil (250 € par défaut), sinon complète ;
 *  - lignes issues des lignes de commande, TVA 21 %.
 *
 * Idempotent : une commande déjà facturée renvoie sa facture existante.
 */
final class InvoiceService
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Génère (ou récupère) la facture d'une commande.
     *
     * @return array<string, mixed>
     */
    public function createFromBooking(int $bookingId): array
    {
        $existing = $this->db->selectOne('SELECT * FROM invoices WHERE booking_id = :b', ['b' => $bookingId]);
        if ($existing !== null) {
            return $existing;
        }

        $booking = $this->db->selectOne('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId]);
        if ($booking === null) {
            throw new NotFoundException('Commande introuvable.');
        }

        $threshold = (int) ($this->db->scalar("SELECT `value` FROM settings WHERE `key` = 'finance.simplified_invoice_max_cents'") ?? 25000);

        return $this->db->transaction(function (Database $db) use ($booking, $bookingId, $threshold): array {
            $year = (int) Clock::nowUtc()->format('Y');
            $number = $this->nextNumber($db, $year);

            $total = (int) $booking['total_cents'];
            $vat = (int) $booking['vat_cents'];
            $subtotal = $total - $vat; // net HTVA (base taxable après remises + déplacement)

            $customer = $db->selectOne('SELECT * FROM customers WHERE id = :id', ['id' => (int) $booking['customer_id']]);
            $isB2b = ($customer['type'] ?? 'b2c') === 'b2b';

            $invoiceId = $db->insert('invoices', [
                'booking_id' => $bookingId,
                'customer_id' => (int) $booking['customer_id'],
                'number' => $number,
                'invoice_type' => $total < $threshold ? 'simplified' : 'full',
                'status' => 'issued',
                'issued_at' => Clock::nowUtc()->format('Y-m-d H:i:s'),
                'subtotal_cents' => $subtotal,
                'vat_cents' => $vat,
                'total_cents' => $total,
                'vat_rate_bp' => (int) $booking['vat_rate_bp'],
                // Peppol uniquement pour le B2B (obligatoire BE depuis 2026).
                'peppol_status' => $isB2b ? 'pending' : 'not_applicable',
            ]);

            // Lignes de facture depuis les lignes de commande.
            $items = $db->select('SELECT * FROM booking_items WHERE booking_id = :b', ['b' => $bookingId]);
            $order = 0;
            foreach ($items as $item) {
                $db->insert('invoice_lines', [
                    'invoice_id' => $invoiceId,
                    'description' => $item['label_snapshot'] ?? 'Prestation',
                    'quantity' => (int) $item['quantity'],
                    'unit_price_cents' => (int) $item['unit_price_cents'],
                    'vat_rate_bp' => (int) $booking['vat_rate_bp'],
                    'line_total_cents' => (int) $item['line_total_cents'],
                    'sort_order' => $order++,
                ]);
            }

            return $db->selectOne('SELECT * FROM invoices WHERE id = :id', ['id' => $invoiceId]) ?? [];
        });
    }

    /**
     * Numéro séquentiel sans trou : compteur par année verrouillé (FOR UPDATE).
     */
    private function nextNumber(Database $db, int $year): string
    {
        $key = 'finance.invoice_seq_' . $year;
        // Garantit l'existence du compteur.
        $db->run(
            "INSERT INTO settings (`key`, `value`, value_type, `group`, label)
             VALUES (:k, '0', 'int', 'finance', :label)
             ON DUPLICATE KEY UPDATE `key` = `key`",
            ['k' => $key, 'label' => 'Compteur de factures ' . $year],
        );
        $current = (int) $db->scalar("SELECT `value` FROM settings WHERE `key` = :k FOR UPDATE", ['k' => $key]);
        $next = $current + 1;
        $db->run("UPDATE settings SET `value` = :v WHERE `key` = :k", ['v' => (string) $next, 'k' => $key]);

        return sprintf('%d-%06d', $year, $next);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $invoiceId): ?array
    {
        return $this->db->selectOne('SELECT * FROM invoices WHERE id = :id', ['id' => $invoiceId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lines(int $invoiceId): array
    {
        return $this->db->select('SELECT * FROM invoice_lines WHERE invoice_id = :id ORDER BY sort_order', ['id' => $invoiceId]);
    }
}
