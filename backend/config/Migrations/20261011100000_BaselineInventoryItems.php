<?php
declare(strict_types=1);

use App\Event\ConfigBaseline;
use App\Event\InventoryLinkReport;
use Cake\Cache\Cache;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Migrations\BaseMigration;

/**
 * Inventory follow-up (G1/I3, approved 2026-10-06): one `baseline_recorded`
 * per existing inventory item, its values at the import (never its stock,
 * which is the stock ledger's), no actor, `source = import`. An item already
 * deleted carries its recorded `deleted_at`, with no `deleted` event made up.
 * Then the broken-link report: menu items, recipes and options still using a
 * deleted item, logged for a Manager to fix in POS, never repaired here.
 *
 * Idempotent: ConfigBaseline skips every row that has a change on record, so
 * only inventory items are new. Re-run with `bin/cake activity_backfill`.
 */
class BaselineInventoryItems extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        TableRegistry::getTableLocator()->clear();
        Cache::clear('_cake_model_');
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('default');
        $baseline = new ConfigBaseline($connection);
        $baseline->run();
        $baseline->check();
        (new InventoryLinkReport($connection))->report();
    }

    /**
     * Remove only what this import added.
     *
     * @return void
     */
    public function down(): void
    {
        $this->execute("DELETE FROM activity_index WHERE event_table = 'config_changes' AND event_id IN
            (SELECT id FROM config_changes WHERE entity_type = 'inventory_item' AND source = 'import')");
        $this->execute("DELETE FROM config_changes WHERE entity_type = 'inventory_item' AND source = 'import'");
    }
}
