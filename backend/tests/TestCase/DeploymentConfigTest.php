<?php
declare(strict_types=1);

namespace App\Test\TestCase;

use Cake\TestSuite\TestCase;

/**
 * The Railway deployment files (DEPLOYMENT.md §5–§6). Railway reads
 * `railway.json` for both services: the API and the `retention` cron
 * service share it, because Railway ignores a custom config file on a
 * service created after Config as Code was deprecated (found 2026-10-18;
 * `railway.retention.json` was deleted). The retention schedule lives in
 * the dashboard, and the job protects itself: it waits for the database
 * and runs once per hotel day. Config as Code stops being read on
 * 2026-12-01 (docs/BACKLOG.md).
 */
class DeploymentConfigTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function config(string $file): array
    {
        $json = (string)file_get_contents(ROOT . DS . $file);
        $this->assertNotSame('', $json, "$file is missing");
        $config = json_decode($json, true);
        $this->assertIsArray($config, "$file is not valid JSON");

        return $config;
    }

    private function jobBranch(): string
    {
        $entrypoint = (string)file_get_contents(ROOT . DS . 'docker' . DS . 'entrypoint.sh');
        $start = strpos($entrypoint, 'if [ "${STAYVANTA_JOB:-}" = "retention" ]; then');
        $this->assertNotFalse($start, 'the job branch exists');
        $end = strpos($entrypoint, "\nfi\n", $start);
        $this->assertNotFalse($end);
        $migrate = strpos($entrypoint, 'migrations migrate');
        $this->assertNotFalse($migrate);
        $this->assertLessThan($migrate, $end, 'the job exits before any migration or web server');

        return substr($entrypoint, $start, $end - $start);
    }

    public function testTheSharedConfigCarriesNothingServiceSpecific(): void
    {
        // Both services read this file, and code values override the
        // dashboard: a schedule here would give the API a cron schedule and
        // override the retention service's.
        $deploy = $this->config('railway.json')['deploy'];
        $this->assertArrayNotHasKey('cronSchedule', $deploy);
        $this->assertArrayNotHasKey('startCommand', $deploy, 'the entrypoint decides (STAYVANTA_JOB)');
        $this->assertArrayNotHasKey('healthcheckPath', $deploy, 'the job has no web server');
        $this->assertFileDoesNotExist(ROOT . DS . 'railway.retention.json', 'Railway ignores it for the retention service');
    }

    public function testTheJobBranchWaitsForTheDatabaseThenRunsOnlyTheRoutine(): void
    {
        $job = $this->jobBranch();
        $wait = strpos($job, 'php bin/cake.php db_wait --timeout 90 --interval 3 || exit 1');
        $run = strpos($job, 'exec php bin/cake.php retention');
        $this->assertNotFalse($wait, 'the job waits 90 s for the database and stops when it never answers');
        $this->assertNotFalse($run);
        $this->assertLessThan($run, $wait, 'the wait comes first');
        $this->assertSame(1, substr_count($job, 'bin/cake.php retention'), 'the routine runs once');
        $this->assertStringNotContainsString('|| true', $job, 'never run the routine without a database');
        $this->assertStringNotContainsString('--dry-run', $job);
    }
}
