<?php
declare(strict_types=1);

use App\Event\ConfigBaseline;
use Cake\Datasource\ConnectionManager;
use Migrations\BaseMigration;

/**
 * Build step 9, part 2 (C7): one `baseline_recorded` change for every
 * configuration row, with its values as they stand at import, no actor, no
 * reason. Earlier values, who made a row, and rows deleted before step 9 were
 * never recorded, so nothing earlier is invented. Idempotent; logs what it
 * added and its per-property check. Re-run with `bin/cake activity_backfill`.
 *
 * down() removes only the imported baselines and their index rows.
 */
class BackfillConfigBaseline extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('default');
        $baseline = new ConfigBaseline($connection);
        $baseline->run();
        $baseline->check();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('default');
        $connection->execute(
            "DELETE FROM activity_index WHERE event_table = 'config_changes' AND event_type = 'baseline_recorded'",
        );
        $connection->execute("DELETE FROM config_changes WHERE event_type = 'baseline_recorded'");
    }
}
