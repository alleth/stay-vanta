<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Enforcement-setting audit (final review G6, approved 2026-10-09 as
 * E-D1–E-D6). The subscription-enforcement phase moves from the environment
 * into `platform_settings`, changed only through App\Platform\Enforcement and
 * recorded in the append-only `platform_setting_events` ledger (who, before,
 * after, why, request id, the properties it affected). Platform events: no
 * property. No foreign keys.
 *
 * Additive only: the previous code keeps reading the environment variable.
 */
class CreatePlatformSettings extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('platform_settings')
            ->addColumn('setting_key', 'string', ['limit' => 60, 'null' => false])
            ->addColumn('value', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['setting_key'], ['unique' => true])
            ->create();

        $this->table('platform_setting_events')
            ->addColumn('property_id', 'integer', ['null' => true])
            ->addColumn('platform_setting_id', 'integer', ['null' => false])
            ->addColumn('event_type', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('value_before', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('value_after', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('actor_id', 'integer', ['null' => true])
            ->addColumn('actor_role', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('source', 'string', ['limit' => 10, 'null' => false])
            ->addColumn('reason', 'text', ['null' => true])
            ->addColumn('changes', 'json', ['null' => true])
            ->addColumn('snapshot', 'json', ['null' => true])
            ->addColumn('correlation_id', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('occurred_at', 'datetime', ['null' => false])
            ->addColumn('corrects_event_id', 'integer', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addIndex(['occurred_at'])
            ->addIndex(['platform_setting_id', 'id'])
            ->addIndex(['correlation_id'])
            ->create();
    }
}
