<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Build step 10, part 1: access accountability (docs/EVENTS.md,
 * access_events).
 *
 * One ledger of everything that changes who can get in, and of every
 * sign-in: accounts created, renamed, deactivated and reactivated, passwords
 * reset or changed, sessions started, refused, locked, ended. The subject is
 * the person whose access it is (`subject_user_id`). Platform events (the
 * Platform Owner's own sign-ins) have no property (`scope = platform`,
 * decided 2026-10-05 as A2). Sign-ins keep the browser's user agent and the
 * client address the edge reported (A4), never used to allow or refuse.
 *
 * Additive only: the previous code ignores it. No foreign keys and no
 * ON DELETE CASCADE: events outlive their subject.
 */
class CreateAccessEvents extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('access_events')
            ->addColumn('property_id', 'integer', ['null' => true])
            ->addColumn('scope', 'string', ['limit' => 10, 'null' => false])
            ->addColumn('subject_user_id', 'integer', ['null' => false])
            ->addColumn('event_type', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('actor_id', 'integer', ['null' => true])
            ->addColumn('actor_role', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('source', 'string', ['limit' => 10, 'null' => false])
            ->addColumn('reason', 'text', ['null' => true])
            ->addColumn('changes', 'json', ['null' => true])
            ->addColumn('snapshot', 'json', ['null' => true])
            ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('client_address', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('correlation_id', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('occurred_at', 'datetime', ['null' => false])
            ->addColumn('corrects_event_id', 'integer', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addIndex(['property_id', 'occurred_at'])
            ->addIndex(['subject_user_id', 'id'])
            ->addIndex(['scope', 'occurred_at'])
            ->addIndex(['property_id', 'correlation_id'])
            ->create();
    }
}
