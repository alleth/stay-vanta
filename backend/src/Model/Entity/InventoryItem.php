<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * InventoryItem entity.
 *
 * `quantity` is the current on-hand amount and must only ever change via a
 * StockMovement (see StockMovementsTable::record) so the ledger stays the
 * source of truth. `last_receptionist_id` mirrors the most recent mover.
 *
 * @property int $id
 * @property int $property_id
 * @property int $inventory_category_id
 * @property string $name
 * @property string $unit
 * @property string $quantity
 * @property string $reorder_level
 * @property int|null $last_receptionist_id
 */
class InventoryItem extends Entity
{
    protected array $_accessible = [
        'property_id' => true,
        'inventory_category_id' => true,
        'name' => true,
        'unit' => true,
        'reorder_level' => true,
        // quantity & last_receptionist_id are set only via stock movements.
    ];
}
