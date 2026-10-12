<?php
declare(strict_types=1);

use App\Auth\RoleDefinitions;
use Cake\Cache\Cache;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Migrations\BaseMigration;

/**
 * Role-definition history (G7): record the role presets and their grants as
 * they stand when history begins, once: `role_imported` per role and
 * `permission_imported` per grant, each dated by its row's own `created`
 * time, with no actor and no reason (who approved them and why were never
 * recorded in the data; no earlier removal is known). Also records the
 * Platform Owner's code-defined grants as observed at release (R-D5). Logs
 * the drift self-check. Idempotent (RoleDefinitions::import()).
 */
class ImportRoleHistory extends BaseMigration
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
        $definitions = new RoleDefinitions($connection);
        $definitions->import();
        $definitions->check();
    }

    /**
     * Remove only what the import added (a rollback restores code, not data;
     * the history-only platform role goes with its events).
     *
     * @return void
     */
    public function down(): void
    {
        $this->execute("DELETE FROM role_events WHERE source = 'import'");
        $this->execute("DELETE FROM roles WHERE code = 'platform'");
    }
}
