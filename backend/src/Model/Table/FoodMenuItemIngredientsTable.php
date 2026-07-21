<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * FoodMenuItemIngredients model — a menu item's recipe lines.
 *
 * @method \App\Model\Entity\FoodMenuItemIngredient newEmptyEntity()
 */
class FoodMenuItemIngredientsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('food_menu_item_ingredients');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('FoodMenuItems');
        $this->belongsTo('InventoryItems');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->requirePresence('inventory_item_id', 'create')
            ->integer('inventory_item_id');

        $validator
            ->numeric('quantity')
            ->greaterThan('quantity', 0, 'Ingredient quantity must be greater than zero.')
            ->requirePresence('quantity', 'create');

        return $validator;
    }
}
