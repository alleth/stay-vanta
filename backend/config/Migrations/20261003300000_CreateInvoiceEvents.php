<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Build step 6: invoice accountability (docs/EVENTS.md, invoice_events).
 *
 * - `invoice_events`: who opened, added to, reversed, settled and refunded
 *   each invoice, when, why, in which request — with the money in typed
 *   columns (`amount`, `total_after`) and the receipt numbers.
 * - `invoice_lines.reverses_line_id`: lines are never deleted again; a
 *   cancelled line is answered by a negative line pointing at it.
 *
 * Additive only: the previous code ignores both. No foreign keys and no
 * ON DELETE CASCADE: events outlive their subject.
 */
class CreateInvoiceEvents extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('invoice_events')
            ->addColumn('property_id', 'integer', ['null' => false])
            ->addColumn('invoice_id', 'integer', ['null' => false])
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
            ->addColumn('total_after', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => true])
            ->addColumn('invoice_line_id', 'integer', ['null' => true])
            ->addColumn('invoice_number', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('or_number', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addIndex(['property_id', 'occurred_at'])
            ->addIndex(['invoice_id', 'id'])
            ->addIndex(['property_id', 'correlation_id'])
            ->create();

        $this->table('invoice_lines')
            ->addColumn('reverses_line_id', 'integer', ['null' => true, 'after' => 'source_id'])
            ->addIndex(['reverses_line_id'])
            ->update();
    }
}
