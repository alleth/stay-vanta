<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * InventoryCategories model.
 *
 * @method \App\Model\Entity\InventoryCategory newEmptyEntity()
 */
class InventoryCategoriesTable extends Table
{
    public const KINDS = ['food_stock', 'hygiene', 'linen', 'utensil', 'other'];

    public function initialize(array $config): void
    {
        parent::initialize($config);
        // Every change is recorded in config_changes (build step 9).
        $this->addBehavior('ConfigAudit', [
            'entityType' => 'inventory_category',
            'defaultImpact' => ConfigChangesTable::IMPACT_ADMINISTRATIVE,
            'softDelete' => true,
        ]);

        $this->setTable('inventory_categories');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Properties');
        $this->belongsTo('ParentCategories', [
            'className' => 'InventoryCategories',
            'foreignKey' => 'parent_id',
        ]);
        $this->hasMany('InventoryItems');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->requirePresence('property_id', 'create')
            ->integer('property_id');

        $validator
            ->scalar('name')
            ->maxLength('name', 100)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator->inList('kind', self::KINDS);

        return $validator;
    }
}
