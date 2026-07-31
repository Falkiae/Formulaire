<?php

declare(strict_types=1);

namespace Keepnew\Booking;

use Keepnew\Catalog\CatalogRepository;
use Keepnew\Core\Database;
use Keepnew\Core\Exception\HttpException;
use Keepnew\Core\Exception\NotFoundException;
use Keepnew\Support\Money;

/**
 * Retouche d'une commande déjà passée, depuis le back-office : corriger un
 * prix, ajouter une prestation du catalogue ou sur mesure, en retirer une,
 * accorder une remise.
 *
 * Le devis initial est produit par CartPricer à partir du panier ; il n'est
 * plus rejouable ici (le panier est converti, les règles ont pu changer). Les
 * totaux sont donc RECALCULÉS à partir des lignes réellement présentes, avec
 * exactement la même arithmétique que CartPricer :
 *
 *     net TVA comprise exclue = sous-total − remise + supplément déplacement
 *     TVA                     = Money::vat(net, taux figé sur la commande)
 *     total TVAC              = net + TVA
 *
 * Le taux de TVA reste celui figé sur la commande (`vat_rate_bp`) : une
 * commande passée sous un taux donné ne doit pas changer de taux parce qu'un
 * réglage a bougé depuis.
 *
 * Deux verrous, volontairement stricts :
 *  - une commande FACTURÉE n'est plus modifiable (la numérotation est
 *    séquentielle et sans trou ; désynchroniser facture et commande créerait un
 *    écart comptable invisible) ;
 *  - une commande ANNULÉE non plus.
 */
final class BookingEditService
{
    public function __construct(
        private readonly Database $db,
        private readonly CatalogRepository $catalog,
    ) {
    }

