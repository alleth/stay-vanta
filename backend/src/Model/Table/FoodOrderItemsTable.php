<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * FoodOrderItems model.
 *
 * @method \App\Model\Entity\FoodOrderItem newEmptyEntity()
 */
class FoodOrderItemsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('food_order_items');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->belongsTo('FoodOrders');
        $this->belongsTo('FoodMenuItems');
    }
}
