<?php
declare(strict_types=1);

use Cake\Log\Log;
use Migrations\BaseMigration;

/**
 * G8b (L12): `rooms.status` is occupancy only. Before G8b a vacant room off
 * service also had `status = maintenance`, a copy of what `service_status`
 * already says (G4). This turns only those copies into `available`; nothing
 * is lost, because `service_status` and `room_events` keep the service state
 * and its history. Occupied rooms are untouched.
 *
 * A `maintenance` row whose service status is still `in_service` is a pre-G4
 * value the G4 import never saw: it's left as it is and counted, never
 * guessed at. `rooms.status` is operational (outside the configuration audit),
 * so no event is written; the counts are logged. Idempotent.
 */
class NormalizeRoomOccupancy extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        $changed = $this->execute("UPDATE rooms SET status = 'available'
            WHERE status = 'maintenance' AND service_status IS NOT NULL AND service_status <> 'in_service'");
        $left = (int)($this->fetchRow("SELECT COUNT(*) AS n FROM rooms
            WHERE status = 'maintenance'")['n'] ?? 0);
        Log::info(sprintf(
            'room occupancy (G8b): %d vacant room(s) off service now read available; '
            . '%d legacy maintenance value(s) left untouched',
            $changed,
            $left,
        ));
    }

    /**
     * The old rule was deterministic, so the combined value comes back exactly.
     *
     * @return void
     */
    public function down(): void
    {
        $this->execute("UPDATE rooms SET status = 'maintenance'
            WHERE status = 'available' AND service_status IS NOT NULL AND service_status <> 'in_service'");
    }
}
