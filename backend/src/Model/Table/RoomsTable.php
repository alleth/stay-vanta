<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Datasource\EntityInterface;
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
    /**
     * Occupancy values. `maintenance` is only the pre-G4 combined value: still
     * accepted so an untouched old row stays valid, never written since G8b.
     */
    public const STATUSES = ['available', 'occupied', 'maintenance'];

    // Service availability (final review G4): whether the room can be sold
    // and assigned. One writer: RoomsController::service(), recorded in
    // room_events. Occupancy is separate: only check-in and check-out set it.
    public const IN_SERVICE = 'in_service';
    public const MAINTENANCE = 'maintenance';
    public const OUT_OF_SERVICE = 'out_of_service';
    public const SERVICE_STATUSES = [self::IN_SERVICE, self::MAINTENANCE, self::OUT_OF_SERVICE];

    /** Display names, for refusals. */
    public const SERVICE_LABELS = [
        self::IN_SERVICE => 'in service',
        self::MAINTENANCE => 'under maintenance',
        self::OUT_OF_SERVICE => 'out of service',
    ];

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
            // Occupancy (reservations) and service availability (room_events,
            // G4) are operational state with their own records, not
            // configuration.
            'ignore' => ['status', 'service_status'],
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
        $validator->inList('service_status', self::SERVICE_STATUSES);

        return $validator;
    }

    /**
     * Set a room's occupancy (check-in, check-out, cancellation, moving a
     * guest): occupied or available. `rooms.status` is occupancy only; whether
     * the room can be sold is `service_status` (G4), which this never touches,
     * so a check-out never clears maintenance. G8b stopped writing the old
     * combined `maintenance` value here.
     *
     * @param \Cake\Datasource\EntityInterface $room The room (saved by the caller).
     * @param bool $occupied Whether a guest is now in it.
     */
    public static function setOccupied(EntityInterface $room, bool $occupied): void
    {
        $room->set('status', $occupied ? 'occupied' : 'available');
    }
}
