<?php
declare(strict_types=1);

namespace App\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Database\Connection;
use Cake\Database\Driver\Mysql;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * `bin/cake db_wait` (retention cron, approved 2026-10-18): answers at once
 * against a running database, and gives up with a non-zero exit, one line
 * and no stack trace when nothing answers by the deadline.
 */
class DbWaitCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    private const UNREACHABLE = 'db_wait_unreachable';

    protected function tearDown(): void
    {
        ConnectionManager::drop(self::UNREACHABLE);
        parent::tearDown();
    }

    public function testARunningDatabaseIsReadyAtOnce(): void
    {
        $this->exec('db_wait --timeout 10 --interval 1');

        $this->assertExitSuccess();
        $this->assertOutputContains('db_wait: ready after');
        $this->assertOutputContains('(1 attempt(s))');
    }

    public function testNothingAnsweringIsGivenUpWithinTheBudget(): void
    {
        ConnectionManager::setConfig(self::UNREACHABLE, [
            'className' => Connection::class,
            'driver' => Mysql::class,
            'host' => '127.0.0.1',
            'port' => '1',
            'username' => 'nobody',
            'password' => 'nothing',
            'database' => 'nothing',
        ]);
        $started = microtime(true);

        $this->exec('db_wait --connection ' . self::UNREACHABLE . ' --timeout 2 --interval 1');

        $this->assertExitError();
        $this->assertErrorContains('db_wait: gave up after');
        $this->assertOutputContains('db_wait: not ready (attempt 1)');
        $this->assertOutputNotContains('#0 ', 'no stack trace');
        $this->assertLessThan(10, microtime(true) - $started, 'the budget holds');
    }

    public function testTheBudgetMustBePositive(): void
    {
        $this->exec('db_wait --timeout 0');

        $this->assertExitError();
        $this->assertErrorContains('must be positive');
    }
}
