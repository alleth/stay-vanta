<?php
declare(strict_types=1);

namespace App\Database;

use Closure;
use Throwable;

/**
 * Waits for the database to answer before a job starts (retention cron,
 * approved 2026-10-18): a sleeping Railway MySQL accepts the connection while
 * it wakes and fails the handshake for a few seconds, which made the first
 * staging deploy fail and restart. Tries the probe until it answers or the
 * wall-clock deadline passes. Never throws, and writes nothing.
 */
final class ConnectionWait
{
    /**
     * @param \Closure(float): void $probe Answers or throws; given the seconds left, to cap its own connect timeout.
     * @param \Closure(): float $clock Monotonic seconds.
     * @param \Closure(float): void $sleep Sleeps that many seconds.
     * @param \Closure(string): void $log One line per failed attempt.
     */
    public function __construct(
        private Closure $probe,
        private Closure $clock,
        private Closure $sleep,
        private Closure $log,
    ) {
    }

    /**
     * @param float $timeout Wall-clock budget in seconds.
     * @param float $interval Pause between attempts in seconds (shortened to fit the deadline).
     * @return array{ready: bool, attempts: int, seconds: float, error: string|null}
     */
    public function wait(float $timeout, float $interval): array
    {
        $start = ($this->clock)();
        $deadline = $start + $timeout;
        $attempts = 0;
        while (true) {
            $attempts++;
            try {
                ($this->probe)(max(0.0, $deadline - ($this->clock)()));

                return $this->result(true, $attempts, $start, null);
            } catch (Throwable $e) {
                $error = self::firstLine($e);
            }
            $left = $deadline - ($this->clock)();
            if ($left <= 0) {
                return $this->result(false, $attempts, $start, $error);
            }
            ($this->log)(sprintf('not ready (attempt %d): %s', $attempts, $error));
            ($this->sleep)(min($interval, $left));
        }
    }

    /**
     * @return array{ready: bool, attempts: int, seconds: float, error: string|null}
     */
    private function result(bool $ready, int $attempts, float $start, ?string $error): array
    {
        return ['ready' => $ready, 'attempts' => $attempts, 'seconds' => ($this->clock)() - $start, 'error' => $error];
    }

    /**
     * The innermost cause's message, one line ("MySQL server has gone away"),
     * instead of a stack trace per attempt.
     */
    private static function firstLine(Throwable $e): string
    {
        while ($e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }

        return trim(strtok($e->getMessage(), "\n") ?: get_class($e));
    }
}
