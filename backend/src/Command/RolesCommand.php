<?php
declare(strict_types=1);

namespace App\Command;

use App\Auth\RoleDefinitions;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Datasource\ConnectionManager;

/**
 * `bin/cake roles [--observe]`
 *
 * Role definitions (final review G7): the read-only drift self-check (the
 * data equals the code for each preset in both directions, and the history
 * replays to the grants). With `--observe` (run by the container at
 * start-up) it first records a change to the Platform Owner's code-defined
 * grants (R-D5). Drift is reported, never fixed. Exits non-zero on drift.
 */
class RolesCommand extends Command
{
    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Check role definitions against the code and their history (read-only).')
            ->addOption('observe', [
                'boolean' => true,
                'help' => 'Record a change to the Platform Owner\'s code grants first (start-up)',
            ]);
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
        $definitions = new RoleDefinitions($connection);
        if ($args->getOption('observe')) {
            $recorded = $definitions->observePlatform();
            if ($recorded > 0) {
                $io->out("roles: $recorded change(s) to the Platform Owner's grants recorded");
            }
        }
        // What a role change made by this release would record as its commit.
        $io->out('roles: release commit ' . (RoleDefinitions::commit() ?? 'not reported by the platform'));
        $check = $definitions->check();
        foreach ($check['roles'] as $code => $count) {
            $io->out(sprintf('roles: %-13s %d grant(s)', $code, $count));
        }
        $io->out(sprintf('roles: %d custom role(s), each checked against its history', $check['custom_roles'] ?? 0));
        $io->out($check['complete']
            ? sprintf('roles: data, code and history agree (%d event(s))', $check['events'] ?? 0)
            : 'roles: DRIFT: ' . implode('; ', $check['warnings']));

        return $check['complete'] ? static::CODE_SUCCESS : static::CODE_ERROR;
    }
}
