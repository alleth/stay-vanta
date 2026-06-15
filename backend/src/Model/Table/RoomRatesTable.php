<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * RoomRates model.
 *
 * @method \App\Model\Entity\RoomRate newEmptyEntity()
 * @method \App\Model\Entity\RoomRate get(mixed $primaryKey, array $options = [])
 */
class RoomRatesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('room_rates');
        $this->setDisplayField('name');
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
            ->scalar('name')
            ->maxLength('name', 100)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->numeric('base_rate')
            ->greaterThanOrEqual('base_rate', 0)
            ->requirePresence('base_rate', 'create');

        return $validator;
    }
}
