<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * FoodMenuItemIngredient entity — one line of a menu item's recipe: an
 * inventory item plus how much of it a single serving consumes.
 *
 * @property int $id
 * @property int $food_menu_item_id
 * @property int $inventory_item_id
 * @property string $quantity
 * @property \App\Model\Entity\InventoryItem|null $inventory_item
 */
class FoodMenuItemIngredient extends Entity
{
    protected array $_accessible = [
        'food_menu_item_id' => true,
        'inventory_item_id' => true,
        'quantity' => true,
    ];
}
