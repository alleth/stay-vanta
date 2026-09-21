<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\Reservation;
use App\Model\StatutoryDiscount;
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
    public const PAYMENT_STATUSES = ['unpaid', 'paid'];

    /**
     * Statutory Senior Citizen / PWD discount (Philippines). A booking carries
     * one `reservation_discounts` row per qualified guest rather than a single
     * flag, and the rate only ever covers each one's own share of the room —
     * see App\Model\StatutoryDiscount, which both this and Food & Orders price
     * through.
     */
    public const STATUTORY_DISCOUNT = StatutoryDiscount::RATE;

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
        $this->hasMany('ReservationDiscounts', ['dependent' => true]);
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
        $validator->inList('payment_status', self::PAYMENT_STATUSES);

        // How many people the room is billed between — the divisor the
        // statutory discount is shared over. One, unless the desk says
        // otherwise; never zero, which would make the share undefined.
        $validator
            ->integer('total_guests')
            ->greaterThanOrEqual('total_guests', 1, 'A booking has at least one guest.')
            ->allowEmptyString('total_guests');

        // Referral is a flat peso amount the receptionist decides, independent
        // of (and stackable with) the senior/pwd statutory discount — so it's
        // optional however many beneficiaries the booking carries, but must be
        // a positive number whenever it's set at all.
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

        $validator
            ->scalar('booking_reference')
            ->maxLength('booking_reference', 100)
            ->allowEmptyString('booking_reference');

        // What the channel sold the night for, gross. Not part of pricing —
        // promo_rate is still what the property charges — so it only has to
        // be a sensible figure when it's given at all.
        $validator
            ->numeric('sold_rate')
            ->greaterThan('sold_rate', 0, 'Enter the rate the channel sold it for, or leave it blank.')
            ->allowEmptyString('sold_rate');

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

        // An online booking is the channel's booking too: without its
        // reference number there's nothing to quote back when a stay is
        // disputed or a remittance doesn't add up. A walk-in has no channel,
        // so it never carries one.
        $rules->add(
            fn(Reservation $reservation): bool => $reservation->source === BookingSourcesTable::WALK_IN
                || trim((string)$reservation->booking_reference) !== '',
            'bookingReferenceRequired',
            [
                'errorField' => 'booking_reference',
                'message' => "Enter the channel's booking ID for an online booking.",
            ],
        );

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
     * The Senior/PWD beneficiaries on a booking.
     *
     * Contained rows are used as they are; otherwise they're read for the
     * booking's own id (an entity that was never saved has none, and so no
     * beneficiaries). Loading rather than trusting an absent association is
     * deliberate: quote() prices from the count, and a caller that forgot to
     * contain them would otherwise quietly bill the full rate.
     *
     * The rows are not written back onto the entity — a dirty hasMany would
     * ride along into the next save().
     *
     * @return list<\App\Model\Entity\ReservationDiscount>
     */
    public function beneficiariesFor(Reservation $reservation): array
    {
        if ($reservation->has('reservation_discounts')) {
            return array_values((array)$reservation->reservation_discounts);
        }
        if (!$reservation->id) {
            return [];
        }

        return TableRegistry::getTableLocator()->get('ReservationDiscounts')->find()
            ->where(['ReservationDiscounts.reservation_id' => $reservation->id])
            ->orderBy(['ReservationDiscounts.id' => 'ASC'])
            ->all()
            ->toList();
    }

    /**
     * Compute a price quote for a reservation given the resolved nightly rate.
     * The promo rate (an OTA-negotiated nightly price) overrides the base rate
     * when present.
     *
     * Senior/PWD and referral (`discount_amount`) are independent and stack: a
     * guest can be, say, a senior citizen *and* have a referral discount.
     *
     * The statutory discount is **per beneficiary, not per booking**: a room
     * can hold several qualified guests (an elderly couple, say), each
     * recorded as a `reservation_discounts` row, and the 20% only covers each
     * one's own even share of the room — `subtotal * (beneficiaries /
     * total_guests) * 20%`, the rule RA 9994 states. A lone senior in a room
     * billed for three guests takes 20% of a third of it, not 20% of all of
     * it. `statutory_shares` is each beneficiary's own slice, in row order, so
     * ReservationsController::postRoomCharge() can post one invoice line per
     * beneficiary naming who it was for; the shares always sum to
     * `statutory_discount`.
     *
     * Referral is the receptionist-entered flat amount, applied on what's left
     * after the statutory discount and capped there so the total can never go
     * negative.
     *
     * @return array{
     *     nights:int, nightly_rate:float, subtotal:float,
     *     statutory_discount:float, statutory_shares:list<float>,
     *     referral_discount:float, discount:float, total:float
     * }
     */
    public function quote(Reservation $reservation, float $baseNightlyRate): array
    {
        $nightly = $reservation->promo_rate !== null
            ? (float)$reservation->promo_rate
            : $baseNightlyRate;

        $nights = $this->nights($reservation);
        $subtotal = $nightly * $nights;

        $totalGuests = max(1, (int)($reservation->total_guests ?? 1));
        // More beneficiaries than guests is rejected at the door by the
        // controller; capping here too keeps a hand-edited row from pricing
        // the discount above the statutory rate.
        $beneficiaries = min(count($this->beneficiariesFor($reservation)), $totalGuests);
        $split = StatutoryDiscount::split($subtotal, $totalGuests, $beneficiaries);

        $statutoryDiscount = $split['total'];
        $remaining = max(0.0, $subtotal - $statutoryDiscount);
        $referralDiscount = $reservation->discount_amount !== null
            ? round(min((float)$reservation->discount_amount, $remaining), 2)
            : 0.0;

        return [
            'nights' => $nights,
            'nightly_rate' => round($nightly, 2),
            'subtotal' => round($subtotal, 2),
            'statutory_discount' => $statutoryDiscount,
            'statutory_shares' => $split['shares'],
            'referral_discount' => $referralDiscount,
            'discount' => round($statutoryDiscount + $referralDiscount, 2),
            'total' => round($subtotal - $statutoryDiscount - $referralDiscount, 2),
        ];
    }
}
