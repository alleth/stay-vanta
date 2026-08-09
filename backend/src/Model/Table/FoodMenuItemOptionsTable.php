<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * FoodMenuItemOptions model — one selectable option within an option group
 * (e.g. "Coffee" in "Choice of Drink", or "Additional egg" in "Add-ons").
 *
 * @method \App\Model\Entity\FoodMenuItemOption newEmptyEntity()
 */
class FoodMenuItemOptionsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('food_menu_item_options');
        $this->setDisplayField('label');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('FoodMenuItemOptionGroups');
        $this->belongsTo('InventoryItems');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('label')
            ->maxLength('label', 100)
            ->requirePresence('label', 'create')
            ->notEmptyString('label');

        $validator
            ->numeric('price_delta')
            ->greaterThanOrEqual('price_delta', 0)
            ->requirePresence('price_delta', 'create');

        return $validator;
    }
}
