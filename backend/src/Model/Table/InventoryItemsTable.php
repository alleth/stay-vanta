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
        // What an item is (name, unit, category, low-stock threshold, stock
        // type, parent) is recorded in config_changes (inventory follow-up,
        // approved 2026-10-06 as G1/I1–I3). How much there is belongs to the
        // stock ledger: quantities change only through
        // StockMovementsTable::record(), so they're never audited here.
        // Deleting is a soft delete with a reason; rows are filtered by hand
        // (`deleted_at IS NULL`) as before, so `softDelete` stays off and no
        // existing query changes.
        $this->addBehavior('ConfigAudit', [
            'entityType' => 'inventory_item',
            'defaultImpact' => ConfigChangesTable::IMPACT_OPERATIONAL,
            'impacts' => [
                'name' => ConfigChangesTable::IMPACT_ADMINISTRATIVE,
                'unit' => ConfigChangesTable::IMPACT_ADMINISTRATIVE,
                'inventory_category_id' => ConfigChangesTable::IMPACT_ADMINISTRATIVE,
                'parent_id' => ConfigChangesTable::IMPACT_ADMINISTRATIVE,
                'reorder_level' => ConfigChangesTable::IMPACT_OPERATIONAL,
                'tracking_type' => ConfigChangesTable::IMPACT_OPERATIONAL,
            ],
            'ignore' => ['quantity', 'total_quantity', 'last_receptionist_id'],
        ]);

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