    /**
     * Vérifie qu'une commande est modifiable et la renvoie.
     *
     * @return array<string, mixed>
     */
    public function assertEditable(int $bookingId): array
    {
        $booking = $this->db->selectOne('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId]);
        if ($booking === null) {
            throw new NotFoundException('Commande introuvable.');
        }

        $invoice = $this->db->scalar('SELECT number FROM invoices WHERE booking_id = :b LIMIT 1', ['b' => $bookingId]);
        if ($invoice !== null) {
            throw new HttpException(409, sprintf(
                'Cette commande est facturée (%s) : elle n\'est plus modifiable. Passez par un avoir ou une facture complémentaire.',
                (string) $invoice,
            ));
        }

        if ($booking['status'] === 'cancelled') {
            throw new HttpException(409, 'Cette commande est annulée : elle n\'est plus modifiable.');
        }

        return $booking;
    }

    /**
     * Corrige le prix unitaire et/ou la quantité d'une ligne.
     */
    public function updateLine(int $bookingId, int $itemId, int $unitPriceCents, int $quantity): void
    {
        $this->assertEditable($bookingId);
        $item = $this->line($bookingId, $itemId);

        $quantity = max(1, $quantity);
        $unitPriceCents = max(0, $unitPriceCents);

        $this->db->run(
            'UPDATE booking_items SET unit_price_cents = :p, quantity = :q, line_total_cents = :t WHERE id = :id',
            ['p' => $unitPriceCents, 'q' => $quantity, 't' => $unitPriceCents * $quantity, 'id' => (int) $item['id']],
        );

        $this->recompute($bookingId);
    }

    /**
     * Ajoute une prestation du catalogue, au tarif et à la durée du mode du
     * rendez-vous auquel elle est rattachée.
     */
    public function addCatalogLine(int $bookingId, int $jobId, int $serviceId, ?int $variantId, int $quantity): void
    {
        $this->assertEditable($bookingId);
        $job = $this->job($bookingId, $jobId);
        $mode = (string) $job['mode'];

        $service = $this->catalog->findService($serviceId);
        if ($service === null) {
            throw new NotFoundException('Prestation inconnue.');
        }
        $modeRow = $this->catalog->serviceMode($serviceId, $mode);
        if ($modeRow === null) {
            throw new HttpException(422, sprintf(
                'La prestation « %s » n\'est pas proposée %s.',
                (string) $service['name'],
                $mode === 'onsite' ? 'à domicile' : 'en atelier',
            ));
        }

        $unitPrice = $modeRow['price_cents'] !== null ? (int) $modeRow['price_cents'] : (int) ($service['base_price_cents'] ?? 0);
        $unitDuration = $modeRow['active_duration_min'] !== null
            ? (int) $modeRow['active_duration_min']
            : (int) ($service['base_duration_min'] ?? 60);

        $label = (string) $service['name'];
        if ($variantId !== null) {
            $variant = $this->catalog->findVariant($variantId);
            if ($variant !== null && (int) $variant['service_id'] === $serviceId) {
                $unitPrice += (int) $variant['price_delta_cents'];
                $unitDuration += (int) $variant['duration_delta_min'];
                $label .= ' — ' . (string) $variant['label'];
            } else {
                $variantId = null;
            }
        }

        $this->insertLine($bookingId, $jobId, $mode, $serviceId, $variantId, $label, $unitPrice, $unitDuration, $quantity);
        $this->recompute($bookingId);
    }

    /**
     * Ajoute une ligne libre (hors catalogue) : libellé, prix et durée saisis.
     */
    public function addCustomLine(
        int $bookingId,
        int $jobId,
        string $label,
        int $unitPriceCents,
        int $quantity,
        int $durationMin,
    ): void {
        $this->assertEditable($bookingId);
        $job = $this->job($bookingId, $jobId);

        $label = trim($label);
        if ($label === '') {
            throw new HttpException(422, 'Donnez un libellé à la prestation sur mesure.');
        }

        $this->insertLine(
            $bookingId,
            $jobId,
            (string) $job['mode'],
            null,
            null,
            $label,
            max(0, $unitPriceCents),
            max(0, $durationMin),
            max(1, $quantity),
        );
        $this->recompute($bookingId);
    }

    /**
     * Retire une ligne. La dernière n'est pas supprimable : une commande sans
     * prestation n'aurait plus de sens (utiliser l'annulation).
     */
    public function removeLine(int $bookingId, int $itemId): void
    {
        $this->assertEditable($bookingId);
        $item = $this->line($bookingId, $itemId);

        $count = (int) $this->db->scalar('SELECT COUNT(*) FROM booking_items WHERE booking_id = :b', ['b' => $bookingId]);
        if ($count <= 1) {
            throw new HttpException(422, 'Une commande garde au moins une prestation : annulez-la plutôt que de la vider.');
        }

        $this->db->run('DELETE FROM booking_items WHERE id = :id', ['id' => (int) $item['id']]);
        $this->recompute($bookingId);
    }

    /**
     * Fixe la remise de la commande, en centimes, sur le sous-total HTVA.
     * Écrase la remise existante (cumul/coupon d'origine comprise) : c'est le
     * montant que l'admin décide, pas un cumul implicite.
     */
    public function setDiscount(int $bookingId, int $discountCents): void
    {
        $this->assertEditable($bookingId);

        $subtotal = (int) $this->db->scalar(
            'SELECT COALESCE(SUM(line_total_cents), 0) FROM booking_items WHERE booking_id = :b',
            ['b' => $bookingId],
        );
        $discount = max(0, min($discountCents, $subtotal));

        $this->db->run('UPDATE bookings SET discount_cents = :d WHERE id = :id', ['d' => $discount, 'id' => $bookingId]);
        $this->recompute($bookingId);
    }

    /**
     * Convertit un pourcentage (points de base) en remise sur le sous-total.
     */
    public function setDiscountPercent(int $bookingId, int $percentBp): void
    {
        $this->assertEditable($bookingId);

        $subtotal = (int) $this->db->scalar(
            'SELECT COALESCE(SUM(line_total_cents), 0) FROM booking_items WHERE booking_id = :b',
            ['b' => $bookingId],
        );

        $this->setDiscount($bookingId, Money::percentOf($subtotal, max(0, min(10000, $percentBp))));
    }

    /**
     * Vrai si le rendez-vous, après recalcul de sa durée, chevauche un autre
     * rendez-vous du même technicien. Signalé à l'admin sans rien bloquer :
     * allonger une prestation est légitime, c'est le planning qui doit suivre.
     */
    public function overlapsAnotherJob(int $jobId): bool
    {
        $job = $this->db->selectOne(
            'SELECT technician_id, scheduled_start, scheduled_end FROM jobs WHERE id = :id',
            ['id' => $jobId],
        );
        if ($job === null || $job['technician_id'] === null || $job['scheduled_start'] === null || $job['scheduled_end'] === null) {
            return false;
        }

        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM jobs
              WHERE id <> :id AND technician_id = :t
                AND status NOT IN ('cancelled')
                AND scheduled_start IS NOT NULL AND scheduled_end IS NOT NULL
                AND scheduled_start < :end AND scheduled_end > :start",
            [
                'id' => $jobId,
                't' => (int) $job['technician_id'],
                'start' => $job['scheduled_start'],
                'end' => $job['scheduled_end'],
            ],
        ) > 0;
    }

