<?php
declare(strict_types=1);

namespace App\Event;

use App\Model\Table\RoomEventsTable;
use App\Model\Table\RoomsTable;
use Cake\Database\Connection;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;

/**
 * Room service history before G4 (approved 2026-10-09): a room whose old
 * combined status says `maintenance` gets service status `maintenance` and
 * one `imported` event, dated when the import observes it, with no actor and
 * no reason. When the maintenance began, who started it and why were never
 * recorded, so none of that is made up (the snapshot says so). Rooms in
 * service need no event: nothing happened to them.
 *
 * Follows the backfill rule (CLAUDE.md): idempotent (a room with any room
 * event, or already off service, is skipped); auditable (`source =
 * 'import'`, correlation `import-rooms-<id>`, a logged per-property check);
 * repeatable (`bin/cake activity_backfill`, one transaction).
 */
final class RoomServiceImport
{
    /**
     * @param \Cake\Database\Connection $connection The application database.
     */
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param int|null $propertyId Limit to one property's rooms.
     * @return array{imported: int} What was added.
     */
    public function run(?int $propertyId = null): array
    {
        $result = ['imported' => 0];
        if (!$this->hasLedger()) {
            return $result;
        }
        $locator = TableRegistry::getTableLocator();
        $rooms = $locator->get('Rooms');
        /** @var \App\Model\Table\RoomEventsTable $events */
        $events = $locator->get('RoomEvents');
        $query = $rooms->find()
            ->where(['Rooms.status' => 'maintenance', 'Rooms.service_status' => RoomsTable::IN_SERVICE])
            ->where(function ($exp, $q) {
                return $exp->notExists(
                    $q->getConnection()->selectQuery('1', 'room_events')->where(['room_events.room_id = Rooms.id']),
                );
            })
            ->orderBy(['Rooms.id' => 'ASC']);
        if ($propertyId !== null) {
            $query->where(['Rooms.property_id' => $propertyId]);
        }
        $found = $query->all()->toList();

        $this->connection->transactional(function () use ($found, $rooms, $events, &$result): void {
            foreach ($found as $room) {
                $room->set('service_status', RoomsTable::MAINTENANCE);
                $rooms->saveOrFail($room, ['atomic' => false]);
                $context = new EventContext(
                    null,
                    null,
                    (int)$room->get('property_id'),
                    'import-rooms-' . $room->get('id'),
                    EventContext::SOURCE_IMPORT,
                );
                $events->record($context, RoomEventsTable::IMPORTED, $room, [
                    'columns' => ['service_before' => null, 'service_after' => RoomsTable::MAINTENANCE],
                    'snapshot' => ['under_maintenance_when_history_began' => true, 'started' => 'not recorded'],
                ]);
                $result['imported']++;
            }
        });

        Log::info(sprintf(
            'room service import run%s: %d rooms under maintenance imported',
            $propertyId !== null ? " (property $propertyId)" : '',
            $result['imported'],
        ));

        return $result;
    }

    /**
     * Per property: rooms, rooms off service, those off service with no
     * event at all (must be zero, or reported), imported events and events
     * recorded since. Logged: `info` when complete, `warning` otherwise.
     *
     * @param int|null $propertyId Limit to one property.
     * @return array<int, array<string, int|bool>>
     */
    public function check(?int $propertyId = null): array
    {
        if (!$this->hasLedger()) {
            return [];
        }
        $where = $propertyId !== null ? 'WHERE p.id = ' . (int)$propertyId : '';
        $rows = $this->connection->execute(
            "SELECT p.id,
                (SELECT COUNT(*) FROM rooms r WHERE r.property_id = p.id AND r.deleted_at IS NULL) AS rooms,
                (SELECT COUNT(*) FROM rooms r WHERE r.property_id = p.id AND r.deleted_at IS NULL
                    AND r.service_status != 'in_service') AS off_service,
                (SELECT COUNT(*) FROM rooms r WHERE r.property_id = p.id AND r.deleted_at IS NULL
                    AND (r.service_status != 'in_service' OR r.status = 'maintenance')
                    AND NOT EXISTS (SELECT 1 FROM room_events e WHERE e.room_id = r.id)) AS unrecorded,
                (SELECT COUNT(*) FROM room_events e WHERE e.property_id = p.id AND e.source = 'import') AS imported,
                (SELECT COUNT(*) FROM room_events e WHERE e.property_id = p.id
                    AND e.source != 'import') AS recorded_since
            FROM properties p $where ORDER BY p.id",
        )->fetchAll('assoc');

        $result = [];
        foreach ($rows as $row) {
            $counts = array_map('intval', $row);
            $id = $counts['id'];
            unset($counts['id']);
            $counts['complete'] = $counts['unrecorded'] === 0;
            $result[$id] = $counts;
            $line = sprintf(
                'room service check, property %d: %d rooms, %d off service, %d with no record; %d imported, '
                . '%d recorded since',
                $id,
                $counts['rooms'],
                $counts['off_service'],
                $counts['unrecorded'],
                $counts['imported'],
                $counts['recorded_since'],
            );
            $counts['complete'] ? Log::info($line) : Log::warning($line);
        }

        return $result;
    }

    /**
     * Whether the ledger exists yet (a fresh database migrates in order).
     */
    private function hasLedger(): bool
    {
        return in_array('room_events', $this->connection->getSchemaCollection()->listTables(), true);
    }
}
