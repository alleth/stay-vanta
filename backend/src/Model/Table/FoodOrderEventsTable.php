<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * POS sales ledger: one append-only row per thing that happened to a sale,
 * recorded through EventLedgerBehavior::record() in the same transaction as
 * the change (catalog: docs/EVENTS.md).
 */
class FoodOrderEventsTable extends Table
{
    use AppendOnlyTableTrait;
    use EventLedgerTableTrait;

    public const PLACED = 'placed';
    public const SERVED = 'served';
    public const CANCELLED = 'cancelled';
    /** A served and paid sale reversed: pos.sale.cancel_paid, with a reason. */
    public const CANCELLED_AFTER_PAYMENT = 'cancelled_after_payment';
    /**
     * Money returned for a paid sale when it's cancelled ("Was money returned
     * to the guest?" yes), with the cancellation's reason and the method
     * (build step 7c, written from 7c-2). Cancelling and refunding are separate.
     */
    public const REFUNDED = 'refunded';

    public const TYPES = [self::PLACED, self::SERVED, self::CANCELLED, self::CANCELLED_AFTER_PAYMENT, self::REFUNDED];

    public const REQUIRES_REASON = [self::CANCELLED_AFTER_PAYMENT, self::REFUNDED];

    /**
     * Accepted without a reason until the compatibility window closes (the
     * POS released before step 5 can't send one). Emptied in the cleanup
     * release; see docs/EVENTS.md.
     */
    public const REASON_GRACE = [self::CANCELLED_AFTER_PAYMENT];

    /**
     * @param array<string, mixed> $config Table config.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('food_order_events');
        $this->addBehavior('EventLedger', ['subjectKey' => 'food_order_id', 'subjectType' => 'food_order']);
    }

    /**
     * The sale as it stood: amounts, states, room, and the guest by id and
     * display name only (no contact details or ID numbers).
     *
     * @param \Cake\Datasource\EntityInterface $order A food order.
     * @return array<string, mixed>
     */
    public function snapshotOf(EntityInterface $order): array
    {
        $locator = TableRegistry::getTableLocator();
        $room = $order->get('room');
        if ($room === null && $order->get('room_id')) {
            $room = $locator->get('Rooms')->find()->select(['room_number'])
                ->where(['id' => $order->get('room_id')])->first();
        }
        $guest = $order->get('guest');
        if ($guest === null && $order->get('guest_id')) {
            $guest = $locator->get('Guests')->find()->select(['id', 'full_name'])
                ->where(['id' => $order->get('guest_id')])->first();
        }

        return [
            'total' => round((float)$order->get('total'), 2),
            'status' => $order->get('status'),
            'payment_status' => $order->get('payment_status'),
            'room' => $room?->get('room_number'),
            'guest_id' => $order->get('guest_id'),
            'guest_name' => $guest?->get('full_name'),
        ];
    }
}
