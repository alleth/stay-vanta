<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Build step 5: the shared event foundation (CLAUDE.md, "Accountability
 * standard"; catalog in docs/EVENTS.md).
 *
 * - `activity_index`: one row per event from every ledger, for the Operations
 *   feed and for reconstructing a business action by correlation id.
 * - `food_order_events`: the POS ledger, the first new one.
 * - `stock_movements` gains the shared ledger columns. They're nullable here
 *   because the code before this release inserts movements without them, and
 *   a rollback restores code, not data; the application requires them, and
 *   the cleanup release makes them NOT NULL. `receptionist_id` stays the
 *   actor column (stored names don't change).
 *
 * No foreign keys and no ON DELETE CASCADE: an event outlives its subject.
 */
class CreateEventFoundation extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('activity_index')
            ->addColumn('property_id', 'integer', ['null' => false])
            ->addColumn('occurred_at', 'datetime', ['null' => false])
            ->addColumn('actor_id', 'integer', ['null' => true])
            ->addColumn('subject_type', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('subject_id', 'integer', ['null' => false])
            ->addColumn('event_table', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('event_id', 'integer', ['null' => false])
            ->addColumn('event_type', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('correlation_id', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('summary', 'json', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addIndex(['property_id', 'occurred_at', 'id'])
            ->addIndex(['property_id', 'correlation_id'])
            ->addIndex(['event_table', 'event_id'], ['unique' => true])
            ->create();

        $this->table('food_order_events')
            ->addColumn('property_id', 'integer', ['null' => false])
            ->addColumn('food_order_id', 'integer', ['null' => false])
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
            ->addColumn('amount', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addIndex(['property_id', 'occurred_at'])
            ->addIndex(['food_order_id', 'id'])
            ->addIndex(['property_id', 'correlation_id'])
            ->create();

        $this->table('stock_movements')
            ->addColumn('actor_role', 'string', ['limit' => 20, 'null' => true, 'after' => 'receptionist_id'])
            ->addColumn('source', 'string', ['limit' => 10, 'null' => true, 'after' => 'actor_role'])
            ->addColumn('changes', 'json', ['null' => true, 'after' => 'note'])
            ->addColumn('snapshot', 'json', ['null' => true, 'after' => 'changes'])
            ->addColumn('correlation_id', 'string', ['limit' => 100, 'null' => true, 'after' => 'snapshot'])
            ->addColumn('occurred_at', 'datetime', ['null' => true, 'after' => 'correlation_id'])
            ->addColumn('corrects_event_id', 'integer', ['null' => true, 'after' => 'occurred_at'])
            ->addIndex(['property_id', 'occurred_at'])
            ->addIndex(['property_id', 'correlation_id'])
            ->update();
    }
}
