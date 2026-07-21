<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * PromoRates model — OTA rate multipliers per property + booking source,
 * optionally per room (room_id null = property-wide). The promo nightly price
 * is the room's original (base) rate × the multiplier.
 *
 * @method \App\Model\Entity\PromoRate newEmptyEntity()
 * @method \App\Model\Entity\PromoRate get(mixed $primaryKey, array $options = [])
 */
class PromoRatesTable extends Table
{
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

        // Validity (must be one of the property's configured booking sources,
        // never 'walk_in') is checked in the controller, where the property
        // is known.
        $validator
            ->scalar('source')
            ->maxLength('source', 50)
            ->requirePresence('source', 'create')
            ->notEmptyString('source');

        $validator
            ->numeric('multiplier')
            ->greaterThan('multiplier', 0, 'The multiplier must be greater than zero.')
            ->requirePresence('multiplier', 'create');

        return $validator;
    }

    /**
     * The rate multiplier for a booking source, preferring a room-specific
     * row over a property-wide one. Null when the admin hasn't configured one
     * (the reservation then falls back to the base room rate).
     */
    public function multiplierFor(int $propertyId, string $source, ?int $roomId): ?float
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

        return $rate ? (float)$rate->multiplier : null;
    }
}
