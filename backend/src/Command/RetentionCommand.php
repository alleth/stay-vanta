<?php
declare(strict_types=1);

namespace App\Command;

use App\Privacy\RetentionRoutine;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Datasource\ConnectionManager;

/**
 * `bin/cake retention [--dry-run] [--property N]`
 *
 * The retention routine (final review G5): clears personal values past their
 * retention period (sign-in device details after 12 months, guest contact
 * values in guest history after 24), keeping every event, then prints the
 * check. Run daily by a Railway cron job; each deploy runs it with
 * `--dry-run` and logs what would be cleared. Every run is recorded in
 * retention_runs. Exits non-zero when the check finds values past their
 * cutoff (after a real run there should be none).
 */
class RetentionCommand extends Command
{
    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Clear personal values past their retention period; events are kept.')
            ->addOption('dry-run', ['boolean' => true, 'help' => 'Count what would be cleared; change nothing'])
            ->addOption('property', ['help' => 'Only this property id']);
    }

    /**
     * @param \Cake\Console\Arguments $args The arguments.
     * @param \Cake\Console\ConsoleIo $io The console.
     * @return int
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('default');
        $routine = new RetentionRoutine($connection);
        $propertyId = $args->getOption('property') !== null ? (int)$args->getOption('property') : null;
        $dryRun = (bool)$args->getOption('dry-run');

        foreach ($routine->run($dryRun, $propertyId) as $policy => $r) {
            $io->out(sprintf(
                '%-24s %d row(s) %s (cutoff %s)',
                $policy,
                $r['rows'],
                $dryRun ? 'would be cleared' : 'cleared',
                $r['cutoff'],
            ));
        }
        $incomplete = 0;
        foreach ($routine->check($propertyId) as $policy => $c) {
            $incomplete += $c['complete'] || $dryRun ? 0 : 1;
            $io->out(sprintf(
                '%-24s check: %d past the cutoff still holding values, %d cleared so far%s',
                $policy,
                $c['due'],
                $c['cleared'],
                $c['complete'] ? '' : ($dryRun ? '  <- due for clearing' : '  <- INCOMPLETE'),
            ));
        }

        return $incomplete === 0 ? static::CODE_SUCCESS : static::CODE_ERROR;
    }
}
