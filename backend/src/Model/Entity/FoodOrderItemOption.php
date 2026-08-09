<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * FoodOrderItemOption entity — a snapshot of one selected option on an order
 * line: label/price/stock-link as they were at order time, independent of
 * the live `food_menu_item_options` row (which may since have changed).
 *
 * @property int $id
 * @property int $food_order_item_id
 * @property int|null $option_id
 * @property string $label
 * @property string $price_delta
 * @property int $quantity
 * @property int|null $inventory_item_id
 */
class FoodOrderItemOption extends Entity
{
    protected array $_accessible = [
        'food_order_item_id' => true,
        'option_id' => true,
        'label' => true,
        'price_delta' => true,
        'quantity' => true,
        'inventory_item_id' => true,
    ];
}
