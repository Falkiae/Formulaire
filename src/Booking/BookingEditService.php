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
 * prix, ajouter une prestation du catalogue ou sur mesure, gérer ses extras,
 * accorder une remise.
 *
 * Le devis initial est produit par CartPricer à partir du panier ; il n'est
 * plus rejouable ici (le panier est converti, les règles ont pu changer). Les
 * MONTANTS sont donc recalculés à partir des lignes réellement présentes, avec
 * exactement la même arithmétique que CartPricer :
 *
 *     total de ligne = (prix unitaire + extras de la ligne) × quantité
 *     net            = sous-total − remise + supplément déplacement
 *     TVA            = Money::vat(net, taux figé sur la commande)
 *     total TVAC     = net + TVA
 *
 * Le taux de TVA reste celui figé sur la commande (`vat_rate_bp`) : une
 * commande passée sous un taux donné ne doit pas changer de taux parce qu'un
 * réglage a bougé depuis.
 *
 * Les DURÉES, elles, sont ajustées par DELTA et non recalculées : les lignes
 * issues du tunnel public sont enregistrées avec `unit_duration_min = 0`
 * (BookingService agrège les durées par mode au moment de créer les jobs, sans
 * les reporter sur les lignes). Un recalcul « somme des lignes » ramènerait
 * donc à zéro la durée des commandes existantes. On applique l'écart de chaque
 * opération, ce qui reste juste quelles que soient les données héritées.
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
     * Corrige le prix unitaire et/ou la quantité d'une ligne. Les extras de la
     * ligne sont conservés et restent comptés dans son total.
     */
    public function updateLine(int $bookingId, int $itemId, int $unitPriceCents, int $quantity): void
    {
        $this->assertEditable($bookingId);
        $item = $this->line($bookingId, $itemId);

        $quantity = max(1, $quantity);
        $unitPriceCents = max(0, $unitPriceCents);
        $before = (int) $item['quantity'];

        $this->db->run(
            'UPDATE booking_items SET unit_price_cents = :p, quantity = :q WHERE id = :id',
            ['p' => $unitPriceCents, 'q' => $quantity, 'id' => $itemId],
        );

        $this->refreshLineTotal($itemId);
        // La durée d'une ligne est multipliée par sa quantité : un changement de
        // quantité déplace donc la durée de l'intervention.
        $this->shiftDuration($bookingId, (int) $item['job_id'], $this->unitDurationOf($itemId) * ($quantity - $before));
        $this->recomputeTotals($bookingId);
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

        $this->insertLine($bookingId, $jobId, $mode, $serviceId, $variantId, $label, $unitPrice, $unitDuration, max(1, $quantity));
        $this->shiftDuration($bookingId, $jobId, $unitDuration * max(1, $quantity));
        $this->recomputeTotals($bookingId);
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

        $quantity = max(1, $quantity);
        $durationMin = max(0, $durationMin);

        $this->insertLine($bookingId, $jobId, (string) $job['mode'], null, null, $label, max(0, $unitPriceCents), $durationMin, $quantity);
        $this->shiftDuration($bookingId, $jobId, $durationMin * $quantity);
        $this->recomputeTotals($bookingId);
    }

    /**
     * Retire une ligne (et ses extras, en cascade). La dernière n'est pas
     * supprimable : une commande sans prestation n'aurait plus de sens
     * (utiliser l'annulation).
     */
    public function removeLine(int $bookingId, int $itemId): void
    {
        $this->assertEditable($bookingId);
        $item = $this->line($bookingId, $itemId);

        $count = (int) $this->db->scalar('SELECT COUNT(*) FROM booking_items WHERE booking_id = :b', ['b' => $bookingId]);
        if ($count <= 1) {
            throw new HttpException(422, 'Une commande garde au moins une prestation : annulez-la plutôt que de la vider.');
        }

        $lost = $this->unitDurationOf($itemId) * (int) $item['quantity'];

        $this->db->run('DELETE FROM booking_items WHERE id = :id', ['id' => $itemId]);
        $this->shiftDuration($bookingId, (int) $item['job_id'], -$lost);
        $this->recomputeTotals($bookingId);
    }

    /**
     * Rattache un extra à une ligne, au tarif du catalogue.
     *
     * Prix et durée sont FIGÉS à l'ajout (comme partout ailleurs) : le tarif
     * du catalogue peut bouger, celui de la commande passée ne doit pas.
     */
    public function addExtra(int $bookingId, int $itemId, int $extraId): void
    {
        $this->assertEditable($bookingId);
        $item = $this->line($bookingId, $itemId);

        $already = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM booking_item_extras WHERE booking_item_id = :i AND extra_id = :e',
            ['i' => $itemId, 'e' => $extraId],
        );
        if ($already > 0) {
            throw new HttpException(422, 'Cet extra est déjà rattaché à la prestation.');
        }

        [$price, $duration, $label] = $this->extraPricing($item, $extraId);

        $this->db->insert('booking_item_extras', [
            'booking_item_id' => $itemId,
            'extra_id' => $extraId,
            'unit_price_cents' => $price,
            'unit_duration_min' => $duration,
            'label_snapshot' => $label,
        ]);

        $this->refreshLineTotal($itemId);
        $this->shiftDuration($bookingId, (int) $item['job_id'], $duration * (int) $item['quantity']);
        $this->recomputeTotals($bookingId);
    }

    /**
     * Retire un extra d'une ligne.
     */
    public function removeExtra(int $bookingId, int $itemId, int $extraRowId): void
    {
        $this->assertEditable($bookingId);
        $item = $this->line($bookingId, $itemId);

        $row = $this->db->selectOne(
            'SELECT * FROM booking_item_extras WHERE id = :id AND booking_item_id = :i',
            ['id' => $extraRowId, 'i' => $itemId],
        );
        if ($row === null) {
            throw new NotFoundException('Extra introuvable sur cette prestation.');
        }

        $this->db->run('DELETE FROM booking_item_extras WHERE id = :id', ['id' => $extraRowId]);

        $this->refreshLineTotal($itemId);
        $this->shiftDuration($bookingId, (int) $item['job_id'], -((int) $row['unit_duration_min'] * (int) $item['quantity']));
        $this->recomputeTotals($bookingId);
    }

    /**
     * Extras rattachables à une ligne : ceux du catalogue de la prestation
     * (tarif éventuellement surchargé pour ce service), ou tous les extras
     * actifs si la ligne est sur mesure. Les extras déjà posés sont exclus.
     *
     * @param array<string, mixed> $item
     * @return list<array<string, mixed>>
     */
    public function attachableExtras(array $item): array
    {
        $serviceId = $item['service_id'] !== null ? (int) $item['service_id'] : null;

        $available = $serviceId !== null
            ? $this->catalog->serviceExtras($serviceId)
            : $this->db->select(
                'SELECT id AS extra_id, label, default_price_cents AS eff_price_cents,
                        default_duration_min AS eff_duration_min
                   FROM extras WHERE is_active = 1 ORDER BY label',
            );

        $taken = array_map(
            static fn (array $r): int => (int) $r['extra_id'],
            $this->db->select('SELECT extra_id FROM booking_item_extras WHERE booking_item_id = :i', ['i' => (int) $item['id']]),
        );

        return array_values(array_filter(
            $available,
            static fn (array $x): bool => !in_array((int) $x['extra_id'], $taken, true),
        ));
    }

    /**
     * Extras posés sur une ligne.
     *
     * @return list<array<string, mixed>>
     */
    public function extrasOf(int $itemId): array
    {
        return $this->db->select(
            'SELECT id, extra_id, label_snapshot, unit_price_cents, unit_duration_min
               FROM booking_item_extras WHERE booking_item_id = :i ORDER BY id',
            ['i' => $itemId],
        );
    }

    /**
     * Fixe la remise de la commande, en centimes, sur le sous-total.
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

        $this->db->run(
            'UPDATE bookings SET discount_cents = :d WHERE id = :id',
            ['d' => max(0, min($discountCents, $subtotal)), 'id' => $bookingId],
        );
        $this->recomputeTotals($bookingId);
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
     * Vrai si le rendez-vous, après changement de durée, chevauche un autre
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

    /**
     * Tarif d'un extra pour une ligne : surcharge du service si l'extra y est
     * rattaché, tarif par défaut de l'extra sinon (ligne sur mesure).
     *
     * @param array<string, mixed> $item
     * @return array{0:int, 1:int, 2:string}
     */
    private function extraPricing(array $item, int $extraId): array
    {
        if ($item['service_id'] !== null) {
            foreach ($this->catalog->serviceExtras((int) $item['service_id']) as $se) {
                if ((int) $se['extra_id'] === $extraId) {
                    return [(int) $se['eff_price_cents'], (int) $se['eff_duration_min'], (string) $se['label']];
                }
            }
        }

        $extra = $this->db->selectOne(
            'SELECT label, default_price_cents, default_duration_min FROM extras WHERE id = :id AND is_active = 1',
            ['id' => $extraId],
        );
        if ($extra === null) {
            throw new NotFoundException('Extra inconnu.');
        }

        return [(int) $extra['default_price_cents'], (int) $extra['default_duration_min'], (string) $extra['label']];
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
     * Durée d'UNE unité de la ligne : sa propre durée plus celle de ses extras.
     */
    private function unitDurationOf(int $itemId): int
    {
        $own = (int) $this->db->scalar('SELECT unit_duration_min FROM booking_items WHERE id = :id', ['id' => $itemId]);
        $extras = (int) $this->db->scalar(
            'SELECT COALESCE(SUM(unit_duration_min), 0) FROM booking_item_extras WHERE booking_item_id = :i',
            ['i' => $itemId],
        );

        return $own + $extras;
    }

    /**
     * Réaligne le total d'une ligne : (prix unitaire + extras) × quantité.
     * Même formule que BookingService à la création — les extras font partie
     * du prix de la ligne, les oublier les rendrait gratuits.
     */
    private function refreshLineTotal(int $itemId): void
    {
        $item = $this->db->selectOne('SELECT unit_price_cents, quantity FROM booking_items WHERE id = :id', ['id' => $itemId]);
        if ($item === null) {
            return;
        }
        $extras = (int) $this->db->scalar(
            'SELECT COALESCE(SUM(unit_price_cents), 0) FROM booking_item_extras WHERE booking_item_id = :i',
            ['i' => $itemId],
        );

        $this->db->run(
            'UPDATE booking_items SET line_total_cents = :t WHERE id = :id',
            ['t' => ((int) $item['unit_price_cents'] + $extras) * (int) $item['quantity'], 'id' => $itemId],
        );
    }

    /**
     * Applique un écart de durée au rendez-vous et à la commande, et décale la
     * fin planifiée. Sans cela, le moteur de disponibilité continuerait de
     * croire le technicien libre sur le temps ajouté.
     */
    private function shiftDuration(int $bookingId, int $jobId, int $deltaMin): void
    {
        if ($deltaMin === 0) {
            return;
        }

        $job = $this->db->selectOne(
            'SELECT mode, scheduled_start, active_duration_min, occupancy_duration_min FROM jobs WHERE id = :id',
            ['id' => $jobId],
        );
        if ($job === null) {
            return;
        }

        $active = max(0, (int) $job['active_duration_min'] + $deltaMin);
        // À l'atelier, l'immobilisation du poste ne descend jamais sous la
        // durée de travail effective (même règle que BookingService).
        $occupancy = $job['mode'] === 'workshop'
            ? max((int) $job['occupancy_duration_min'] + $deltaMin, $active)
            : $active;

        $end = null;
        if ($job['scheduled_start'] !== null) {
            $end = (new \DateTimeImmutable((string) $job['scheduled_start'], new \DateTimeZone('UTC')))
                ->modify('+' . ($job['mode'] === 'workshop' ? $occupancy : $active) . ' minutes')
                ->format('Y-m-d H:i:s');
        }

        $this->db->run(
            'UPDATE jobs SET active_duration_min = :a, occupancy_duration_min = :o, scheduled_end = :e WHERE id = :id',
            ['a' => $active, 'o' => max(0, $occupancy), 'e' => $end, 'id' => $jobId],
        );
        $this->db->run(
            'UPDATE bookings SET total_duration_min = GREATEST(0, total_duration_min + :d) WHERE id = :id',
            ['d' => $deltaMin, 'id' => $bookingId],
        );
    }

    /**
     * Réaligne les totaux de la commande sur ses lignes.
     */
    private function recomputeTotals(int $bookingId): void
    {
        $booking = $this->db->selectOne('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId]);
        if ($booking === null) {
            return;
        }

        $subtotal = (int) $this->db->scalar(
            'SELECT COALESCE(SUM(line_total_cents), 0) FROM booking_items WHERE booking_id = :b',
            ['b' => $bookingId],
        );

        // Une remise supérieure au sous-total après retrait d'une ligne serait
        // absurde : on la ramène au plafond.
        $discount = min((int) $booking['discount_cents'], $subtotal);
        $net = max(0, $subtotal - $discount + (int) $booking['travel_surcharge_cents']);
        $vat = Money::vat($net, (int) $booking['vat_rate_bp']);

        $this->db->run(
            'UPDATE bookings SET subtotal_cents = :s, discount_cents = :d, vat_cents = :v, total_cents = :t WHERE id = :id',
            ['s' => $subtotal, 'd' => $discount, 'v' => $vat, 't' => $net + $vat, 'id' => $bookingId],
        );
    }
}
