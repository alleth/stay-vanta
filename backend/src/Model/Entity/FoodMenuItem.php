<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * FoodMenuItem entity. `inventory_item_id` links the dish/drink (or linen) to
 * an inventory item so that ordering it decrements stock.
 *
 * @property int $id
 * @property int $property_id
 * @property int|null $inventory_item_id
 * @property string $name
 * @property string $type  food | linen — which management tab it belongs to
 * @property string $price
 * @property bool $is_available
 * @property \Cake\I18n\DateTime|null $deleted_at  soft-delete marker (hidden when set)
 */
class FoodMenuItem extends Entity
{
    protected array $_accessible = [
        'property_id' => true,
        'inventory_item_id' => true,
        'name' => true,
        'type' => true,
        'price' => true,
        'is_available' => true,
    ];
}
