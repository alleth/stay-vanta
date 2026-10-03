<?php
declare(strict_types=1);

use App\Event\ActivityBackfill;
use Cake\Datasource\ConnectionManager;
use Migrations\BaseMigration;

/**
 * Build step 5, part 3: index the history that predates the event foundation,
 * so the Operations feed can read activity_index alone. Data only, idempotent,
 * and the same routine as `bin/cake activity_backfill` (see ActivityBackfill
 * for the rules it follows). Its per-property check goes to the log, which
 * Railway keeps, so the result can be read without database access.
 *
 * down() removes only what a backfill created (`source = 'import'` events and
 * `import-` index rows), through the connection: the one sanctioned way past
 * the ledgers' append-only rule.
 */
class BackfillActivityIndex extends BaseMigration
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
        $connection->execute("DELETE FROM activity_index WHERE correlation_id LIKE 'import-%'");
        $connection->execute("DELETE FROM food_order_events WHERE source = 'import'");
    }
}
