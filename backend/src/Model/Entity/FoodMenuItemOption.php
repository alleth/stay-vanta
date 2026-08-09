<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * FoodMenuItemOption entity — one selectable option within a group.
 *
 * @property int $id
 * @property int $food_menu_item_option_group_id
 * @property string $label
 * @property string $price_delta
 * @property int|null $inventory_item_id
 * @property \App\Model\Entity\InventoryItem|null $inventory_item
 */
class FoodMenuItemOption extends Entity
{
    protected array $_accessible = [
        'food_menu_item_option_group_id' => true,
        'label' => true,
        'price_delta' => true,
        'inventory_item_id' => true,
    ];
}
