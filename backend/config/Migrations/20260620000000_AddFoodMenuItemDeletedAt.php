<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * food_menu_items.deleted_at — soft-delete marker. A "deleted" menu item is
 * hidden from the menu and from new orders, but the row stays so past
 * `food_order_items` keep their name/price reference (order history intact).
 */
class AddFoodMenuItemDeletedAt extends BaseMigration
{
    public function change(): void
    {
        $this->table('food_menu_items')
            ->addColumn('deleted_at', 'datetime', ['null' => true, 'after' => 'is_available'])
            ->update();
    }
}
