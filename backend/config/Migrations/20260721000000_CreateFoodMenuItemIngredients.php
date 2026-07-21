<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * food_menu_item_ingredients — a menu item's recipe (bill of materials): each
 * row is one ingredient (an inventory item) plus the quantity of it consumed
 * per single serving. This is on top of, not instead of, the existing
 * `food_menu_items.inventory_item_id` single link — a menu item can have a
 * primary linked stock AND/OR a multi-ingredient recipe here. Placing an
 * order decrements every ingredient row's quantity (per serving × qty
 * ordered) alongside the single link; cancelling restocks the same way.
 */
class CreateFoodMenuItemIngredients extends BaseMigration
{
    public function change(): void
    {
        $this->table('food_menu_item_ingredients')
            ->addColumn('food_menu_item_id', 'integer', ['null' => false])
            ->addColumn('inventory_item_id', 'integer', ['null' => false])
            ->addColumn('quantity', 'decimal', ['precision' => 10, 'scale' => 2, 'null' => false, 'default' => 1])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['food_menu_item_id'])
            ->addIndex(['inventory_item_id'])
            ->create();
    }
}
