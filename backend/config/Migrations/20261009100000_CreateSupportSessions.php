<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Build step 10b, part 2: support access (A6). A support session lets the
 * Platform Owner read one property's data for 60 minutes, with a reason,
 * every request recorded and shown to the property's Manager. The row holds
 * the session's state (open until `ended_at` or `expires_at`); what happened
 * is in access_events (`support_access_started` / `_used` / `_ended`, with
 * `support_session_id`).
 *
 * Additive only. No foreign keys.
 */
class CreateSupportSessions extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('support_sessions')
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('property_id', 'integer', ['null' => false])
            ->addColumn('reason', 'text', ['null' => false])
            ->addColumn('started_at', 'datetime', ['null' => false])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('ended_at', 'datetime', ['null' => true])
            ->addColumn('ended_by', 'integer', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['user_id', 'ended_at'])
            ->addIndex(['property_id', 'started_at'])
            ->create();

        $this->table('access_events')
            ->addColumn('support_session_id', 'integer', ['null' => true, 'after' => 'role_id'])
            ->addIndex(['support_session_id', 'id'])
            ->update();
    }
}
