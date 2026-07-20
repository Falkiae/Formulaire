<?php

declare(strict_types=1);

namespace Keepnew\Tests\Availability;

use Keepnew\Availability\AvailabilityEngine;
use Keepnew\Availability\BayContext;
use Keepnew\Availability\BusyBlock;
use Keepnew\Availability\EngineConfig;
use Keepnew\Availability\Interval;
use Keepnew\Availability\JobDraft;
use Keepnew\Availability\ScheduleBuilder;
use Keepnew\Availability\TechnicianContext;
use Keepnew\Geo\GeoPoint;
use Keepnew\Geo\GeoProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests du moteur de disponibilité sur les cas limites imposés : chevauchement,
 * trajet trop long, congé (retrait de fenêtre), réservation concurrente (hold),
 * changement d'heure d'été, plus la contrainte poste+technicien en atelier.
 */
final class AvailabilityEngineTest extends TestCase
{
    private const DATE = '2026-08-03'; // lundi, été (UTC+2)

    private \DateTimeZone $utc;
    private \DateTimeImmutable $now;
    private GeoPoint $home;
    private GeoPoint $jobA;
    private GeoPoint $far;
    private EngineConfig $cfg;

    protected function setUp(): void
    {
        $this->utc = new \DateTimeZone('UTC');
        $this->now = new \DateTimeImmutable('2026-07-01 00:00:00', $this->utc);
        $this->home = new GeoPoint(50.6326, 5.5797, '4000');
        $this->jobA = new GeoPoint(50.6400, 5.5700, '4000');
        $this->far = new GeoPoint(50.5900, 5.8600, '4800');
        $this->cfg = new EngineConfig(30, 24, 90, 15, 360, 120, 100);
    }

    private function stubGeo(array $map): GeoProviderInterface
    {
        return new class ($map) implements GeoProviderInterface {
            public function __construct(private array $map)
            {
            }

            public function name(): string
            {
                return 'stub';
            }

            public function travelSeconds(GeoPoint $a, GeoPoint $b): ?int
            {
                $k = "{$a->lat},{$a->lng}>{$b->lat},{$b->lng}";

                return isset($this->map[$k]) ? $this->map[$k] * 60 : 0;
            }
        };
    }

    private function win(string $s, string $end): Interval
    {
        return ScheduleBuilder::windowForDate(self::DATE, $s, $end);
    }

    private function range(): array
    {
        return [
            new \DateTimeImmutable(self::DATE . ' 00:00', $this->utc),
            new \DateTimeImmutable(self::DATE . ' 23:59', $this->utc),
        ];
    }

    public function testDstWindowConversion(): void
    {
        self::assertSame('08:00', ScheduleBuilder::windowForDate('2026-01-12', '09:00', '10:00')->start->format('H:i')); // UTC+1
        self::assertSame('07:00', ScheduleBuilder::windowForDate('2026-08-03', '09:00', '10:00')->start->format('H:i')); // UTC+2
        self::assertSame('07:00', ScheduleBuilder::windowForDate('2026-03-29', '09:00', '17:00')->start->format('H:i')); // jour de bascule
    }

    public function testTimeOffRemovesAvailability(): void
    {
        $window = $this->win('08:00', '17:00');
        self::assertCount(2, ScheduleBuilder::subtract($window, $this->win('12:00', '13:00')));
        self::assertSame([], ScheduleBuilder::subtract($window, $this->win('07:00', '18:00')));
    }

    public function testOverlapRejected(): void
    {
        $engine = new AvailabilityEngine($this->cfg, $this->stubGeo([]));
        $busy = $this->win('09:00', '11:00');
        $tech = new TechnicianContext(1, [1], $this->home, [self::DATE => [$this->win('08:00', '17:00')]], [self::DATE => [new BusyBlock($busy, $this->jobA)]]);
        $job = new JobDraft('onsite', 60, 0, [1], $this->jobA);

        [$from, $to] = $this->range();
        $slots = $engine->onsiteSlots($job, [$tech], $from, $to, $this->now);

        self::assertNotEmpty($slots);
        foreach ($slots as $s) {
            self::assertFalse((new Interval($s->start, $s->end))->overlaps($busy));
        }
    }

    public function testTravelTooLongRejectsNearSlots(): void
    {
        $geo = $this->stubGeo(["{$this->far->lat},{$this->far->lng}>{$this->jobA->lat},{$this->jobA->lng}" => 40]);
        $engine = new AvailabilityEngine($this->cfg, $geo);
        $busyFar = $this->win('08:00', '10:00');
        $tech = new TechnicianContext(2, [1], $this->home, [self::DATE => [$this->win('08:00', '13:00')]], [self::DATE => [new BusyBlock($busyFar, $this->far)]]);
        $job = new JobDraft('onsite', 60, 0, [1], $this->jobA);

        [$from, $to] = $this->range();
        $slots = $engine->onsiteSlots($job, [$tech], $from, $to, $this->now);

        $earliest = $slots[0]->start ?? null;
        self::assertNotNull($earliest);
        // Fin du job à 10:00 local (08:00 UTC) + 40 min trajet → pas avant 08:40 UTC.
        $limit = $this->win('10:00', '11:00')->start->modify('+40 minutes');
        self::assertGreaterThanOrEqual($limit, $earliest);
    }

    public function testConcurrentHoldRespected(): void
    {
        $engine = new AvailabilityEngine($this->cfg, $this->stubGeo([]));
        $hold = $this->win('14:00', '15:00');
        $tech = new TechnicianContext(3, [1], $this->home, [self::DATE => [$this->win('08:00', '17:00')]], [self::DATE => [new BusyBlock($hold, $this->jobA)]]);
        $job = new JobDraft('onsite', 60, 0, [1], $this->jobA);

        [$from, $to] = $this->range();
        foreach ($engine->onsiteSlots($job, [$tech], $from, $to, $this->now) as $s) {
            self::assertFalse((new Interval($s->start, $s->end))->overlaps($hold));
        }
    }

    public function testWorkshopNeedsBayAndTechnician(): void
    {
        $engine = new AvailabilityEngine($this->cfg, $this->stubGeo([]));
        $job = new JobDraft('workshop', 75, 195, [3], null);
        $window = $this->win('08:00', '18:00');
        $tech = new TechnicianContext(4, [3], $this->home, [self::DATE => [$window]], [self::DATE => []]);

        [$from, $to] = $this->range();

        // Poste libre → au moins un créneau, avec reprise = dépôt + 195 min.
        $slots = $engine->workshopSlots($job, [$tech], [new BayContext(1, [self::DATE => []])], $from, $to, $this->now);
        self::assertNotEmpty($slots);
        self::assertSame(1, $slots[0]->bayId);
        self::assertSame(195, (int) (($slots[0]->pickupAt->getTimestamp() - $slots[0]->start->getTimestamp()) / 60));

        // Poste occupé toute la journée → aucun créneau.
        $slotsNoBay = $engine->workshopSlots($job, [$tech], [new BayContext(1, [self::DATE => [$window]])], $from, $to, $this->now);
        self::assertSame([], $slotsNoBay);
    }
}
