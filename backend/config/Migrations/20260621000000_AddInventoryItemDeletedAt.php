<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * inventory_items.deleted_at — soft-delete marker. A "deleted" item is hidden
 * from the inventory and from menu linking, but the row stays so its
 * stock_movements (the accountability ledger) remain intact.
 */
class AddInventoryItemDeletedAt extends BaseMigration
{
    public function change(): void
    {
        $this->table('inventory_items')
            ->addColumn('deleted_at', 'datetime', ['null' => true, 'after' => 'last_receptionist_id'])
            ->update();
    }
}
