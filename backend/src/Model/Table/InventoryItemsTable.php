<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * InventoryItems model.
 *
 * @method \App\Model\Entity\InventoryItem newEmptyEntity()
 * @method \App\Model\Entity\InventoryItem get(mixed $primaryKey, array $options = [])
 */
class InventoryItemsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('inventory_items');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Properties');
        $this->belongsTo('InventoryCategories');
        $this->belongsTo('LastReceptionist', [
            'className' => 'Users',
            'foreignKey' => 'last_receptionist_id',
        ]);
        $this->hasMany('StockMovements');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->requirePresence('property_id', 'create')
            ->integer('property_id');

        $validator
            ->requirePresence('inventory_category_id', 'create')
            ->integer('inventory_category_id');

        $validator
            ->scalar('name')
            ->maxLength('name', 150)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->scalar('unit')
            ->maxLength('unit', 30)
            ->allowEmptyString('unit');

        $validator
            ->greaterThanOrEqual('reorder_level', 0)
            ->allowEmptyString('reorder_level');

        return $validator;
    }
}
