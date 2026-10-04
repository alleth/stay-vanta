<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Build step 7c-1: how money went back to a guest (cash, gcash, maya,
 * gotyme), on the refund events of both money ledgers. A real column, not
 * JSON, because reconciliation filters and sums by it. Nullable: no event
 * written before step 7c-2 has a method, and none is ever guessed.
 */
class AddRefundMethod extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('invoice_events')
            ->addColumn('method', 'string', ['limit' => 20, 'null' => true, 'after' => 'amount'])
            ->update();
        $this->table('food_order_events')
            ->addColumn('method', 'string', ['limit' => 20, 'null' => true, 'after' => 'amount'])
            ->update();
    }
}
