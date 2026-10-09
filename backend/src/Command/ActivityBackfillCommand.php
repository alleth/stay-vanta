<?php
declare(strict_types=1);

namespace App\Command;

use App\Event\AccessBackfill;
use App\Event\ActivityBackfill;
use App\Event\ConfigBaseline;
use App\Event\GuestHistoryImport;
use App\Event\InventoryLinkReport;
use App\Event\MembershipImport;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Datasource\ConnectionManager;

/**
 * `bin/cake activity_backfill [--property N] [--check-only]`
 *
 * Re-runs the activity_index backfill (safe any number of times: it only adds
 * what's missing) and prints the per-property check. The deployment runs it
 * once through the BackfillActivityIndex migration.
 */
class ActivityBackfillCommand extends Command
{
    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Index stock movements and sales that predate the event foundation.')
            ->addOption('property', ['help' => 'Only this property id'])
            ->addOption('check-only', ['boolean' => true, 'help' => 'Report counts without inserting']);
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
        $backfill = new ActivityBackfill($connection);
        $propertyId = $args->getOption('property') !== null ? (int)$args->getOption('property') : null;

        if (!$args->getOption('check-only')) {
            foreach ($backfill->run($propertyId) as $what => $count) {
                $io->out(sprintf('%-24s %d added', $what, $count));
            }
        }
        $incomplete = 0;
        foreach ($backfill->check($propertyId) as $id => $c) {
            $ok = (bool)$c['complete'];
            $incomplete += $ok ? 0 : 1;
            $io->out(sprintf(
                'property %d: stock %d/%d indexed, orders %d/%d placed and %d indexed; invoices %d (%d unopened, '
                . '%d lines unrecorded, %d settled unrecorded, %d events unindexed); reservations %d '
                . '(%d imported events; %d uncreated, %d check-ins, %d check-outs, %d cancellations '
                . 'unrecorded, %d events unindexed)%s',
                $id,
                $c['stock_indexed'],
                $c['stock'],
                $c['placed'],
                $c['orders'],
                $c['placed_indexed'],
                $c['invoices'],
                $c['invoices_unopened'],
                $c['lines_unrecorded'],
                $c['settled_unrecorded'],
                $c['invoice_unindexed'],
                $c['reservations'],
                $c['reservation_imported'],
                $c['reservations_uncreated'],
                $c['check_ins_unrecorded'],
                $c['check_outs_unrecorded'],
                $c['cancels_unrecorded'],
                $c['reservation_unindexed'],
                $ok ? '' : '  <- INCOMPLETE',
            ));
        }

        // The configuration baseline (step 9): rows with no recorded change.
        $baseline = new ConfigBaseline($connection);
        if (!$args->getOption('check-only')) {
            foreach ($baseline->run($propertyId) as $what => $count) {
                $io->out(sprintf('%-24s %d baselines added', $what, $count));
            }
        }
        foreach ($baseline->check($propertyId) as $id => $c) {
            $incomplete += $c['complete'] ? 0 : 1;
            $io->out(sprintf(
                'property %d: %d configuration rows, %d with no recorded change, %d baselines, %d changes since%s',
                $id,
                $c['config_rows'],
                $c['unrecorded'],
                $c['baselines'],
                $c['recorded_changes'],
                $c['complete'] ? '' : '  <- INCOMPLETE',
            ));
        }

        // Account history (step 10): one account_created per account.
        $access = new AccessBackfill($connection);
        if (!$args->getOption('check-only')) {
            $added = $access->run($propertyId);
            $io->out(sprintf(
                '%-24s %d added, %d not importable',
                'account_created',
                $added['account_created'],
                $added['skipped'],
            ));
        }
        foreach ($access->check($propertyId) as $id => $c) {
            $incomplete += $c['complete'] ? 0 : 1;
            $io->out(sprintf(
                '%s: %d accounts, %d with no recorded creation, %d imported%s',
                $id === 0 ? 'platform' : "property $id",
                $c['accounts'],
                $c['unrecorded'],
                $c['imported'],
                $c['complete'] ? '' : '  <- INCOMPLETE',
            ));
        }

        // Memberships and the platform flag (step 10b).
        $memberships = new MembershipImport($connection);
        if (!$args->getOption('check-only')) {
            $added = $memberships->run($propertyId);
            $io->out(sprintf(
                '%-24s %d memberships, %d platform flags added, %d not importable',
                'memberships',
                $added['memberships'],
                $added['platform'],
                $added['skipped'],
            ));
        }
        foreach ($memberships->check($propertyId) as $id => $c) {
            $incomplete += $c['complete'] ? 0 : 1;
            $io->out($id === 0
                ? sprintf(
                    'platform: %d owner accounts, %d flagged, %d grants recorded%s',
                    $c['owners'],
                    $c['flagged'],
                    $c['granted'],
                    $c['complete'] ? '' : '  <- INCOMPLETE',
                )
                : sprintf(
                    'property %d: %d staff accounts, %d with no membership, %d active memberships, %d imported%s',
                    $id,
                    $c['accounts'],
                    $c['without_membership'],
                    $c['active_memberships'],
                    $c['imported'],
                    $c['complete'] ? '' : '  <- INCOMPLETE',
                ));
        }

        // Guest history (G3): one imported registration per guest.
        $guestHistory = new GuestHistoryImport($connection);
        if (!$args->getOption('check-only')) {
            $added = $guestHistory->run($propertyId);
            $io->out(sprintf(
                '%-24s %d added, %d not importable',
                'guest history',
                $added['imported'],
                $added['skipped'],
            ));
        }
        foreach ($guestHistory->check($propertyId) as $id => $c) {
            $incomplete += $c['complete'] ? 0 : 1;
            $io->out(sprintf(
                'property %d: %d guests, %d with no history, %d imported, %d recorded since%s',
                $id,
                $c['guests'],
                $c['unrecorded'],
                $c['imported'],
                $c['recorded_since'],
                $c['complete'] ? '' : '  <- INCOMPLETE',
            ));
        }

        // Menu items, recipes and options still using a deleted inventory item
        // (I3): reported for a Manager to fix in POS, never repaired here.
        $broken = (new InventoryLinkReport($connection))->report($propertyId);
        foreach ($broken as $b) {
            $io->out(sprintf(
                'property %d: menu item %d "%s" (%s) uses deleted inventory item %d "%s"  <- FIX IN POS',
                $b['property_id'],
                $b['menu_item_id'],
                $b['menu_item'],
                $b['kind'],
                $b['inventory_item_id'],
                $b['inventory_item'],
            ));
        }
        if ($broken === []) {
            $io->out('inventory links: none broken');
        }

        return $incomplete === 0 ? static::CODE_SUCCESS : static::CODE_ERROR;
    }
}
