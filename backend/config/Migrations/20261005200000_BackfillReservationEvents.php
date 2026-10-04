<?php
declare(strict_types=1);

use App\Event\ActivityBackfill;
use Cake\Datasource\ConnectionManager;
use Migrations\BaseMigration;

/**
 * Build step 8, part 2: record reservation history from before
 * reservation_events (created as booked or walked in at `created`, checked
 * in at `checked_in_at`, checked out at `checked_out_at`, cancelled at
 * `cancelled_at`) and index it, so reservations join the Operations feed and
 * the reservation timeline. The same idempotent ActivityBackfill as steps 5
 * and 6; it never invents an actor (`receptionist_id` is only "last touched
 * by", kept in the snapshot under that name), a reason, a time or an event
 * that wasn't recorded (edits, corrections, deletions before step 8), and
 * logs what it added and its per-property check.
 *
 * down() removes only imported reservation events and their index rows.
 */
class BackfillReservationEvents extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('default');
        $backfill = new ActivityBackfill($connection);
        $backfill->run();
        $backfill->check();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('default');
        $connection->execute(
            "DELETE FROM activity_index WHERE event_table = 'reservation_events' AND correlation_id LIKE 'import-%'",
        );
        $connection->execute("DELETE FROM reservation_events WHERE source = 'import'");
    }
}
