<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * FoodOrderItemOptions model — a snapshot of what was actually picked for an
 * order line's option group(s) at order time (label/price/stock link), kept
 * independent of the live `food_menu_item_options` config so edits/deletes to
 * the menu don't affect historical orders or cancel-time restocking.
 *
 * @method \App\Model\Entity\FoodOrderItemOption newEmptyEntity()
 */
class FoodOrderItemOptionsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('food_order_item_options');
        $this->setDisplayField('label');
        $this->setPrimaryKey('id');

        $this->belongsTo('FoodOrderItems');
    }
}
