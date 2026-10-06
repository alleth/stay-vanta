<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Build step 10, part 2 (release 10b): Permissions Phase 2.
 *
 * - `roles`: the role presets. Their `code` is the stored role value
 *   (`admin`, `receptionist`), never renamed (principle 7); `name` is what
 *   screens show.
 * - `role_permissions`: role × permission name, checked against the code
 *   catalog (App\Auth\Permissions). Changed only by migration until Phase 3.
 * - `property_memberships`: who holds which role at which property, from
 *   `started_at` until `ended_at`. Never deleted: a membership is ended.
 * - `users.is_platform`: the platform flag (the Platform Owner).
 * - `access_events.membership_id` / `role_id`: filtered, so real columns.
 *
 * Additive only: the previous code ignores all of it, and `users.role` /
 * `users.property_id` keep being written for one release, so a rollback
 * restores the old behavior. No foreign keys, like the other ledgers' subjects.
 */
class CreateMemberships extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('roles')
            ->addColumn('code', 'string', ['limit' => 30, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 60, 'null' => false])
            ->addColumn('scope', 'string', ['limit' => 10, 'null' => false, 'default' => 'property'])
            ->addColumn('is_preset', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['code'], ['unique' => true])
            ->create();

        $this->table('role_permissions')
            ->addColumn('role_id', 'integer', ['null' => false])
            ->addColumn('permission', 'string', ['limit' => 60, 'null' => false])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addIndex(['role_id', 'permission'], ['unique' => true])
            ->create();

        $this->table('property_memberships')
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('property_id', 'integer', ['null' => false])
            ->addColumn('role_id', 'integer', ['null' => false])
            ->addColumn('started_at', 'datetime', ['null' => false])
            ->addColumn('ended_at', 'datetime', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['user_id', 'ended_at'])
            ->addIndex(['property_id', 'ended_at'])
            ->create();

        $this->table('users')
            ->addColumn('is_platform', 'boolean', ['null' => false, 'default' => false, 'after' => 'role'])
            ->update();

        $this->table('access_events')
            ->addColumn('membership_id', 'integer', ['null' => true, 'after' => 'subject_user_id'])
            ->addColumn('role_id', 'integer', ['null' => true, 'after' => 'membership_id'])
            ->addIndex(['membership_id', 'id'])
            ->update();
    }
}
