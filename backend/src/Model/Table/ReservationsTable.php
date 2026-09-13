<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\Reservation;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Validation\Validator;
use Closure;

/**
 * Reservations model.
 *
 * @method \App\Model\Entity\Reservation newEmptyEntity()
 * @method \App\Model\Entity\Reservation get(mixed $primaryKey, array $options = [])
 */
class ReservationsTable extends Table
{
    public const STATUSES = ['booked', 'checked_in', 'checked_out', 'cancelled'];

    /**
     * Statuses that actually hold a room. A checked-out or cancelled booking
     * releases it, so only these two can collide with a new one.
     */
    public const HOLDS_ROOM = ['booked', 'checked_in'];
    public const DISCOUNT_TYPES = ['none', 'senior', 'pwd'];
    public const PAYMENT_STATUSES = ['unpaid', 'paid'];

    /** Statutory Senior Citizen / PWD discount (Philippines). */
    public const STATUTORY_DISCOUNT = 0.20;

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('reservations');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Properties');
        $this->belongsTo('Rooms');
        $this->belongsTo('Guests');
        $this->belongsTo('Receptionist', [
            'className' => 'Users',
            'foreignKey' => 'receptionist_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->requirePresence('property_id', 'create')
            ->integer('property_id');

        $validator->inList('status', self::STATUSES);
        // Validity (walk_in, or one of the property's configured booking
        // sources) is checked in the controller, where the property is known.
        $validator->scalar('source')->maxLength('source', 50);
        $validator->inList('discount_type', self::DISCOUNT_TYPES);
        $validator->inList('payment_status', self::PAYMENT_STATUSES);

        // Referral is a flat peso amount the receptionist decides, independent
        // of (and stackable with) the senior/pwd statutory discount above —
        // so it's optional regardless of discount_type, but must be a
        // positive number whenever it's set at all.
        $validator
            ->numeric('discount_amount')
            ->greaterThan('discount_amount', 0, 'Enter a referral discount amount greater than 0.')
            ->allowEmptyString('discount_amount');

        $validator
            ->date('check_in')
            ->requirePresence('check_in', 'create')
            ->notEmptyDate('check_in');

        $validator
            ->date('check_out')
            ->requirePresence('check_out', 'create')
            ->notEmptyDate('check_out')
            ->add('check_out', 'after', [
                'rule' => fn($value, $context) => empty($context['data']['check_in'])
                    || strtotime((string)$value) > strtotime((string)$context['data']['check_in']),
                'message' => 'Check-out must be after check-in.',
            ]);

        $validator
            ->nonNegativeInteger('additional_beds')
            ->allowEmptyString('additional_beds');

        return $validator;
    }

    /**
     * A reservation's room and guest must belong to the same property as the
     * reservation itself.
     *
     * Both arrive as raw ids in the request body (`room_id`, `guest_id`) and
     * neither is something the caller should be trusted about: a booking
     * pointing at another property's room would flip that property's
     * `rooms.status` to occupied on check-in, and one pointing at another
     * property's guest would echo their name and contact details back through
     * the reservations index, which contains Guests.
     *
     * This lives in the table rather than the controller so every writer is
     * covered at once — `add()`, `edit()`, and anything added later — and so a
     * row that is already wrong can't be saved again by a lifecycle
     * transition without the problem surfacing.
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($this->inSameProperty('Rooms', 'room_id'), 'roomInProperty', [
            'errorField' => 'room_id',
            'message' => 'That room does not belong to this property.',
        ]);

        $rules->add($this->inSameProperty('Guests', 'guest_id'), 'guestInProperty', [
            'errorField' => 'guest_id',
            'message' => 'That guest does not belong to this property.',
        ]);

        $rules->add(
            function (Reservation $reservation): bool {
                // A booking that no longer holds the room can't collide.
                if (
                    $reservation->room_id === null
                    || !in_array($reservation->status, self::HOLDS_ROOM, true)
                    || !$reservation->check_in
                    || !$reservation->check_out
                ) {
                    return true;
                }

                return !$this->conflicting(
                    (int)$reservation->room_id,
                    $reservation->check_in,
                    $reservation->check_out,
                    $reservation->isNew() ? null : (int)$reservation->id,
                )->count();
            },
            'roomAvailable',
            [
                'errorField' => 'room_id',
                'message' => 'That room is already booked for those dates.',
            ],
        );

        return $rules;
    }

    /**
     * Bookings that hold `$roomId` for any night in `[$checkIn, $checkOut)`.
     *
     * Occupancy is the nights stayed, not the dates touched: a booking that
     * checks out on the 14th frees the room for someone checking in that same
     * day, which is why the comparisons are strict. (The Front Desk calendar
     * derives availability the same way.)
     *
     * @param \Cake\I18n\Date|string $checkIn
     * @param \Cake\I18n\Date|string $checkOut
     */
    public function conflicting(
        int $roomId,
        mixed $checkIn,
        mixed $checkOut,
        ?int $excludeId = null,
    ): SelectQuery {
        $query = $this->find()->where([
            'Reservations.room_id' => $roomId,
            'Reservations.status IN' => self::HOLDS_ROOM,
            'Reservations.check_in <' => $checkOut,
            'Reservations.check_out >' => $checkIn,
        ]);

        if ($excludeId !== null) {
            $query->where(['Reservations.id !=' => $excludeId]);
        }

        return $query;
    }

    /**
     * Take a write lock on a room for the rest of the current transaction.
     *
     * The rule above closes the gap between two requests only if they can't
     * both pass it at once — checking availability and then inserting is two
     * steps, and two receptionists booking the same room at the same moment
     * would otherwise both find it free. Locking the room row serialises them:
     * the second waits, then re-runs the rule and sees the first booking.
     *
     * Only meaningful inside a transaction — the lock is released when that
     * transaction ends. Same idiom as ReceiptSeriesTable::assignNext().
     */
    public function lockRoom(int $roomId): void
    {
        TableRegistry::getTableLocator()->get('Rooms')->find()
            ->where(['Rooms.id' => $roomId])
            ->epilog('FOR UPDATE')
            ->first();
    }

    /**
     * Build a rule asserting that the row `$field` points at shares the
     * reservation's property. A null id passes — whether the column may be
     * empty at all is validationDefault's business, not this rule's.
     */
    private function inSameProperty(string $association, string $field): Closure
    {
        return function (Reservation $reservation) use ($association, $field): bool {
            $id = $reservation->get($field);
            if ($id === null) {
                return true;
            }

            // Via the association's target rather than the table locator —
            // a Table has no getTableLocator() in CakePHP 5.
            return $this->getAssociation($association)->getTarget()->exists([
                $association . '.id' => $id,
                $association . '.property_id' => $reservation->property_id,
            ]);
        };
    }

    /**
     * Number of chargeable nights between check-in and check-out.
     */
    public function nights(Reservation $reservation): int
    {
        if (!$reservation->check_in || !$reservation->check_out) {
            return 0;
        }
        $diff = $reservation->check_in->diffInDays($reservation->check_out);

        return max(0, (int)$diff);
    }

    /**
     * Compute a price quote for a reservation given the resolved nightly rate.
     * The promo rate (an OTA-negotiated nightly price) overrides the base rate
     * when present. Senior/PWD (`discount_type`) and referral
     * (`discount_amount`) are independent and stack: a guest can be, say, a
     * senior citizen *and* have a referral discount. Senior/PWD applies the
     * statutory 20% off the subtotal; referral is the receptionist-entered
     * flat amount, applied on what's left after the statutory discount and
     * capped there so the total can never go negative.
     *
     * @return array{
     *     nights:int, nightly_rate:float, subtotal:float,
     *     statutory_discount:float, referral_discount:float, discount:float, total:float
     * }
     */
    public function quote(Reservation $reservation, float $baseNightlyRate): array
    {
        $nightly = $reservation->promo_rate !== null
            ? (float)$reservation->promo_rate
            : $baseNightlyRate;

        $nights = $this->nights($reservation);
        $subtotal = $nightly * $nights;

        $statutoryDiscount = in_array($reservation->discount_type, ['senior', 'pwd'], true)
            ? round($subtotal * self::STATUTORY_DISCOUNT, 2)
            : 0.0;
        $remaining = max(0.0, $subtotal - $statutoryDiscount);
        $referralDiscount = $reservation->discount_amount !== null
            ? round(min((float)$reservation->discount_amount, $remaining), 2)
            : 0.0;

        return [
            'nights' => $nights,
            'nightly_rate' => round($nightly, 2),
            'subtotal' => round($subtotal, 2),
            'statutory_discount' => $statutoryDiscount,
            'referral_discount' => $referralDiscount,
            'discount' => round($statutoryDiscount + $referralDiscount, 2),
            'total' => round($subtotal - $statutoryDiscount - $referralDiscount, 2),
        ];
    }
}
