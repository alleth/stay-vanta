<?php
declare(strict_types=1);

use App\Event\MembershipImport;
use App\Event\RoomServiceImport;
use Cake\Cache\Cache;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Migrations\BaseMigration;

/**
 * Room service accountability (G4): grant the Manager preset
 * `rooms.room.remove_from_service` and `rooms.room.view_history`, then give
 * each room already under maintenance its service status and one `imported`
 * event, with no actor, reason or start time (see App\Event\RoomServiceImport).
 * Idempotent and re-runnable (`bin/cake activity_backfill`); the check is
 * logged per property.
 */
class ImportRoomService extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        TableRegistry::getTableLocator()->clear();
        Cache::clear('_cake_model_');
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('default');
        (new MembershipImport($connection))->seedRoles();
        $import = new RoomServiceImport($connection);
        $import->run();
        $import->check();
    }

    /**
     * Remove only what the import added.
     *
     * @return void
     */
    public function down(): void
    {
        $this->execute("UPDATE rooms SET service_status = 'in_service' WHERE id IN
            (SELECT room_id FROM room_events WHERE source = 'import')");
        $this->execute("DELETE FROM room_events WHERE source = 'import'");
        $this->execute("DELETE FROM role_permissions WHERE permission IN
            ('rooms.room.remove_from_service', 'rooms.room.view_history')");
    }
}
