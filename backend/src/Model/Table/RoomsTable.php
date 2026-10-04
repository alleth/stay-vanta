<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Rooms model.
 *
 * @method \App\Model\Entity\Room newEmptyEntity()
 * @method \App\Model\Entity\Room get(mixed $primaryKey, array $options = [])
 */
class RoomsTable extends Table
{
    public const STATUSES = ['available', 'occupied', 'maintenance'];

    public function initialize(array $config): void
    {
        parent::initialize($config);
        // Every change is recorded in config_changes (build step 9).
        $this->addBehavior('ConfigAudit', [
            'entityType' => 'room',
            'defaultImpact' => ConfigChangesTable::IMPACT_BOOKING,
            'impacts' => [
                'room_number' => ConfigChangesTable::IMPACT_OPERATIONAL,
                'room_type' => ConfigChangesTable::IMPACT_OPERATIONAL,
            ],
            // Occupancy, cleaning and maintenance are operational state, kept
            // by the future Rooms module (C5), not configuration.
            'ignore' => ['status'],
            'labelFields' => ['room_number'],
            'softDelete' => true,
        ]);

        $this->setTable('rooms');
        $this->setDisplayField('room_number');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Properties');
        $this->hasMany('RoomRates');
        $this->hasMany('Reservations');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->requirePresence('property_id', 'create')
            ->integer('property_id');

        $validator
            ->scalar('room_number')
            ->maxLength('room_number', 30)
            ->requirePresence('room_number', 'create')
            ->notEmptyString('room_number');

        $validator->inList('status', self::STATUSES);

        return $validator;
    }
}
