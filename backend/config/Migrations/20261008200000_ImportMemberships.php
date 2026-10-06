<?php
declare(strict_types=1);

use App\Event\MembershipImport;
use Cake\Cache\Cache;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Migrations\BaseMigration;

/**
 * Build step 10b: seed the role presets and their grants, give every Manager
 * and Front Desk account its membership, and set the platform flag for the
 * Platform Owner (see App\Event\MembershipImport). Idempotent and re-runnable
 * (`bin/cake activity_backfill`); the per-property check is logged.
 */
class ImportMemberships extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        // An earlier data migration in this process may have described
        // access_events before CreateMemberships added its columns (a fresh
        // database runs them all at once): forget those descriptions.
        TableRegistry::getTableLocator()->clear();
        Cache::clear('_cake_model_');

        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('default');
        $import = new MembershipImport($connection);
        $import->run();
        $import->check();
    }

    /**
     * Remove only what the import added (its events, memberships and flags);
     * the presets go with CreateMemberships' rollback.
     *
     * @return void
     */
    public function down(): void
    {
        $this->execute("DELETE FROM property_memberships WHERE id IN (SELECT membership_id FROM access_events
            WHERE source = 'import' AND event_type = 'membership_imported')");
        $this->execute("UPDATE users SET is_platform = 0 WHERE id IN (SELECT subject_user_id FROM access_events
            WHERE source = 'import' AND event_type = 'platform_access_granted')");
        $this->execute("DELETE FROM access_events WHERE source = 'import'
            AND event_type IN ('membership_imported', 'platform_access_granted')");
    }
}
