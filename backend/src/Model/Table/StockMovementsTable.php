<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\InventoryItem;
use App\Model\Entity\StockMovement;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use RuntimeException;

/**
 * StockMovements model — the accountability ledger.
 *
 * @method \App\Model\Entity\StockMovement newEmptyEntity()
 */
class StockMovementsTable extends Table
{
    public const DIRECTIONS = ['in', 'out'];

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('stock_movements');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');
        // Only `created` — movements are append-only and never modified.
        $this->addBehavior('Timestamp', [
            'events' => ['Model.beforeSave' => ['created' => 'new']],
        ]);

        $this->belongsTo('Properties');
        $this->belongsTo('InventoryItems');
        $this->belongsTo('Receptionist', [
            'className' => 'Users',
            'foreignKey' => 'receptionist_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->inList('direction', self::DIRECTIONS)
            ->requirePresence('direction', 'create');

        $validator
            ->numeric('quantity')
            ->greaterThan('quantity', 0, 'Quantity must be greater than zero.')
            ->requirePresence('quantity', 'create');

        $validator
            ->requirePresence('receptionist_id', 'create')
            ->integer('receptionist_id');

        return $validator;
    }

    /**
     * Record a stock movement and apply it to the item, atomically.
     *
     * This is the ONLY supported way to change an item's on-hand quantity:
     * it writes the ledger row, adjusts inventory_items.quantity, and stamps
     * the acting receptionist as the item's last mover — all in one
     * transaction. Returns the persisted movement.
     *
     * @param \App\Model\Entity\InventoryItem $item The affected item.
     * @param string $direction 'in' or 'out'.
     * @param float $quantity Positive amount to move.
     * @param int $receptionistId The acting receptionist (accountability).
     * @param array $extra Optional reason/reference_type/reference_id/note.
     * @throws \RuntimeException When an 'out' would drive stock negative.
     */
    public function record(
        InventoryItem $item,
        string $direction,
        float $quantity,
        int $receptionistId,
        array $extra = []
    ): StockMovement {
        return $this->getConnection()->transactional(
            function () use ($item, $direction, $quantity, $receptionistId, $extra): StockMovement {
                $delta = $direction === 'out' ? -$quantity : $quantity;
                $newQty = (float)$item->quantity + $delta;
                if ($newQty < 0) {
                    throw new RuntimeException('Insufficient stock for this movement.');
                }

                $movement = $this->newEntity([
                    'property_id' => $item->property_id,
                    'inventory_item_id' => $item->id,
                    'receptionist_id' => $receptionistId,
                    'direction' => $direction,
                    'quantity' => $quantity,
                    'reason' => $extra['reason'] ?? null,
                    'reference_type' => $extra['reference_type'] ?? null,
                    'reference_id' => $extra['reference_id'] ?? null,
                    'note' => $extra['note'] ?? null,
                ]);
                $this->saveOrFail($movement);

                $items = $this->InventoryItems;
                $item->set('quantity', $newQty);
                $item->set('last_receptionist_id', $receptionistId);
                $items->saveOrFail($item);

                return $movement;
            }
        );
    }
}
