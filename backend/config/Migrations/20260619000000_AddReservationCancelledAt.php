<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * reservations.cancelled_at — when a reservation was cancelled (mirrors
 * checked_out_at), so the Front Desk can count "cancelled today" and treat each
 * day as a fresh start (hiding completed transactions from previous days).
 */
class AddReservationCancelledAt extends BaseMigration
{
    public function change(): void
    {
        $this->table('reservations')
            ->addColumn('cancelled_at', 'datetime', ['null' => true, 'after' => 'checked_out_at'])
            ->update();
    }
}
