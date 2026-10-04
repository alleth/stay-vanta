<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Build step 8: reservation accountability (docs/EVENTS.md, reservation_events).
 *
 * - `reservation_events`: who booked, edited, corrected, backdated, checked
 *   in or out, cancelled or deleted each reservation, when, why, what
 *   changed (before/after), in which request. `room_id` and `status_after`
 *   are typed because they're filtered.
 * - `reservations.deleted_at`: reservations are never hard-deleted again; a
 *   deleted one keeps its row, discounts and extras, hidden from every query
 *   by ReservationsTable unless asked for.
 *
 * Additive only: the previous code ignores both. No foreign keys and no
 * ON DELETE CASCADE: events outlive their subject.
 */
class CreateReservationEvents extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('reservation_events')
            ->addColumn('property_id', 'integer', ['null' => false])
            ->addColumn('reservation_id', 'integer', ['null' => false])
            ->addColumn('event_type', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('actor_id', 'integer', ['null' => true])
            ->addColumn('actor_role', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('source', 'string', ['limit' => 10, 'null' => false])
            ->addColumn('reason', 'text', ['null' => true])
            ->addColumn('changes', 'json', ['null' => true])
            ->addColumn('snapshot', 'json', ['null' => true])
            ->addColumn('correlation_id', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('occurred_at', 'datetime', ['null' => false])
            ->addColumn('corrects_event_id', 'integer', ['null' => true])
            ->addColumn('room_id', 'integer', ['null' => true])
            ->addColumn('status_after', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addIndex(['property_id', 'occurred_at'])
            ->addIndex(['reservation_id', 'id'])
            ->addIndex(['property_id', 'correlation_id'])
            ->create();

        $this->table('reservations')
            ->addColumn('deleted_at', 'datetime', ['null' => true])
            ->addIndex(['deleted_at'])
            ->update();
    }
}
