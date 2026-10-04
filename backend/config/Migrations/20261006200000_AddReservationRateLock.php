<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Build step 9, part 2 (C1, approved 2026-10-04): a booking's nightly rate is
 * fixed when it's made. `nightly_rate` is the base rate resolved at booking
 * (or when an edit changes its room or source); `rate_source` records where it
 * came from (the room-rate row and its value, the promo row and multiplier).
 * Nullable: bookings made before keep today's live rate until edited; nothing
 * is stamped retroactively.
 */
class AddReservationRateLock extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('reservations')
            ->addColumn('nightly_rate', 'decimal', [
                'precision' => 12, 'scale' => 2, 'null' => true, 'after' => 'promo_rate',
            ])
            ->addColumn('rate_source', 'json', ['null' => true, 'after' => 'nightly_rate'])
            ->update();
    }
}
