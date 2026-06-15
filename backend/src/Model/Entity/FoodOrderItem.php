<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * FoodOrderItem entity — one menu line on an order.
 *
 * @property int $id
 * @property int $food_order_id
 * @property int $food_menu_item_id
 * @property int $quantity
 * @property string $unit_price
 * @property string $line_total
 */
class FoodOrderItem extends Entity
{
    protected array $_accessible = [
        'food_order_id' => true,
        'food_menu_item_id' => true,
        'quantity' => true,
        'unit_price' => true,
        'line_total' => true,
    ];
}
