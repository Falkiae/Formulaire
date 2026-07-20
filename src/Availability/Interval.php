<?php

declare(strict_types=1);

namespace Keepnew\Availability;

/**
 * Intervalle temporel [start, end[ en UTC. Semi-ouvert à droite : deux
 * intervalles qui se touchent (fin de l'un = début de l'autre) NE se
 * chevauchent PAS.
 */
final readonly class Interval
{
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
    }

    public function overlaps(Interval $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    public function contains(Interval $other): bool
    {
        return $other->start >= $this->start && $other->end <= $this->end;
    }

    public function durationMinutes(): int
    {
        return (int) (($this->end->getTimestamp() - $this->start->getTimestamp()) / 60);
    }

    public function withEnd(\DateTimeImmutable $end): self
    {
        return new self($this->start, $end);
    }
}
