<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Build step 7c-2: a refund is recorded exactly once. The screen sends a key
 * made when the refund dialog opens; a repeat of the same request (a double
 * click, a retry after a timeout) carries the same key and is refused. The
 * unique index makes that hold even if two copies race past the check.
 * Nullable: only refunds carry one.
 */
class AddRefundIdempotencyKey extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        foreach (['invoice_events', 'food_order_events'] as $table) {
            $this->table($table)
                ->addColumn('idempotency_key', 'string', ['limit' => 64, 'null' => true, 'after' => 'method'])
                ->addIndex(['idempotency_key'], ['unique' => true, 'name' => $table . '_idempotency_key'])
                ->update();
        }
    }
}
