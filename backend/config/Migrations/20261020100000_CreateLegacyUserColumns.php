<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Permissions Phase 3d-2 (approved 2026-10-13 as 3D2-D4): the archive that
 * 3d-3 fills before it drops users.role and users.property_id. Created empty
 * here so its structure and checksums are proven before any column goes.
 *
 * One row per account, written once and never changed (append-only, like the
 * event ledgers): the two values as they were, a per-row checksum, when and by
 * which release they were copied. No foreign key cascade: accounts are never
 * deleted, and the archive must outlive any change to them.
 *
 * Additive only: the release before ignores this table.
 */
class CreateLegacyUserColumns extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        $this->table('legacy_user_columns')
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('role', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('property_id', 'integer', ['null' => true])
            ->addColumn('row_checksum', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('archived_at', 'datetime', ['null' => false])
            ->addColumn('release_commit', 'string', ['limit' => 40, 'null' => true])
            ->addIndex(['user_id'], ['unique' => true])
            ->create();
    }

    /**
     * Removed only while empty: once it holds the archive, dropping it would
     * destroy the only copy of the retired values.
     *
     * @return void
     */
    public function down(): void
    {
        $row = $this->fetchRow('SELECT COUNT(*) FROM legacy_user_columns');
        if ((int)($row[0] ?? 0) > 0) {
            throw new RuntimeException(
                'legacy_user_columns holds the archive of users.role / users.property_id; it is never dropped.',
            );
        }
        $this->table('legacy_user_columns')->drop()->save();
    }
}
