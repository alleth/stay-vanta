<?php
declare(strict_types=1);

use App\Event\ActivityBackfill;
use Cake\Datasource\ConnectionManager;
use Migrations\BaseMigration;

/**
 * Build step 6, part 3: record invoice history from before invoice_events
 * (opened at `created`, each line at its `created`, settled at `settled_at`
 * with its receipt numbers) and index it, so invoice events can join the
 * Operations feed. The same idempotent ActivityBackfill as step 5 (its other
 * steps add nothing now); it never invents an actor, a reason, an amount it
 * can't vouch for or a time, and logs what it added and its per-property check.
 *
 * down() removes only imported invoice events and their index rows.
 */
class BackfillInvoiceEvents extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('default');
        $backfill = new ActivityBackfill($connection);
        $backfill->run();
        $backfill->check();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('default');
        $connection->execute(
            "DELETE FROM activity_index WHERE event_table = 'invoice_events' AND correlation_id LIKE 'import-%'",
        );
        $connection->execute("DELETE FROM invoice_events WHERE source = 'import'");
    }
}
