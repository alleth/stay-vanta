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
     * guest): occupied, or, once vacant, available only while it's in
     * service. A check-out never clears maintenance (G4). `rooms.status`
     * keeps this combined value for one release, so older readers see a
     * room under maintenance as before.
     *
     * @param \Cake\Datasource\EntityInterface $room The room (saved by the caller).
     * @param bool $occupied Whether a guest is now in it.
     */
    public static function setOccupied(EntityInterface $room, bool $occupied): void
    {
        if ($occupied) {
            $room->set('status', 'occupied');

            return;
        }
        $inService = ($room->get('service_status') ?? self::IN_SERVICE) === self::IN_SERVICE;
        $room->set('status', $inService ? 'available' : 'maintenance');
    }
}
