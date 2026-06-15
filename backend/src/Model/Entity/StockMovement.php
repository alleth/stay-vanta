<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * StockMovement entity — one row in the append-only accountability ledger.
 *
 * @property int $id
 * @property int $property_id
 * @property int $inventory_item_id
 * @property int $receptionist_id
 * @property string $direction   in | out
 * @property string $quantity
 * @property string|null $reason
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $note
 * @property \Cake\I18n\DateTime|null $created
 */
class StockMovement extends Entity
{
    protected array $_accessible = [
        'property_id' => true,
        'inventory_item_id' => true,
        'receptionist_id' => true,
        'direction' => true,
        'quantity' => true,
        'reason' => true,
        'reference_type' => true,
        'reference_id' => true,
        'note' => true,
    ];
}
