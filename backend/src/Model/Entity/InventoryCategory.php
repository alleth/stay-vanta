<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * InventoryCategory entity.
 *
 * @property int $id
 * @property int $property_id
 * @property int|null $parent_id
 * @property string $name
 * @property string $kind   food_stock | hygiene | linen | utensil | other
 */
class InventoryCategory extends Entity
{
    protected array $_accessible = [
        'property_id' => true,
        'parent_id' => true,
        'name' => true,
        'kind' => true,
    ];
}
