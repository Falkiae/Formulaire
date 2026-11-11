<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Core\Database;
use Keepnew\Support\Clock;
use Keepnew\Support\Money;

/**
 * Jeu de rendez-vous fictifs, pour voir le planning rempli.
 *
 * Ces données s'écrivent dans la VRAIE base. Deux garde-fous, et ils sont la
 * raison d'être de cette classe :
 *
 * 1. TOUT EST MARQUÉ. Les commandes portent une référence `KN-DEMO-……` et les
 *    clients une adresse e-mail en `@demo.invalid` — un domaine que la norme
 *    (RFC 2606) réserve et qu'aucun serveur ne peut livrer, donc une
 *    notification partie par accident ne touchera jamais personne.
 * 2. TOUT SE RETIRE. `purge()` supprime exactement ce que `generate()` a créé,
 *    et rien d'autre : un jeu d'essai qu'on ne peut pas enlever n'est pas un
 *    jeu d'essai, c'est une pollution.
 *
 * Les lignes produites ont la même forme que celles de BookingService (mêmes
 * colonnes, même historique de statut) : un jeu qui ne ressemblerait pas aux
 * vraies données ne prouverait rien sur le planning.
 */
final class DemoDataService
{
    public const REFERENCE_PREFIX = 'KN-DEMO-';
    public const EMAIL_DOMAIN = '@demo.invalid';

    /** Quelques adresses réelles de l'agglomération liégeoise, pour la carte. */
    private const ADDRESSES = [
        ['Rue Saint-Gilles', '12', '4000', 'Liège', 50.6395, 5.5705],
        ['Boulevard d\'Avroy', '134', '4000', 'Liège', 50.6367, 5.5692],
        ['Rue de la Station', '8', '4420', 'Saint-Nicolas', 50.6356, 5.5278],
        ['Place Communale', '3', '4432', 'Ans', 50.6621, 5.5313],
        ['Rue de l\'Église', '41', '4600', 'Visé', 50.7380, 5.7007],
        ['Avenue de la Résistance', '77', '4030', 'Grivegnée', 50.6227, 5.6063],
        ['Rue Haute', '19', '4040', 'Herstal', 50.6633, 5.6256],
        ['Chaussée de Tongres', '210', '4000', 'Rocourt', 50.6716, 5.5428],
        ['Rue du Village', '5', '4121', 'Neupré', 50.5536, 5.4737],
        ['Rue de Fragnée', '63', '4000', 'Liège', 50.6256, 5.5641],
    ];

