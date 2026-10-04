<?php
declare(strict_types=1);

namespace App\Command;

use App\Model\Finance\Restatement;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * `bin/cake cash_restatement [--property N]`
 *
 * Read-only: prints (and logs) what switching Collected to cash movement
 * (build step 7c-2) changes per property: every changed day and month with
 * its figures before and after, the all-time invariant, and the cancelled
 * paid POS sales that stay collected. Changes nothing. The deployment runs it
 * once through the ReportCashRestatement migration.
 */
class CashRestatementCommand extends Command
{
    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Report what the cash-movement model changes (read-only).')
            ->addOption('property', ['help' => 'Only this property id']);
    }

    /**
     * @param \Cake\Console\Arguments $args The arguments.
     * @param \Cake\Console\ConsoleIo $io The console.
     * @return int
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $propertyId = $args->getOption('property') !== null ? (int)$args->getOption('property') : null;
        $failed = 0;
        foreach (Restatement::logAll($propertyId) as $id => $report) {
            foreach (['days' => 'day', 'months' => 'month'] as $key => $label) {
                foreach ($report[$key] as $r) {
                    if ($r['changes']) {
                        $io->out(sprintf(
                            'property %d %-5s %-10s before %12.2f | collected %12.2f refunded %10.2f net %12.2f',
                            $id,
                            $label,
                            $r['period'],
                            $r['before'],
                            $r['collected'],
                            $r['refunded'],
                            $r['net'],
                        ));
                    }
                }
            }
            $holds = $report['all_time']['holds'];
            $failed += $holds ? 0 : 1;
            $io->out(sprintf(
                'property %d: all time %.2f before, net %.2f after (%s); '
                . '%d cancelled paid POS sale(s), %.2f, stay collected',
                $id,
                $report['all_time']['before'],
                $report['all_time']['net_after'],
                $holds ? 'invariant holds' : 'INVARIANT FAILED',
                $report['pos_cancelled_paid']['count'],
                $report['pos_cancelled_paid']['total'],
            ));
        }

        return $failed === 0 ? static::CODE_SUCCESS : static::CODE_ERROR;
    }
}
