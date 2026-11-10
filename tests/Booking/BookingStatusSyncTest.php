<?php

declare(strict_types=1);

namespace Keepnew\Tests\Booking;

use Keepnew\Booking\BookingService;
use Keepnew\Core\Database;
use PHPUnit\Framework\TestCase;

/**
 * Statut de commande déduit de ses rendez-vous.
 *
 * Sur SQLite en mémoire : aucune base externe requise, le test tourne donc
 * TOUJOURS, contrairement à BookingFlowTest qui s'ignore sans KN_TEST_DSN.
 * C'est volontaire — la règle vérifiée ici conditionne la facturation
 * automatique, elle ne doit pas dépendre d'un environnement configuré.
 */
final class BookingStatusSyncTest extends TestCase
{
    private Database $db;
    private BookingService $bookings;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('CREATE TABLE bookings (id INTEGER PRIMARY KEY, status TEXT)');
        $pdo->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INT, status TEXT)');
        $pdo->exec(
            'CREATE TABLE booking_status_history (id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INT,
                 job_id INT, old_status TEXT, new_status TEXT, changed_by INT, note TEXT, created_at TEXT)',
        );

        $this->db = new Database($pdo);
        // Seules $db et les dépendances réellement utilisées par
        // syncStatusFromJobs() sont nécessaires : la méthode ne touche que la
        // base, on évite donc de monter tout le graphe de la commande.
        $this->bookings = (new \ReflectionClass(BookingService::class))->newInstanceWithoutConstructor();
        $prop = new \ReflectionProperty(BookingService::class, 'db');
        $prop->setAccessible(true);
        $prop->setValue($this->bookings, $this->db);
    }

    /**
     * @param list<string> $jobStatuses
     */
    private function given(string $bookingStatus, array $jobStatuses): void
    {
        $this->db->run('INSERT INTO bookings (id, status) VALUES (1, :s)', ['s' => $bookingStatus]);
        foreach ($jobStatuses as $s) {
            $this->db->run('INSERT INTO jobs (booking_id, status) VALUES (1, :s)', ['s' => $s]);
        }
    }

    private function statusAfterSync(): string
    {
        $this->bookings->syncStatusFromJobs(1, 7);

        return (string) $this->db->scalar('SELECT status FROM bookings WHERE id = 1');
    }

    public function testAllJobsCompletedCompletesTheBooking(): void
    {
        $this->given('confirmed', ['completed', 'completed']);
        self::assertSame('completed', $this->statusAfterSync());
    }

    public function testCancelledJobsAreIgnoredWhenJudgingCompletion(): void
    {
        $this->given('confirmed', ['completed', 'cancelled']);
        self::assertSame('completed', $this->statusAfterSync());
    }

    public function testAJobInProgressPutsTheBookingInProgress(): void
    {
        $this->given('confirmed', ['in_progress', 'scheduled']);
        self::assertSame('in_progress', $this->statusAfterSync());
    }

    public function testEnRouteCountsAsInProgress(): void
    {
        $this->given('confirmed', ['en_route']);
        self::assertSame('in_progress', $this->statusAfterSync());
    }

    public function testScheduledJobsLeaveTheBookingConfirmed(): void
    {
        $this->given('in_progress', ['scheduled']);
        self::assertSame('confirmed', $this->statusAfterSync());
    }

    /**
     * L'annulation est une décision, pas un état dérivé : elle ne doit jamais
     * être défaite par la complétion d'un rendez-vous resté ouvert.
     */
    public function testACancelledBookingIsNeverRevived(): void
    {
        $this->given('cancelled', ['completed']);
        self::assertSame('cancelled', $this->statusAfterSync());
    }

    public function testAllJobsCancelledLeavesTheBookingAlone(): void
    {
        $this->given('confirmed', ['cancelled', 'cancelled']);
        self::assertSame('confirmed', $this->statusAfterSync());
    }

    /**
     * Le cron lit cette trace pour appliquer son délai de grâce avant
     * facturation : sans elle, une commande terminée ne serait jamais facturée.
     */
    public function testTheTransitionIsRecordedInHistory(): void
    {
        $this->given('confirmed', ['completed']);
        $this->bookings->syncStatusFromJobs(1, 7);

        $row = $this->db->selectOne(
            "SELECT old_status, new_status, changed_by FROM booking_status_history
              WHERE booking_id = 1 AND new_status = 'completed'",
        );

        self::assertNotNull($row);
        self::assertSame('confirmed', $row['old_status']);
        self::assertSame(7, (int) $row['changed_by']);
    }

    public function testNoHistoryRowWhenTheStatusDoesNotChange(): void
    {
        $this->given('completed', ['completed']);
        $this->bookings->syncStatusFromJobs(1, 7);

        self::assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM booking_status_history'));
    }
}
