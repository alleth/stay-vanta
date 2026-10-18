<?php
declare(strict_types=1);

namespace App\Test\TestCase\Database;

use App\Database\ConnectionWait;
use Cake\TestSuite\TestCase;
use LogicException;
use RuntimeException;

/**
 * The database wait before the retention job (approved 2026-10-18): tries
 * until the database answers or the wall-clock deadline passes, sleeps only
 * between failed attempts and never past the deadline, logs one line per
 * failed attempt and never throws. A fake clock keeps it instant.
 */
class ConnectionWaitTest extends TestCase
{
    private float $now = 0.0;
    /**
     * @var list<float>
     */
    private array $slept = [];
    /**
     * @var list<string>
     */
    private array $lines = [];
    /**
     * @var list<float>
     */
    private array $offered = [];

    /**
     * @param int $failures Attempts that fail before one answers (PHP_INT_MAX: never answers).
     * @param float $cost Seconds each attempt takes.
     */
    private function waiter(int $failures, float $cost = 0.0): ConnectionWait
    {
        $calls = 0;

        return new ConnectionWait(
            function (float $left) use (&$calls, $failures, $cost): void {
                $this->offered[] = $left;
                $this->now += $cost;
                if ($calls++ < $failures) {
                    throw new LogicException('Connection to Mysql could not be established', 0, new RuntimeException("SQLSTATE[HY000] [2006] MySQL server has gone away\n#0 trace"));
                }
            },
            fn(): float => $this->now,
            function (float $seconds): void {
                $this->slept[] = $seconds;
                $this->now += $seconds;
            },
            function (string $line): void {
                $this->lines[] = $line;
            },
        );
    }

    public function testADatabaseThatAnswersAtOnceIsNotWaitedFor(): void
    {
        $result = $this->waiter(0)->wait(90, 3);

        $this->assertTrue($result['ready']);
        $this->assertSame(1, $result['attempts']);
        $this->assertSame([], $this->slept);
        $this->assertSame([], $this->lines);
    }

    public function testAWakingDatabaseIsWaitedForAndEachFailureIsOneLine(): void
    {
        $result = $this->waiter(2)->wait(90, 3);

        $this->assertTrue($result['ready']);
        $this->assertSame(3, $result['attempts']);
        $this->assertSame([3.0, 3.0], $this->slept);
        $this->assertSame([
            'not ready (attempt 1): SQLSTATE[HY000] [2006] MySQL server has gone away',
            'not ready (attempt 2): SQLSTATE[HY000] [2006] MySQL server has gone away',
        ], $this->lines, 'the innermost cause, one line, no trace');
        $this->assertEqualsWithDelta(6.0, $result['seconds'], 0.001);
    }

    public function testADatabaseThatNeverAnswersIsGivenUpAtTheDeadline(): void
    {
        $result = $this->waiter(PHP_INT_MAX)->wait(9, 3);

        $this->assertFalse($result['ready']);
        $this->assertSame(4, $result['attempts'], 'at 0, 3, 6 and 9 seconds');
        $this->assertSame([3.0, 3.0, 3.0], $this->slept);
        $this->assertLessThanOrEqual(9.0, array_sum($this->slept), 'never sleeps past the deadline');
        $this->assertSame('SQLSTATE[HY000] [2006] MySQL server has gone away', $result['error']);
    }

    public function testASlowAttemptShortensTheLastPauseAndIsOfferedOnlyTheTimeLeft(): void
    {
        $result = $this->waiter(PHP_INT_MAX, 5.0)->wait(9, 3);

        $this->assertFalse($result['ready']);
        $this->assertSame(2, $result['attempts']);
        $this->assertSame([3.0], $this->slept, '5 s attempt, 3 s pause, then 1 s left');
        $this->assertSame([9.0, 1.0], $this->offered, 'the probe caps its connect timeout to what is left');
    }

    public function testThePauseShrinksToFitTheDeadline(): void
    {
        $this->waiter(PHP_INT_MAX)->wait(4, 3);

        $this->assertSame([3.0, 1.0], $this->slept);
    }
}
