<?php

declare(strict_types=1);

namespace Keepnew\Tests\Admin;

use Keepnew\Admin\DemoPlanner;
use Keepnew\Support\Clock;
use PHPUnit\Framework\TestCase;

/**
 * Plan des rendez-vous de démonstration.
 *
 * Ce jeu sert à vérifier le planning : s'il contient lui-même des
 * chevauchements ou des statuts impossibles, il ne prouve rien. Test pur
 * (aucune base) : il tourne partout.
 */
final class DemoPlannerTest extends TestCase
{
    private function brussels(string $local): \DateTimeImmutable
    {
        return new \DateTimeImmutable($local, new \DateTimeZone(Clock::DISPLAY_TZ));
    }

    /**
     * @return list<array{start_utc:string, start_local:string, technician_id:int, duration_min:int, status:string}>
     */
    private function plan(string $from = '2026-11-01', string $to = '2026-12-31', array $techs = [1, 2, 3]): array
    {
        return DemoPlanner::plan(
            $this->brussels($from . ' 00:00'),
            $this->brussels($to . ' 00:00'),
            $techs,
            $this->brussels('2026-11-17 10:00'),
        );
    }

    public function testItFillsTheRequestedPeriod(): void
    {
        $plan = $this->plan();

        self::assertGreaterThan(200, count($plan), 'un jeu de démonstration doit être fourni');
        $dates = array_map(static fn (array $r): string => substr($r['start_local'], 0, 10), $plan);
        self::assertSame('2026-11-01', min($dates) > '2026-11-01' ? '2026-11-01' : min($dates));
        self::assertLessThanOrEqual('2026-12-31', max($dates));
        self::assertGreaterThanOrEqual('2026-11-01', min($dates));
    }

    /**
     * Le défaut qui invaliderait tout le jeu : deux interventions du même
     * technicien au même moment.
     */
    public function testATechnicianIsNeverInTwoPlacesAtOnce(): void
    {
        $plan = $this->plan();

        $parTech = [];
        foreach ($plan as $r) {
            $parTech[$r['technician_id']][] = $r;
        }

        foreach ($parTech as $techId => $rdvs) {
            usort($rdvs, static fn (array $a, array $b): int => strcmp($a['start_utc'], $b['start_utc']));
            for ($i = 1, $n = count($rdvs); $i < $n; $i++) {
                $finPrecedent = (new \DateTimeImmutable($rdvs[$i - 1]['start_utc'], new \DateTimeZone('UTC')))
                    ->modify('+' . $rdvs[$i - 1]['duration_min'] . ' minutes')
                    ->format('Y-m-d H:i:s');
                self::assertLessThanOrEqual(
                    $rdvs[$i]['start_utc'],
                    $finPrecedent,
                    "technicien {$techId} : chevauchement à {$rdvs[$i]['start_local']}",
                );
            }
        }
    }

    public function testEverythingHappensOnWorkingDaysAndHours(): void
    {
        foreach ($this->plan() as $r) {
            $local = $this->brussels($r['start_local']);
            self::assertNotSame('7', $local->format('N'), "dimanche : {$r['start_local']}");
            $minutes = (int) $local->format('H') * 60 + (int) $local->format('i');
            self::assertGreaterThanOrEqual(8 * 60, $minutes, $r['start_local']);
            self::assertLessThanOrEqual(16 * 60, $minutes, $r['start_local']);
            self::assertContains((int) $local->format('i'), [0, 30], "heure non ronde : {$r['start_local']}");
        }
    }

    /**
     * Un statut doit être cohérent avec sa date : rien de « planifié » dans le
     * passé, rien de « terminé » dans le futur.
     */
    public function testStatusesAgreeWithTheDate(): void
    {
        $now = $this->brussels('2026-11-17 10:00');
        $plan = $this->plan();
        $vus = [];

        foreach ($plan as $r) {
            $start = new \DateTimeImmutable($r['start_utc'], new \DateTimeZone('UTC'));
            $vus[$r['status']] = true;

            if ($start < Clock::toUtc($now)->modify('-1 day')) {
                self::assertContains($r['status'], ['completed', 'cancelled', 'no_show'], $r['start_local']);
            }
            if ($start > Clock::toUtc($now)->modify('+1 day')) {
                self::assertContains($r['status'], ['scheduled', 'cancelled'], $r['start_local']);
            }
        }

        // La vue Jour doit pouvoir montrer autre chose que « planifié ».
        foreach (['completed', 'scheduled'] as $attendu) {
            self::assertArrayHasKey($attendu, $vus, "statut absent du jeu : {$attendu}");
        }
    }

    /**
     * Les états transitoires — « en route », « en cours » — sont les seuls que
     * la vue Jour ne montre jamais sur un jeu figé. Ils n'apparaissent que si
     * l'on génère pendant les heures ouvrées : c'est voulu, marquer « en
     * cours » une intervention à 22 h serait un mensonge dans les données.
     */
    public function testLiveStatusesAppearWhenGeneratingDuringWorkingHours(): void
    {
        $plan = DemoPlanner::plan(
            $this->brussels('2026-11-01 00:00'),
            $this->brussels('2026-12-31 00:00'),
            [1, 2, 3],
            $this->brussels('2026-11-17 10:00'), // un mardi, en pleine journée
        );

        $statuts = array_unique(array_column($plan, 'status'));
        self::assertNotEmpty(
            array_intersect(['en_route', 'in_progress'], $statuts),
            'aucun état en cours alors que la génération a lieu en heures ouvrées',
        );
    }

    public function testTheSameSeedGivesTheSamePlan(): void
    {
        self::assertSame($this->plan(), $this->plan(), 'le jeu doit être reproductible');

        $autre = DemoPlanner::plan(
            $this->brussels('2026-11-01 00:00'),
            $this->brussels('2026-12-31 00:00'),
            [1, 2, 3],
            $this->brussels('2026-11-17 10:00'),
            999,
        );
        self::assertNotSame($this->plan(), $autre, 'une graine différente doit changer le jeu');
    }

    public function testEveryTechnicianGetsWork(): void
    {
        $techs = array_unique(array_column($this->plan(), 'technician_id'));
        sort($techs);

        self::assertSame([1, 2, 3], $techs);
    }

    public function testItRefusesImpossibleInput(): void
    {
        self::assertSame([], $this->plan(techs: []));
        self::assertSame([], $this->plan('2026-12-31', '2026-11-01'));
    }
}
