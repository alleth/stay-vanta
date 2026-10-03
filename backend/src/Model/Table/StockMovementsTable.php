<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Event\EventContext;
use App\Model\Entity\InventoryItem;
use App\Model\Entity\StockMovement;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use RuntimeException;

/**
 * StockMovements model — the accountability ledger, and the first one that
 * predates the event foundation: each row is the movement itself, so it goes
 * through EventLedgerBehavior::write() (the shared columns are stamped on the
 * row) rather than a separate events table. `receptionist_id` stays the actor
 * column and `direction` implies the event type (catalog: docs/EVENTS.md).
 *
 * @method \App\Model\Entity\StockMovement newEmptyEntity()
 */
class StockMovementsTable extends Table
{
    use AppendOnlyTableTrait;

    public const DIRECTIONS = ['in', 'out'];

    public const MOVED_IN = 'moved_in';
    public const MOVED_OUT = 'moved_out';

    public const TYPES = [self::MOVED_IN, self::MOVED_OUT];

    public const REQUIRES_REASON = [];

    public const REASON_GRACE = [];

    /**
     * @param array<string, mixed> $config Table config.
     * @return void
     */
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
        $this->addBehavior('EventLedger', [
            'subjectKey' => 'inventory_item_id',
            'subjectType' => 'inventory_item',
            'actorColumn' => 'receptionist_id',
            'eventTypeColumn' => null,
        ]);

        $this->belongsTo('Properties');
        $this->belongsTo('InventoryItems');
        $this->belongsTo('Receptionist', [
            'className' => 'Users',
            'foreignKey' => 'receptionist_id',
        ]);
    }

    /**
     * @param \Cake\Validation\Validator $validator The validator.
     * @return \Cake\Validation\Validator
     */
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
     * This is the ONLY supported way to change an item's on-hand quantity. In
     * one transaction it locks the item row (FOR UPDATE, so two movements of
     * the same item can't both start from the same quantity), writes the
     * ledger row with the shared event columns, indexes it in activity_index,
     * adjusts inventory_items.quantity and stamps the actor as the item's last
     * mover. The caller's `$item` is updated to the new quantities.
     *
     * @param \App\Event\EventContext $context Who is moving it, in which action.
     * @param \App\Model\Entity\InventoryItem $item The affected item.
     * @param string $direction 'in' or 'out'.
     * @param float $quantity Positive amount to move.
     * @param array<string, mixed> $extra Optional reason/reference_type/reference_id/note.
     * @param bool $affectsTotal When true the owned total (total_quantity) moves
     *   with the available quantity — i.e. acquiring/retiring reusable units, as
     *   opposed to merely issuing/returning them. Ignored for consumables.
     * @throws \RuntimeException When an 'out' would drive stock negative.
     */
    public function record(
        EventContext $context,
        InventoryItem $item,
        string $direction,
        float $quantity,
        array $extra = [],
        bool $affectsTotal = false,
    ): StockMovement {
        if ($context->actorId === null) {
            throw new RuntimeException('A stock movement needs the person moving it.');
        }

        return $this->getConnection()->transactional(
            function () use ($context, $item, $direction, $quantity, $extra, $affectsTotal): StockMovement {
                /** @var \App\Model\Entity\InventoryItem $locked */
                $locked = $this->InventoryItems->find()
                    ->where(['InventoryItems.id' => $item->id])
                    ->epilog('FOR UPDATE')
                    ->firstOrFail();

                $delta = $direction === 'out' ? -$quantity : $quantity;
                $newQty = (float)$locked->quantity + $delta;
                if ($newQty < 0) {
                    throw new RuntimeException('Insufficient stock for this movement.');
                }

                // Reusable accounting: acquiring/retiring moves the owned total
                // too; a plain return ('in') must not exceed the units owned.
                $isReusable = $locked->tracking_type === 'reusable';
                if ($isReusable && $affectsTotal) {
                    $newTotal = (float)$locked->total_quantity + $delta;
                    if ($newTotal < 0) {
                        throw new RuntimeException('Cannot reduce owned stock below zero.');
                    }
                    $locked->set('total_quantity', $newTotal);
                } elseif ($isReusable && $newQty > (float)$locked->total_quantity) {
                    throw new RuntimeException('Cannot return more than the total owned.');
                }

                $movement = $this->newEntity([
                    'property_id' => $locked->property_id,
                    'inventory_item_id' => $locked->id,
                    'receptionist_id' => $context->actorId,
                    'direction' => $direction,
                    'quantity' => $quantity,
                    'reason' => $extra['reason'] ?? null,
                    'reference_type' => $extra['reference_type'] ?? null,
                    'reference_id' => $extra['reference_id'] ?? null,
                    'note' => $extra['note'] ?? null,
                ]);
                if ($movement->getErrors()) {
                    throw new RuntimeException('Invalid stock movement: ' . json_encode($movement->getErrors()));
                }
                /** @var \App\Model\Behavior\EventLedgerBehavior $ledger */
                $ledger = $this->getBehavior('EventLedger');
                $type = $direction === 'out' ? self::MOVED_OUT : self::MOVED_IN;
                $ledger->write($context, $type, $movement, $locked, [
                    'snapshot' => [
                        'item' => $locked->name,
                        'unit' => $locked->unit,
                        'quantity_after' => $newQty,
                    ],
                    'summary' => [
                        'direction' => $direction,
                        'quantity' => $quantity,
                        'item' => $locked->name,
                        'unit' => $locked->unit,
                        'reason' => $movement->reason,
                    ],
                ]);

                $locked->set('quantity', $newQty);
                $locked->set('last_receptionist_id', $context->actorId);
                $this->InventoryItems->saveOrFail($locked);

                // Hand the caller the item as it now stands.
                foreach (['quantity', 'total_quantity', 'last_receptionist_id'] as $field) {
                    $item->set($field, $locked->get($field));
                    $item->setDirty($field, false);
                }

                return $movement;
            },
        );
    }

    /**
     * Minimal facts about the moved item (used only if a caller writes a
     * movement without its own snapshot).
     *
     * @param \Cake\Datasource\EntityInterface $item An inventory item.
     * @return array<string, mixed>
     */
    public function snapshotOf(EntityInterface $item): array
    {
        return ['item' => $item->get('name'), 'unit' => $item->get('unit')];
    }
}
