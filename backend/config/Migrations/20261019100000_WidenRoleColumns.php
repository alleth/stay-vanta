<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Permissions Phase 3c-1 (approved 2026-10-12 as C-D2 and C-D5): room for
 * custom role codes before anyone can hold one.
 *
 * - `actor_role` on every ledger: 20 -> 60. A custom role's code is longer than
 *   20 characters, and an event that can't be saved refuses the change it
 *   records, so a custom-role holder could change nothing.
 * - `roles.code`: 30 -> 60 (new custom codes are capped at 40).
 * - `users.role`: 20 -> 60 and nullable. It is a legacy column (dropped in
 *   Phase 3d); a custom-role holder will have none rather than a built-in role
 *   they don't hold (C-D2).
 *
 * Widening only: no row changes, and the release before works unchanged.
 */
class WidenRoleColumns extends BaseMigration
{
    /**
     * Every ledger that records the actor's role.
     */
    private const LEDGERS = [
        'access_events',
        'config_changes',
        'food_order_events',
        'guest_events',
        'invoice_events',
        'platform_setting_events',
        'reservation_events',
        'role_events',
        'room_events',
        'stock_movements',
    ];

    /**
     * @return void
     */
    public function up(): void
    {
        foreach (self::LEDGERS as $table) {
            $this->table($table)
                ->changeColumn('actor_role', 'string', ['limit' => 60, 'null' => true])
                ->update();
        }
        $this->table('roles')
            ->changeColumn('code', 'string', ['limit' => 60, 'null' => false])
            ->update();
        $this->table('users')
            ->changeColumn('role', 'string', ['limit' => 60, 'null' => true])
            ->update();
    }

    /**
     * Narrows back only while every value still fits; otherwise it refuses, since
     * narrowing would cut recorded history.
     *
     * @return void
     */
    public function down(): void
    {
        $checks = [];
        foreach (self::LEDGERS as $table) {
            $checks[] = "SELECT COUNT(*) FROM `$table` WHERE CHAR_LENGTH(actor_role) > 20";
        }
        $checks[] = 'SELECT COUNT(*) FROM `roles` WHERE CHAR_LENGTH(code) > 30';
        $checks[] = 'SELECT COUNT(*) FROM `users` WHERE role IS NULL OR CHAR_LENGTH(role) > 20';
        foreach ($checks as $sql) {
            $row = $this->fetchRow($sql);
            if ((int)($row[0] ?? 0) > 0) {
                throw new RuntimeException("WidenRoleColumns can't be rolled back: values no longer fit ($sql).");
            }
        }
        foreach (self::LEDGERS as $table) {
            $this->table($table)
                ->changeColumn('actor_role', 'string', ['limit' => 20, 'null' => true])
                ->update();
        }
        $this->table('roles')
            ->changeColumn('code', 'string', ['limit' => 30, 'null' => false])
            ->update();
        $this->table('users')
            ->changeColumn('role', 'string', ['limit' => 20, 'null' => false])
            ->update();
    }
}
