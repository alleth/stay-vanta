<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Permissions Phase 3b (approved 2026-10-12 as E3b-D1–D5): the role catalog.
 * `kind` separates the built-ins (`system`: defined by code, changed only by
 * migration, PH-D1) from roles the Platform Owner makes (`custom`). The
 * default is `system`, so the presets and the history-only `platform` entry
 * that RoleDefinitions creates from code are system roles; the app's
 * custom-role path sets `custom` explicitly. A copy remembers its source
 * (`copied_from_role_id`); a role is archived, never deleted.
 *
 * Additive only: the release before ignores these columns.
 */
class AddRoleCatalogColumns extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('roles')
            ->addColumn('kind', 'string', ['limit' => 10, 'null' => false, 'default' => 'system', 'after' => 'scope'])
            ->addColumn('description', 'text', ['null' => true, 'after' => 'name'])
            ->addColumn('copied_from_role_id', 'integer', ['null' => true, 'after' => 'kind'])
            ->addColumn('archived_at', 'datetime', ['null' => true, 'after' => 'is_preset'])
            ->update();
    }
}
