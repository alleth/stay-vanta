<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * FoodMenuItemOptionGroup entity — a labeled set of options on a menu item.
 * `kind` is `choice` (guest must pick exactly one, normally free) or `addon`
 * (guest may add any number of each option, normally priced).
 *
 * @property int $id
 * @property int $food_menu_item_id
 * @property string $name
 * @property string $kind
 * @property \App\Model\Entity\FoodMenuItemOption[] $food_menu_item_options
 */
class FoodMenuItemOptionGroup extends Entity
{
    protected array $_accessible = [
        'food_menu_item_id' => true,
        'name' => true,
        'kind' => true,
    ];
}