    /** @var list<array{string, string}> Prénoms / noms fictifs. */
    private const NAMES = [
        ['Nina', 'Lambert'], ['Marc', 'Dubois'], ['Sofia', 'Renard'], ['Thomas', 'Gérard'],
        ['Amélie', 'Collard'], ['Youssef', 'Benali'], ['Claire', 'Lejeune'], ['Pierre', 'Dumont'],
        ['Lucie', 'Hubert'], ['David', 'Moreau'], ['Fatima', 'Haddad'], ['Julien', 'Piret'],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /** Nombre de commandes de démonstration actuellement en base. */
    public function count(): int
    {
        return (int) ($this->db->scalar(
            'SELECT COUNT(*) FROM bookings WHERE reference LIKE :p',
            ['p' => self::REFERENCE_PREFIX . '%'],
        ) ?? 0);
    }

    /**
     * Ce qui manque pour pouvoir générer. Liste vide = tout est prêt.
     *
     * @return list<string>
     */
    public function blockers(): array
    {
        $out = [];
        if ($this->activeTechnicianIds() === []) {
            $out[] = 'Aucun technicien actif : créez-en au moins un dans Équipe → Techniciens.';
        }
        if ($this->onsiteService() === null) {
            $out[] = 'Aucune prestation active proposée à domicile : créez-en une dans Services → Catalogue.';
        }

        return $out;
    }

    /**
     * Crée le jeu. Renvoie le nombre de commandes créées.
     *
     * @throws \RuntimeException si le catalogue ou l'équipe ne permettent pas
     */
    public function generate(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $blockers = $this->blockers();
        if ($blockers !== []) {
            throw new \RuntimeException(implode(' ', $blockers));
        }

        $technicians = $this->activeTechnicianIds();
        $service = $this->onsiteService();
        $plan = DemoPlanner::plan($from, $to, $technicians, Clock::nowUtc());
        if ($plan === []) {
            return 0;
        }

        $vatRateBp = (int) ($this->db->scalar("SELECT `value` FROM settings WHERE `key` = 'finance.vat_rate_bp'") ?? 2100);
        $unitPriceHt = (int) ($service['price_cents'] ?? $service['base_price_cents'] ?? 0);

        return $this->db->transaction(function (Database $db) use ($plan, $service, $unitPriceHt, $vatRateBp): int {
            $created = 0;
            foreach ($plan as $i => $rdv) {
                // Un créneau déjà occupé par une VRAIE intervention est sauté :
                // le jeu d'essai ne doit jamais masquer ni concurrencer le
                // travail réel.
                if ($this->technicianBusy($db, $rdv)) {
                    continue;
                }

                [$firstName, $lastName] = self::NAMES[$i % count(self::NAMES)];
                $addr = self::ADDRESSES[$i % count(self::ADDRESSES)];

                $customerId = $db->insert('customers', [
                    'type' => 'b2c',
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => 'demo-' . $i . '-' . bin2hex(random_bytes(3)) . self::EMAIL_DOMAIN,
                    'phone' => '+32 470 00 00 ' . str_pad((string) ($i % 100), 2, '0', STR_PAD_LEFT),
                ]);

                $addressId = $db->insert('addresses', [
                    'customer_id' => $customerId,
                    'street' => $addr[0],
                    'number' => $addr[1],
                    'postal_code' => $addr[2],
                    'city' => $addr[3],
                    'lat' => $addr[4],
                    'lng' => $addr[5],
                ]);

                $totalHt = $unitPriceHt;
                $totalTvac = Money::addVat($totalHt, $vatRateBp);
                // Une commande annulée reste annulée ; sinon le statut de la
                // commande se déduit de celui de son rendez-vous, comme le fait
                // BookingService::syncStatusFromJobs().
                $bookingStatus = match ($rdv['status']) {
                    'cancelled' => 'cancelled',
                    'completed' => 'completed',
                    'en_route', 'in_progress' => 'in_progress',
                    default => 'confirmed',
                };

                $bookingId = $db->insert('bookings', [
                    'reference' => 'TMP-' . bin2hex(random_bytes(6)),
                    'customer_id' => $customerId,
                    'address_id' => $addressId,
                    'booking_mode' => 'instant',
                    'status' => $bookingStatus,
                    'payment_status' => 'not_required',
                    'subtotal_cents' => $totalHt,
                    'discount_cents' => 0,
                    'travel_surcharge_cents' => 0,
                    'vat_cents' => $totalTvac - $totalHt,
                    'total_cents' => $totalTvac,
                    'vat_rate_bp' => $vatRateBp,
                    'total_duration_min' => $rdv['duration_min'],
                    'manage_token' => bin2hex(random_bytes(24)),
                ]);
                // La référence porte la marque : c'est elle qui rend le jeu
                // reconnaissable d'un coup d'œil, et supprimable.
                $db->run(
                    'UPDATE bookings SET reference = :r WHERE id = :id',
                    ['r' => sprintf('%s%06d', self::REFERENCE_PREFIX, $bookingId), 'id' => $bookingId],
                );

                $start = new \DateTimeImmutable($rdv['start_utc'], new \DateTimeZone('UTC'));
                $jobId = $db->insert('jobs', [
                    'booking_id' => $bookingId,
                    'mode' => 'onsite',
                    'technician_id' => $rdv['technician_id'],
                    'address_id' => $addressId,
                    'scheduled_start' => $rdv['start_utc'],
                    'scheduled_end' => $start->modify('+' . $rdv['duration_min'] . ' minutes')->format('Y-m-d H:i:s'),
                    'arrival_from' => $rdv['start_utc'],
                    'arrival_to' => $start->modify('+120 minutes')->format('Y-m-d H:i:s'),
                    'active_duration_min' => $rdv['duration_min'],
                    'occupancy_duration_min' => $rdv['duration_min'],
                    'status' => $rdv['status'],
                ]);

                $db->insert('booking_items', [
                    'booking_id' => $bookingId,
                    'job_id' => $jobId,
                    'service_id' => (int) $service['id'],
                    'mode' => 'onsite',
                    'quantity' => 1,
                    'unit_price_cents' => $unitPriceHt,
                    'unit_duration_min' => $rdv['duration_min'],
                    'line_total_cents' => $totalHt,
                    'label_snapshot' => (string) $service['name'],
                ]);

                $db->insert('booking_status_history', [
                    'booking_id' => $bookingId,
                    'new_status' => $bookingStatus,
                    'note' => 'Jeu de démonstration',
                ]);

                $created++;
            }

            return $created;
        });
    }

    /**
     * Supprime exactement ce que `generate()` a créé. Renvoie le nombre de
     * commandes supprimées.
     */
    public function purge(): int
    {
        return $this->db->transaction(function (Database $db): int {
            $rows = $db->select(
                'SELECT id, customer_id, address_id FROM bookings WHERE reference LIKE :p',
                ['p' => self::REFERENCE_PREFIX . '%'],
            );
            if ($rows === []) {
                return 0;
            }

            $bookingIds = array_map(static fn (array $r): int => (int) $r['id'], $rows);
            $in = implode(',', array_fill(0, count($bookingIds), '?'));

            // Ordre imposé par les clés étrangères : du plus feuille au plus
            // racine.
            $db->run(
                "DELETE FROM booking_item_extras WHERE booking_item_id IN
                 (SELECT id FROM booking_items WHERE booking_id IN ({$in}))",
                $bookingIds,
            );
            foreach (['booking_items', 'booking_status_history', 'jobs', 'booking_answers'] as $table) {
                $db->run("DELETE FROM {$table} WHERE booking_id IN ({$in})", $bookingIds);
            }
            $db->run("DELETE FROM invoices WHERE booking_id IN ({$in})", $bookingIds);
            $db->run("DELETE FROM bookings WHERE id IN ({$in})", $bookingIds);

            // Clients et adresses : seulement ceux du jeu, et seulement s'ils
            // ne servent plus à rien d'autre.
            $db->run(
                'DELETE FROM addresses WHERE customer_id IN
                 (SELECT id FROM customers WHERE email LIKE :d)',
                ['d' => '%' . self::EMAIL_DOMAIN],
            );
            $db->run(
                'DELETE FROM customers WHERE email LIKE :d
                 AND id NOT IN (SELECT customer_id FROM bookings)',
                ['d' => '%' . self::EMAIL_DOMAIN],
            );

            return count($bookingIds);
        });
    }

    /** @return list<int> */
    private function activeTechnicianIds(): array
    {
        return array_map(
            static fn (array $r): int => (int) $r['id'],
            $this->db->select('SELECT id FROM technicians WHERE is_active = 1 ORDER BY id'),
        );
    }

    /**
     * Une prestation active réellement proposée à domicile, avec son prix du
     * mode s'il existe.
     *
     * @return array<string, mixed>|null
     */
    private function onsiteService(): ?array
    {
        return $this->db->selectOne(
            "SELECT s.id, s.name, s.base_price_cents, m.price_cents, m.active_duration_min
             FROM services s
             JOIN service_delivery_modes m
               ON m.service_id = s.id AND m.mode = 'onsite' AND m.is_active = 1
             WHERE s.is_active = 1
             ORDER BY s.sort_order, s.id
             LIMIT 1",
        );
    }

    /**
     * @param array{start_utc:string, technician_id:int, duration_min:int} $rdv
     */
    private function technicianBusy(Database $db, array $rdv): bool
    {
        $start = $rdv['start_utc'];
        $end = (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))
            ->modify('+' . $rdv['duration_min'] . ' minutes')
            ->format('Y-m-d H:i:s');

        return $db->scalar(
            "SELECT id FROM jobs
             WHERE technician_id = :t AND status NOT IN ('cancelled', 'unscheduled')
               AND scheduled_start < :end AND scheduled_end > :start
             LIMIT 1",
            ['t' => $rdv['technician_id'], 'start' => $start, 'end' => $end],
        ) !== null;
    }
}
