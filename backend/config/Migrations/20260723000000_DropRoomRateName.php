<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * room_rates.name — dropped. It duplicated what `description` (the amenities
 * & bed type) and the rate's linked room already convey, and wasn't shown or
 * used anywhere outside the Rates tab's own table.
 */
class DropRoomRateName extends BaseMigration
{
    public function change(): void
    {
        $this->table('room_rates')
            ->removeColumn('name')
            ->update();
    }
}
