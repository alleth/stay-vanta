<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\Reservation;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Reservations model.
 *
 * @method \App\Model\Entity\Reservation newEmptyEntity()
 * @method \App\Model\Entity\Reservation get(mixed $primaryKey, array $options = [])
 */
class ReservationsTable extends Table
{
    public const STATUSES = ['booked', 'checked_in', 'checked_out', 'cancelled'];
    public const SOURCES = ['walk_in', 'cocotel', 'agoda', 'trip_com', 'tripadvisor'];
    public const DISCOUNT_TYPES = ['none', 'senior', 'pwd'];

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
        $validator->inList('source', self::SOURCES);
        $validator->inList('discount_type', self::DISCOUNT_TYPES);

        $validator
            ->date('check_in')
            ->requirePresence('check_in', 'create')
            ->notEmptyDate('check_in');

        $validator
            ->date('check_out')
            ->requirePresence('check_out', 'create')
            ->notEmptyDate('check_out')
            ->add('check_out', 'after', [
                'rule' => fn ($value, $context) => empty($context['data']['check_in'])
                    || strtotime((string)$value) > strtotime((string)$context['data']['check_in']),
                'message' => 'Check-out must be after check-in.',
            ]);

        $validator
            ->nonNegativeInteger('additional_beds')
            ->allowEmptyString('additional_beds');

        return $validator;
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
     * when present; senior/PWD applies the statutory discount.
     *
     * @return array{nights:int, nightly_rate:float, subtotal:float, discount:float, total:float}
     */
    public function quote(Reservation $reservation, float $baseNightlyRate): array
    {
        $nightly = $reservation->promo_rate !== null
            ? (float)$reservation->promo_rate
            : $baseNightlyRate;

        $nights = $this->nights($reservation);
        $subtotal = $nightly * $nights;
        $discountRate = in_array($reservation->discount_type, ['senior', 'pwd'], true)
            ? self::STATUTORY_DISCOUNT
            : 0.0;
        $discount = round($subtotal * $discountRate, 2);

        return [
            'nights' => $nights,
            'nightly_rate' => round($nightly, 2),
            'subtotal' => round($subtotal, 2),
            'discount' => $discount,
            'total' => round($subtotal - $discount, 2),
        ];
    }
}
