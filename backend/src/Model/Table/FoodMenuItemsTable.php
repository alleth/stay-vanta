<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * FoodMenuItems model.
 *
 * @method \App\Model\Entity\FoodMenuItem newEmptyEntity()
 * @method \App\Model\Entity\FoodMenuItem get(mixed $primaryKey, array $options = [])
 */
class FoodMenuItemsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('food_menu_items');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Properties');
        $this->belongsTo('InventoryItems');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->requirePresence('property_id', 'create')
            ->integer('property_id');

        $validator
            ->scalar('name')
            ->maxLength('name', 150)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->numeric('price')
            ->greaterThanOrEqual('price', 0)
            ->requirePresence('price', 'create');

        return $validator;
    }
}
