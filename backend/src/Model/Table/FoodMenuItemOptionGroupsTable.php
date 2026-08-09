<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * FoodMenuItemOptionGroups model — a menu item's guest-facing option groups
 * (distinct from the silent `food_menu_item_ingredients` recipe).
 *
 * @method \App\Model\Entity\FoodMenuItemOptionGroup newEmptyEntity()
 */
class FoodMenuItemOptionGroupsTable extends Table
{
    public const KINDS = ['choice', 'addon'];

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('food_menu_item_option_groups');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('FoodMenuItems');
        $this->hasMany('FoodMenuItemOptions', ['dependent' => true]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('name')
            ->maxLength('name', 100)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator->inList('kind', self::KINDS, 'Kind must be choice or addon.');

        return $validator;
    }
}
