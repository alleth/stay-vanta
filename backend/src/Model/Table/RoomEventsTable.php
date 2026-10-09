<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;

/**
 * Room service ledger (final review G4, approved 2026-10-09 as R1–R5):
 * every change to whether a room can be sold and assigned, with who, the
 * service status before and after, why, and the request. Occupancy (check-in,
 * check-out) is the reservation ledger's and isn't copied here. Written by
 * RoomsController::service() under a lock on the room row. Every type but the
 * import reaches Operations → Activity (R2); the history is Managers' (R1).
 */
class RoomEventsTable extends Table
{
    use AppendOnlyTableTrait;
    use EventLedgerTableTrait;

    /** In service → maintenance: off sale briefly. Needs a reason (what's wrong). */
    public const MAINTENANCE_STARTED = 'maintenance_started';
    /** Maintenance → in service. A reason is optional. */
    public const MAINTENANCE_COMPLETED = 'maintenance_completed';
    /** → out of service: off sale until further notice (Managers). Needs a reason. */
    public const TAKEN_OUT_OF_SERVICE = 'taken_out_of_service';
    /** Out of service → in service (Managers). A reason is optional. */
    public const RETURNED_TO_SERVICE = 'returned_to_service';
    /** Import: a room already off service when history began; no actor, reason or start time. */
    public const IMPORTED = 'imported';

    public const TYPES = [
        self::MAINTENANCE_STARTED,
        self::MAINTENANCE_COMPLETED,
        self::TAKEN_OUT_OF_SERVICE,
        self::RETURNED_TO_SERVICE,
        self::IMPORTED,
    ];

    public const REQUIRES_REASON = [
        self::MAINTENANCE_STARTED,
        self::TAKEN_OUT_OF_SERVICE,
    ];

    public const REASON_GRACE = [];

    /** Every service change reaches Activity (R2); the import doesn't. */
    public const FEED_TYPES = [
        self::MAINTENANCE_STARTED,
        self::MAINTENANCE_COMPLETED,
        self::TAKEN_OUT_OF_SERVICE,
        self::RETURNED_TO_SERVICE,
    ];

    /**
     * @param array<string, mixed> $config Table config.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('room_events');
        $this->addBehavior('EventLedger', [
            'subjectKey' => 'room_id',
            'subjectType' => 'room',
            'indexTypes' => self::FEED_TYPES,
        ]);
    }

    /**
     * The room as it stood: id and number.
     *
     * @param \Cake\Datasource\EntityInterface $room A room.
     * @return array<string, mixed>
     */
    public function snapshotOf(EntityInterface $room): array
    {
        return ['room_id' => (int)$room->get('id'), 'room_number' => $room->get('room_number')];
    }

    /**
     * The event a service change is, from where it goes and where it was.
     */
    public static function typeOf(string $before, string $after): string
    {
        return match (true) {
            $after === RoomsTable::OUT_OF_SERVICE => self::TAKEN_OUT_OF_SERVICE,
            $after === RoomsTable::MAINTENANCE => self::MAINTENANCE_STARTED,
            $before === RoomsTable::OUT_OF_SERVICE => self::RETURNED_TO_SERVICE,
            default => self::MAINTENANCE_COMPLETED,
        };
    }
}
