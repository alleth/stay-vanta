<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * FoodOrderDiscounts model — one row per Senior/PWD beneficiary on an order
 * (an order can carry several, e.g. two senior citizens at the same table).
 * `amount` is a snapshot of that beneficiary's own even split of the order's
 * total discount, independent of any later change — see FoodOrdersTable::place().
 *
 * @method \App\Model\Entity\FoodOrderDiscount newEmptyEntity()
 */
class FoodOrderDiscountsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('food_order_discounts');
        $this->setDisplayField('beneficiary_name');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('FoodOrders');
    }
}
