<?php

declare(strict_types=1);

namespace Keepnew\Availability;

use Keepnew\Support\Clock;

/**
 * Un créneau réservable proposé au client.
 *
 * Domicile : fenêtre d'arrivée = [start, start + arrivalWindowMin]. Atelier :
 * heure de dépôt = start, reprise estimée = pickupAt.
 */
final readonly class TimeSlot
{
    public function __construct(
        public \DateTimeImmutable $start,          // UTC
        public \DateTimeImmutable $end,            // UTC (fin du travail actif)
        public int $technicianId,
        public string $mode,                       // onsite | workshop
        public ?int $bayId = null,                 // atelier
        public ?\DateTimeImmutable $pickupAt = null, // atelier : reprise estimée
        public ?int $travelInMin = null,
        public ?int $travelOutMin = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'start_utc' => $this->start->format(DATE_ATOM),
            'end_utc' => $this->end->format(DATE_ATOM),
            'start_local' => Clock::format($this->start, 'd/m/Y H:i'),
            'end_local' => Clock::format($this->end, 'H:i'),
            'technician_id' => $this->technicianId,
            'mode' => $this->mode,
            'bay_id' => $this->bayId,
            'pickup_local' => $this->pickupAt !== null ? Clock::format($this->pickupAt, 'H:i') : null,
            'travel_in_min' => $this->travelInMin,
            'travel_out_min' => $this->travelOutMin,
        ];
    }
}
