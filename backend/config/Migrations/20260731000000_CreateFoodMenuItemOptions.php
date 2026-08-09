<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Menu-item option groups: a guest-facing pick, distinct from the silent
 * `food_menu_item_ingredients` recipe. A group's `kind` is `choice` (guest
 * must pick exactly one option, normally free — e.g. "Choice of Drink") or
 * `addon` (guest may add any number of each option, normally priced — e.g.
 * "Additional egg" +15). Each option optionally links an inventory item that
 * decrements when chosen. `food_order_item_options` snapshots what was
 * actually picked per order line (label/price/stock link), independent of
 * the live option config, mirroring how `food_order_items` already
 * snapshots `unit_price`/`line_total` instead of re-reading the menu price.
 */
class CreateFoodMenuItemOptions extends BaseMigration
{
    public function change(): void
    {
        $this->table('food_menu_item_option_groups')
            ->addColumn('food_menu_item_id', 'integer', ['null' => false])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('kind', 'string', ['limit' => 10, 'null' => false, 'default' => 'choice'])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['food_menu_item_id'])
            ->create();

        $this->table('food_menu_item_options')
            ->addColumn('food_menu_item_option_group_id', 'integer', ['null' => false])
            ->addColumn('label', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('price_delta', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => false, 'default' => 0])
            ->addColumn('inventory_item_id', 'integer', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['food_menu_item_option_group_id'])
            ->addIndex(['inventory_item_id'])
            ->create();

        $this->table('food_order_item_options')
            ->addColumn('food_order_item_id', 'integer', ['null' => false])
            ->addColumn('option_id', 'integer', ['null' => true])
            ->addColumn('label', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('price_delta', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => false, 'default' => 0])
            ->addColumn('quantity', 'integer', ['null' => false, 'default' => 1])
            ->addColumn('inventory_item_id', 'integer', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['food_order_item_id'])
            ->addIndex(['option_id'])
            ->create();
    }
}
