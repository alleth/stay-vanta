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
 * @property string $tracking_type  consumable | reusable
 * @property string $unit
 * @property string $quantity        for reusables: the count currently available
 * @property string|null $total_quantity  units owned (reusables only); in-use = total - quantity
 * @property string $reorder_level
 * @property int|null $last_receptionist_id
 * @property \Cake\I18n\DateTime|null $deleted_at  soft-delete marker (hidden when set)
 */
class InventoryItem extends Entity
{
    protected array $_accessible = [
        'property_id' => true,
        'inventory_category_id' => true,
        'parent_id' => true,
        'name' => true,
        'tracking_type' => true,
        'unit' => true,
        'reorder_level' => true,
        // quantity, total_quantity & last_receptionist_id are set only via stock movements.
    ];
}
