<?php
declare(strict_types=1);

use App\Event\AccessBackfill;
use Cake\Datasource\ConnectionManager;
use Migrations\BaseMigration;

/**
 * Build step 10, part 1: one `account_created` per existing account, at the
 * account's own creation time, with no actor (A13; see App\Event\AccessBackfill).
 * Idempotent and re-runnable (`bin/cake activity_backfill`); the per-property
 * check is logged.
 */
class BackfillAccountHistory extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('default');
        $backfill = new AccessBackfill($connection);
        $backfill->run();
        $backfill->check();
    }

    /**
     * Remove only what the import added (and its feed rows).
     *
     * @return void
     */
    public function down(): void
    {
        $this->execute("DELETE FROM activity_index WHERE event_table = 'access_events'
            AND event_id IN (SELECT id FROM access_events WHERE source = 'import')");
        $this->execute("DELETE FROM access_events WHERE source = 'import'");
    }
}
