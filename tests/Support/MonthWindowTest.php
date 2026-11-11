<?php

declare(strict_types=1);

namespace Keepnew\Tests\Support;

use Keepnew\Support\Clock;
use Keepnew\Support\MonthWindow;
use PHPUnit\Framework\TestCase;

/**
 * Fenêtre de mois du calendrier de réservation.
 *
 * Deux pièges que ce test garde : un mois « belge » ne commence pas à minuit
 * UTC et le décalage change deux fois par an ; et le mois courant ne doit
 * jamais proposer des heures déjà passées. Test pur (aucune base) : il tourne
 * partout, y compris là où les tests d'intégration sont ignorés.
 */
final class MonthWindowTest extends TestCase
{
    private function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    public function testAMonthStartsAndEndsAtBelgianMidnight(): void
    {
        $r = MonthWindow::resolve('2027-05', $this->at('2027-01-15 10:00:00'));

        self::assertSame('01/05/2027 00:00', Clock::format($r['from']));
        self::assertSame('31/05/2027 23:59', Clock::format($r['to']));
    }

    /**
     * Mars et octobre portent le changement d'heure : les bornes doivent
     * rester minuit et 23h59 LOCALES, pas un décalage figé.
     */
    public function testDaylightSavingMonthsKeepLocalMidnightBoundaries(): void
    {
        foreach (['2027-03' => '31/03/2027 23:59', '2027-10' => '31/10/2027 23:59'] as $month => $expectedEnd) {
            $r = MonthWindow::resolve($month, $this->at('2027-01-15 10:00:00'));

            self::assertSame('01/' . substr($month, 5) . '/2027 00:00', Clock::format($r['from']), $month);
            self::assertSame($expectedEnd, Clock::format($r['to']), $month);
            self::assertSame('UTC', $r['from']->getTimezone()->getName(), $month);
        }
    }

    /**
     * Le cas qui ferait proposer un rendez-vous dans le passé.
     */
    public function testTheCurrentMonthNeverStartsBeforeNow(): void
    {
        $now = $this->at('2026-11-17 13:30:00');
        $r = MonthWindow::resolve('2026-11', $now);

        self::assertSame($now->format(DATE_ATOM), $r['from']->format(DATE_ATOM));
        self::assertSame('30/11/2026 23:59', Clock::format($r['to']));
    }

    public function testAFutureMonthStartsAtItsFirstDay(): void
    {
        $r = MonthWindow::resolve('2026-12', $this->at('2026-11-17 13:30:00'));

        self::assertSame('01/12/2026 00:00', Clock::format($r['from']));
    }

    public function testAPastMonthIsBroughtBackToTheCurrentOne(): void
    {
        $now = $this->at('2026-11-17 13:30:00');
        $r = MonthWindow::resolve('2019-02', $now);

        self::assertSame('2026-11', $r['window']['month']);
        self::assertSame($now->format(DATE_ATOM), $r['from']->format(DATE_ATOM));
    }

    public function testAMonthBeyondTheHorizonIsBroughtBackToTheLastOne(): void
    {
        $r = MonthWindow::resolve('2099-01', $this->at('2026-11-17 13:30:00'));

        self::assertSame('2027-11', $r['window']['month']);
        self::assertSame('2027-11', $r['window']['last_month']);
    }

    public function testTheHorizonIsTwelveMonthsAhead(): void
    {
        $now = $this->at('2026-11-17 13:30:00');

        self::assertSame('2026-11', MonthWindow::firstMonth($now));
        self::assertSame('2027-11', MonthWindow::lastMonth($now));
    }

    /**
     * Le mois est calculé à Bruxelles, pas en UTC : le 31 octobre à 23h30 UTC,
     * il est déjà minuit et demi le 1er novembre en Belgique.
     */
    public function testTheCurrentMonthFollowsBelgianTimeNotUtc(): void
    {
        self::assertSame('2026-11', MonthWindow::firstMonth($this->at('2026-10-31 23:30:00')));
    }

    public function testOnlyAWellFormedKeyIsAccepted(): void
    {
        foreach (['2026-11', '2027-01', '2026-12'] as $ok) {
            self::assertTrue(MonthWindow::isValidKey($ok), $ok);
        }
        foreach (['2026-13', '2026-00', '2026-1', 'novembre', '2026/11', '', '2026-11-01'] as $ko) {
            self::assertFalse(MonthWindow::isValidKey($ko), $ko);
        }
    }

    /**
     * Les bornes renvoyées au client servent à activer ou non les flèches :
     * elles doivent toujours encadrer le mois affiché.
     */
    public function testTheReturnedWindowAlwaysFramesTheDisplayedMonth(): void
    {
        foreach (['2026-11', '2027-03', '2027-11'] as $month) {
            $w = MonthWindow::resolve($month, $this->at('2026-11-17 13:30:00'))['window'];

            self::assertGreaterThanOrEqual($w['first_month'], $w['month'], $month);
            self::assertLessThanOrEqual($w['last_month'], $w['month'], $month);
        }
    }
}
