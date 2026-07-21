<?php

declare(strict_types=1);

namespace Keepnew\Tracking;

use Keepnew\Core\Database;

/**
 * Orchestration du tracking serveur d'une conversion (achat/réservation).
 *
 * Construit le contexte depuis la commande, déclenche Meta CAPI (dédupliqué via
 * event_id partagé avec le pixel navigateur) et prépare les données hachées pour
 * Google Ads Enhanced Conversions. Le tracking est une exigence de premier
 * ordre, mais inactif tant que les identifiants ne sont pas configurés.
 */
final class TrackingService
{
    public function __construct(
        private readonly Database $db,
        private readonly MetaCapiClient $meta,
    ) {
    }

    /**
     * Déclenche l'événement Purchase serveur pour une réservation.
     */
    public function purchaseFromBooking(int $bookingId, string $eventId, ?string $clientIp, ?string $userAgent): void
    {
        $booking = $this->db->selectOne(
            'SELECT b.total_cents, c.email, c.phone, c.first_name, c.last_name
             FROM bookings b JOIN customers c ON c.id = b.customer_id WHERE b.id = :id',
            ['id' => $bookingId],
        );
        if ($booking === null) {
            return;
        }
        $city = (string) ($this->db->scalar(
            'SELECT a.city FROM jobs j JOIN addresses a ON a.id = j.address_id WHERE j.booking_id = :b LIMIT 1',
            ['b' => $bookingId],
        ) ?? '');

        $contents = array_map(
            static fn (array $it): array => [
                'id' => (string) $it['service_id'],
                'quantity' => (int) $it['quantity'],
                'item_price' => ((int) $it['unit_price_cents']) / 100,
            ],
            $this->db->select('SELECT service_id, quantity, unit_price_cents FROM booking_items WHERE booking_id = :b', ['b' => $bookingId]),
        );

        $user = array_filter([
            'email' => $booking['email'] ?? null,
            'phone' => $booking['phone'] ?? null,
            'first_name' => $booking['first_name'] ?? null,
            'last_name' => $booking['last_name'] ?? null,
            'city' => $city ?: null,
        ]);

        $sent = $this->meta->purchase(
            $eventId,
            $user,
            ['value' => ((int) $booking['total_cents']) / 100, 'currency' => 'EUR', 'contents' => $contents],
            $clientIp,
            $userAgent,
        );

        // Journalise l'événement (déduplication + audit tracking).
        $this->db->insert('notifications_log', [
            'booking_id' => $bookingId,
            'event_key' => 'tracking_purchase',
            'channel' => 'email', // réutilise l'enum ; canal technique
            'recipient' => 'meta_capi',
            'status' => $sent ? 'sent' : ($this->meta->isEnabled() ? 'failed' : 'skipped'),
            'provider_ref' => $eventId,
        ]);
    }
}
