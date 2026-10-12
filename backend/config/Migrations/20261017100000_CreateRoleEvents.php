<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Role-definition history (final review G7, approved 2026-10-12 as
 * R-D1–R-D6). Every change to a role preset (created, renamed, a permission
 * granted or revoked) is a `role_events` row, written only through
 * App\Auth\RoleDefinitions in the transaction of the change. Platform events:
 * a preset applies to every property, so no property. No foreign keys.
 *
 * Additive only: the previous code keeps writing grants through
 * MembershipImport::seedRoles() as before.
 */
class CreateRoleEvents extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('role_events')
            ->addColumn('property_id', 'integer', ['null' => true])
            ->addColumn('role_id', 'integer', ['null' => false])
            ->addColumn('event_type', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('permission', 'string', ['limit' => 60, 'null' => true])
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
            ->addIndex(['role_id', 'id'])
            ->addIndex(['permission'])
            ->addIndex(['correlation_id'])
            ->create();
    }
}
