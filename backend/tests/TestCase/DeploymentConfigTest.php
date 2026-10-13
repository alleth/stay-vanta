<?php
declare(strict_types=1);

namespace App\Test\TestCase;

use Cake\TestSuite\TestCase;

/**
 * The Railway config files (DEPLOYMENT.md §5–§6). The `retention` cron
 * service has its own file (approved 2026-10-13 as R1): the same image as the
 * API, the daily schedule, and no automatic retries, so a failed run shows
 * as one failed run with one log and records no extra retention_runs rows.
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

    public function testTheRetentionServiceNeverRetriesAndRunsDaily(): void
    {
        $config = $this->config('railway.retention.json');
        $this->assertSame('NEVER', $config['deploy']['restartPolicyType']);
        $this->assertSame('0 19 * * *', $config['deploy']['cronSchedule'], '19:00 UTC = 03:00 Manila');
        $this->assertArrayNotHasKey('startCommand', $config['deploy'], 'the entrypoint decides (STAYVANTA_JOB)');
        $this->assertArrayNotHasKey('healthcheckPath', $config['deploy'], 'the job has no web server');
    }

    public function testTheRetentionServiceBuildsTheSameImageAsTheApi(): void
    {
        $this->assertSame(
            $this->config('railway.json')['build'],
            $this->config('railway.retention.json')['build'],
        );
    }

    public function testTheJobBranchRunsOnlyTheRoutine(): void
    {
        $entrypoint = (string)file_get_contents(ROOT . DS . 'docker' . DS . 'entrypoint.sh');
        $job = strpos($entrypoint, '"${STAYVANTA_JOB:-}" = "retention"');
        $migrate = strpos($entrypoint, 'migrations migrate');
        $this->assertNotFalse($job);
        $this->assertNotFalse($migrate);
        $this->assertLessThan($migrate, $job, 'the job exits before any migration or web server');
        $this->assertStringContainsString('exec php bin/cake.php retention', $entrypoint);
    }
}
