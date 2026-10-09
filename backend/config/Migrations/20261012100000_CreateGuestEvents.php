<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Guest accountability (final review G3, approved 2026-10-09 as GU1–GU5):
 * one ledger of every guest registration, edit, rename and detail completed
 * from a booking, with who, before/after and why (renames and look-alike
 * overrides). Same shared columns as every `*_events` table; append-only.
 *
 * Additive only. No foreign keys and no ON DELETE CASCADE.
 */
class CreateGuestEvents extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('guest_events')
            ->addColumn('property_id', 'integer', ['null' => false])
            ->addColumn('guest_id', 'integer', ['null' => false])
            ->addColumn('event_type', 'string', ['limit' => 40, 'null' => false])
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
            ->addIndex(['property_id', 'occurred_at'])
            ->addIndex(['guest_id', 'id'])
            ->addIndex(['property_id', 'correlation_id'])
            ->create();
    }
}