    // --- Interne ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function line(int $bookingId, int $itemId): array
    {
        $item = $this->db->selectOne(
            'SELECT * FROM booking_items WHERE id = :id AND booking_id = :b',
            ['id' => $itemId, 'b' => $bookingId],
        );
        if ($item === null) {
            throw new NotFoundException('Ligne introuvable.');
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function job(int $bookingId, int $jobId): array
    {
        $job = $this->db->selectOne(
            'SELECT * FROM jobs WHERE id = :id AND booking_id = :b',
            ['id' => $jobId, 'b' => $bookingId],
        );
        if ($job === null) {
            throw new NotFoundException('Rendez-vous introuvable.');
        }

        return $job;
    }

    private function insertLine(
        int $bookingId,
        int $jobId,
        string $mode,
        ?int $serviceId,
        ?int $variantId,
        string $label,
        int $unitPriceCents,
        int $unitDurationMin,
        int $quantity,
    ): void {
        $this->db->insert('booking_items', [
            'booking_id' => $bookingId,
            'job_id' => $jobId,
            'service_id' => $serviceId,
            'variant_id' => $variantId,
            'mode' => $mode,
            'quantity' => $quantity,
            'unit_price_cents' => $unitPriceCents,
            'unit_duration_min' => $unitDurationMin,
            'line_total_cents' => $unitPriceCents * $quantity,
            'label_snapshot' => $label,
            'config_snapshot' => json_encode(
                ['mode' => $mode, 'source' => $serviceId === null ? 'sur_mesure' : 'catalogue'],
                JSON_UNESCAPED_UNICODE,
            ),
        ]);
    }

    /**
     * Réaligne totaux et durées sur les lignes réellement présentes.
     *
     * La durée d'un rendez-vous est la somme des durées de SES lignes : ajouter
     * une prestation allonge l'intervention, et `scheduled_end` suit — sans quoi
     * le moteur de disponibilité continuerait de croire le technicien libre.
     */
    private function recompute(int $bookingId): void
    {
        $this->db->transaction(function (Database $db) use ($bookingId): void {
            $booking = $db->selectOne('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId]);
            if ($booking === null) {
                return;
            }

            $subtotal = (int) $db->scalar(
                'SELECT COALESCE(SUM(line_total_cents), 0) FROM booking_items WHERE booking_id = :b',
                ['b' => $bookingId],
            );
            $duration = (int) $db->scalar(
                'SELECT COALESCE(SUM(unit_duration_min * quantity), 0) FROM booking_items WHERE booking_id = :b',
                ['b' => $bookingId],
            );

            // Une remise supérieure au sous-total après retrait d'une ligne
            // serait absurde : on la ramène au plafond.
            $discount = min((int) $booking['discount_cents'], $subtotal);
            $travel = (int) $booking['travel_surcharge_cents'];
            $rate = (int) $booking['vat_rate_bp'];

            $net = max(0, $subtotal - $discount + $travel);
            $vat = Money::vat($net, $rate);

            $db->run(
                'UPDATE bookings SET subtotal_cents = :s, discount_cents = :d, vat_cents = :v,
                        total_cents = :t, total_duration_min = :dur
                  WHERE id = :id',
                ['s' => $subtotal, 'd' => $discount, 'v' => $vat, 't' => $net + $vat, 'dur' => $duration, 'id' => $bookingId],
            );

            // Durée par rendez-vous + fin planifiée.
            $jobs = $db->select(
                'SELECT id, scheduled_start, occupancy_duration_min, mode FROM jobs WHERE booking_id = :b',
                ['b' => $bookingId],
            );
            foreach ($jobs as $job) {
                $jobDuration = (int) $db->scalar(
                    'SELECT COALESCE(SUM(unit_duration_min * quantity), 0) FROM booking_items WHERE job_id = :j',
                    ['j' => (int) $job['id']],
                );
                if ($jobDuration <= 0) {
                    continue;
                }

                // À l'atelier, l'immobilisation du poste ne descend jamais sous
                // la durée de travail effective (cf. BookingService).
                $occupancy = $job['mode'] === 'workshop'
                    ? max((int) $job['occupancy_duration_min'], $jobDuration)
                    : $jobDuration;

                $end = null;
                if ($job['scheduled_start'] !== null) {
                    $end = (new \DateTimeImmutable((string) $job['scheduled_start'], new \DateTimeZone('UTC')))
                        ->modify('+' . ($job['mode'] === 'workshop' ? $occupancy : $jobDuration) . ' minutes')
                        ->format('Y-m-d H:i:s');
                }

                $db->run(
                    'UPDATE jobs SET active_duration_min = :a, occupancy_duration_min = :o, scheduled_end = :e WHERE id = :id',
                    ['a' => $jobDuration, 'o' => $occupancy, 'e' => $end, 'id' => (int) $job['id']],
                );
            }
        });
    }
}
