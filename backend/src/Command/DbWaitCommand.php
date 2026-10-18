<?php
declare(strict_types=1);

namespace App\Command;

use App\Database\ConnectionWait;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use PDO;

/**
 * `bin/cake db_wait [--timeout 90] [--interval 3]`
 *
 * Waits until the database answers `SELECT 1` (retention cron, approved
 * 2026-10-18): the job container's entrypoint runs it before the retention
 * routine, so a sleeping staging MySQL is woken and waited for instead of
 * failing the run. Each attempt opens a fresh connection (a broken one is
 * never reused) with a short connect timeout, and logs one line, not a stack
 * trace. Exits non-zero when the database hasn't answered by the deadline.
 * Reads and writes no application table.
 */
class DbWaitCommand extends Command
{
    /** Longest connect timeout of one attempt, so the budget holds. */
    private const CONNECT_TIMEOUT = 5;

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Wait until the database answers; exit non-zero after the timeout.')
            ->addOption('timeout', ['default' => '90', 'help' => 'Wall-clock budget in seconds'])
            ->addOption('interval', ['default' => '3', 'help' => 'Seconds between attempts'])
            ->addOption('connection', ['default' => 'default', 'help' => 'Connection name (tests use another)']);
    }

    /**
     * @param \Cake\Console\Arguments $args The arguments.
     * @param \Cake\Console\ConsoleIo $io The console.
     * @return int
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $timeout = (float)$args->getOption('timeout');
        $interval = (float)$args->getOption('interval');
        if ($timeout <= 0 || $interval <= 0) {
            $io->error('--timeout and --interval must be positive.');

            return static::CODE_ERROR;
        }
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get((string)$args->getOption('connection'));
        $config = $connection->config();

        $probe = function (float $left) use ($config): void {
            $flags = (array)($config['flags'] ?? []);
            $flags[PDO::ATTR_TIMEOUT] = max(1, min(self::CONNECT_TIMEOUT, (int)ceil($left)));
            $attempt = new Connection(['flags' => $flags] + $config);
            // A waking server also raises PHP warnings ("Error while reading
            // greeting packet"); the exception says the same, once.
            set_error_handler(fn(): bool => true);
            try {
                $attempt->execute('SELECT 1')->fetch();
            } finally {
                restore_error_handler();
                $attempt->getDriver()->disconnect();
            }
        };
        $wait = new ConnectionWait(
            $probe,
            fn(): float => hrtime(true) / 1e9,
            function (float $seconds): void {
                usleep((int)round($seconds * 1e6));
            },
            fn(string $line) => $io->out('db_wait: ' . $line),
        );

        $result = $wait->wait($timeout, $interval);
        if ($result['ready']) {
            $io->out(sprintf('db_wait: ready after %.1fs (%d attempt(s))', $result['seconds'], $result['attempts']));

            return static::CODE_SUCCESS;
        }
        $line = sprintf(
            'db_wait: gave up after %.1fs (%d attempt(s)): %s',
            $result['seconds'],
            $result['attempts'],
            (string)$result['error'],
        );
        Log::error($line);
        $io->err($line);

        return static::CODE_ERROR;
    }
}
