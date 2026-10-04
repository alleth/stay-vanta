<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * Reservation ledger (build step 8): every booking, change, correction,
 * check-in/out, cancellation and deletion, with who, when, why and what
 * changed. Written only by ReservationsController, one event per action,
 * inside the action's transaction with the reservation row locked.
 * Front Desk owns the lifecycle; money stays in invoice_events (the
 * reservation's history shows both, linked by correlation id).
 */
class ReservationEventsTable extends Table
{
    use AppendOnlyTableTrait;
    use EventLedgerTableTrait;

    /** A future booking is created (it holds the room). */
    public const BOOKED = 'booked';
    /** A walk-in is created, checked in at once (it occupies the room). */
    public const WALKED_IN = 'walked_in';
    /** A past stay is entered, or a booking's check-in moved before today (Manager, reason). */
    public const BACKDATED = 'backdated';
    /** A booking is changed before check-in. */
    public const EDITED = 'edited';
    /** A Manager corrects a checked-in or checked-out stay (reason). */
    public const CORRECTED = 'corrected';
    /** A booking's discounts change after it was made (a referral amount needs a reason). */
    public const DISCOUNT_CHANGED = 'discount_changed';
    public const CHECKED_IN = 'checked_in';
    public const CHECKED_OUT = 'checked_out';
    /** Cancelled with no money taken. */
    public const CANCELLED = 'cancelled';
    /** Cancelled after a downpayment was collected or charges were posted (reason). */
    public const CANCELLED_AFTER_PAYMENT = 'cancelled_after_payment';
    /** A Manager deletes a reservation entered by mistake: soft, with a reason. */
    public const DELETED = 'deleted';

    public const TYPES = [
        self::BOOKED,
        self::WALKED_IN,
        self::BACKDATED,
        self::EDITED,
        self::CORRECTED,
        self::DISCOUNT_CHANGED,
        self::CHECKED_IN,
        self::CHECKED_OUT,
        self::CANCELLED,
        self::CANCELLED_AFTER_PAYMENT,
        self::DELETED,
    ];

    public const REQUIRES_REASON = [
        self::BACKDATED,
        self::CORRECTED,
        self::CANCELLED_AFTER_PAYMENT,
        self::DELETED,
    ];

    public const REASON_GRACE = [];

    /**
     * @param array<string, mixed> $config Table config.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('reservation_events');
        $this->addBehavior('EventLedger', ['subjectKey' => 'reservation_id', 'subjectType' => 'reservation']);
    }

    /**
     * The reservation as it stood: guest by id and display name, room number,
     * dates, status and guest count. No contact details.
     *
     * @param \Cake\Datasource\EntityInterface $reservation A reservation.
     * @return array<string, mixed>
     */
    public function snapshotOf(EntityInterface $reservation): array
    {
        $locator = TableRegistry::getTableLocator();
        $guestId = $reservation->get('guest_id');
        $roomId = $reservation->get('room_id');
        $guest = $guestId
            ? $locator->get('Guests')->find()->select(['full_name'])->where(['id' => $guestId])->first()
            : null;
        $room = $roomId
            ? $locator->get('Rooms')->find()->select(['room_number'])->where(['id' => $roomId])->first()
            : null;

        return [
            'guest_id' => $reservation->get('guest_id'),
            'guest_name' => $guest?->get('full_name'),
            'room' => $room?->get('room_number'),
            'check_in' => $reservation->get('check_in')?->format('Y-m-d'),
            'check_out' => $reservation->get('check_out')?->format('Y-m-d'),
            'status' => $reservation->get('status'),
            'total_guests' => $reservation->get('total_guests'),
        ];
    }
}
