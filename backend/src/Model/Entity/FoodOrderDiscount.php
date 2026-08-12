<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * FoodOrderDiscount entity — one Senior/PWD beneficiary on an order. `amount`
 * is this beneficiary's own even split of the order's total discount,
 * snapshotted at order time (see FoodOrdersTable::place()).
 *
 * @property int $id
 * @property int $food_order_id
 * @property string $discount_type  senior | pwd
 * @property string $beneficiary_name
 * @property string $id_number
 * @property string $amount
 */
class FoodOrderDiscount extends Entity
{
    protected array $_accessible = [
        'food_order_id' => true,
        'discount_type' => true,
        'beneficiary_name' => true,
        'id_number' => true,
        'amount' => true,
    ];
}
