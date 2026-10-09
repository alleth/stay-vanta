<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Room service accountability (final review G4, approved 2026-10-09 as
 * R1–R5). A room's service availability (in service, maintenance, out of
 * service) becomes its own column with one writer (Rooms), separate from
 * occupancy, which only check-in and check-out set. Every service change is
 * a `room_events` row: who, before, after, why, request id.
 *
 * Additive only: `rooms.status` keeps being written (occupied, or
 * maintenance while a vacant room isn't in service, else available), so the
 * previous code reads it as before. No foreign keys.
 */
class CreateRoomEvents extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('rooms')
            ->addColumn('service_status', 'string', [
                'limit' => 20, 'null' => false, 'default' => 'in_service', 'after' => 'status',
            ])
            ->update();

        $this->table('room_events')
            ->addColumn('property_id', 'integer', ['null' => false])
            ->addColumn('room_id', 'integer', ['null' => false])
            ->addColumn('event_type', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('service_before', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('service_after', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('actor_id', 'integer', ['null' => true])
            ->addColumn('actor_role', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('source', 'string', ['limit' => 10, 'null' => false])
            ->addColumn('reason', 'text', ['null' => true])
            ->addColumn('changes', 'json', ['null' => true])
            ->addColumn('snapshot', 'json', ['null' => true])
            ->addColumn('correlation_id', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('occurred_at', 'datetime', ['null' => false])
            ->addColumn('corrects_event_id', 'integer', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addIndex(['property_id', 'occurred_at'])
            ->addIndex(['room_id', 'id'])
            ->addIndex(['property_id', 'correlation_id'])
            ->create();
    }
}
