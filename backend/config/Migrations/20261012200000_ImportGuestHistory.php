<?php
declare(strict_types=1);

use App\Event\GuestHistoryImport;
use App\Event\MembershipImport;
use Cake\Cache\Cache;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Migrations\BaseMigration;

/**
 * Guest accountability (G3): grant the Manager preset
 * `guests.guest.view_history` (GU1), then one `imported` event per existing
 * guest at its own registration time, with no actor (see
 * App\Event\GuestHistoryImport). Idempotent and re-runnable
 * (`bin/cake activity_backfill`); the per-property check is logged.
 */
class ImportGuestHistory extends BaseMigration
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
        (new MembershipImport($connection))->seedRoles();
        $import = new GuestHistoryImport($connection);
        $import->run();
        $import->check();
    }

    /**
     * Remove only what the import added.
     *
     * @return void
     */
    public function down(): void
    {
        $this->execute("DELETE FROM guest_events WHERE source = 'import'");
        $this->execute("DELETE FROM role_permissions WHERE permission = 'guests.guest.view_history'");
    }
}
