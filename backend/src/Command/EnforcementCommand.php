<?php
declare(strict_types=1);

namespace App\Command;

use App\Platform\Enforcement;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * `bin/cake enforcement [--observe]`
 *
 * The subscription-enforcement phase (final review G6): prints the phase,
 * the emergency ceiling, the phase in force, the preview (properties per
 * stage) and the self-check. With `--observe` (run by the container at
 * start-up) it first records a ceiling value the app hasn't seen before.
 * Read-only otherwise. Exits non-zero when the self-check fails.
 */
class EnforcementCommand extends Command
{
    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Show the subscription-enforcement phase and check its history.')
            ->addOption('observe', ['boolean' => true, 'help' => 'Record a new ceiling value first (start-up)']);
    }

    /**
     * @param \Cake\Console\Arguments $args The arguments.
     * @param \Cake\Console\ConsoleIo $io The console.
     * @return int
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        if ($args->getOption('observe') && Enforcement::observeCeiling()) {
            $io->out('enforcement: new ceiling observed and recorded');
        }
        $preview = Enforcement::preview();
        $io->out(sprintf(
            'enforcement: properties %d by stage: active %d, grace %d, read-only %d, suspended %d',
            $preview['properties'],
            $preview['stages']['active'],
            $preview['stages']['grace'],
            $preview['stages']['read_only'],
            $preview['stages']['suspended'],
        ));
        $check = Enforcement::check();
        $io->out(sprintf(
            'enforcement: phase %s, ceiling %s, in force %s; baseline %d, changes %d; history %s; ceiling seen %s',
            $check['phase'],
            $check['ceiling'] ?? 'none',
            $check['effective'],
            $check['baselines'],
            $check['changes'],
            $check['chain_ok'] ? 'consistent' : 'INCONSISTENT',
            $check['ceiling_seen_ok'] ? 'up to date' : 'NOT RECORDED',
        ));

        return $check['complete'] ? static::CODE_SUCCESS : static::CODE_ERROR;
    }
}
