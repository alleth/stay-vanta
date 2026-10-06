<?php
declare(strict_types=1);

use App\Event\MembershipImport;
use Cake\Cache\Cache;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Migrations\BaseMigration;

/**
 * Build step 10b, part 2: grant the Manager preset `settings.role.view`
 * (Settings → Roles). Grants live in data since part 1; a new one ships as a
 * migration. MembershipImport::seedRoles() adds only what a preset is
 * missing from Permissions::ROLE_GRANTS, so it's idempotent.
 */
class SeedRoleGrants extends BaseMigration
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
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->execute("DELETE FROM role_permissions WHERE permission = 'settings.role.view'");
    }
}
