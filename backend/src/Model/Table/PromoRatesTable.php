<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * PromoRates model — OTA nightly prices per property + booking source,
 * optionally per room (room_id null = property-wide).
 *
 * @method \App\Model\Entity\PromoRate newEmptyEntity()
 * @method \App\Model\Entity\PromoRate get(mixed $primaryKey, array $options = [])
 */
class PromoRatesTable extends Table
{
    /** Booking sources a promo rate can target (OTAs only — walk-ins pay the base rate). */
    public const SOURCES = ['cocotel', 'agoda', 'trip_com', 'tripadvisor'];

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('promo_rates');
        $this->setDisplayField('source');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Properties');
        $this->belongsTo('Rooms');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->requirePresence('property_id', 'create')
            ->integer('property_id');

        $validator
            ->requirePresence('source', 'create')
            ->inList('source', self::SOURCES, 'Pick an OTA booking source.');

        $validator
            ->numeric('rate')
            ->greaterThanOrEqual('rate', 0)
            ->requirePresence('rate', 'create');

        return $validator;
    }

    /**
     * The nightly promo rate for a booking source, preferring a room-specific
     * row over a property-wide one. Null when the admin hasn't configured one
     * (the reservation then falls back to the base room rate).
     */
    public function rateFor(int $propertyId, string $source, ?int $roomId): ?float
    {
        $query = $this->find()
            ->where(['PromoRates.property_id' => $propertyId, 'PromoRates.source' => $source]);

        if ($roomId !== null) {
            $query->where(function ($exp) use ($roomId) {
                return $exp->or([
                    'PromoRates.room_id' => $roomId,
                    'PromoRates.room_id IS' => null,
                ]);
            });
        } else {
            $query->where(['PromoRates.room_id IS' => null]);
        }

        $rate = $query->orderBy(['PromoRates.room_id' => 'DESC'])->first();

        return $rate ? (float)$rate->rate : null;
    }
}
