<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * reservations.downpayment — the 50%-of-total downpayment collected when a
 * guest books in advance (check-in after the booking day). Recorded as an
 * immediately-settled invoice at booking; credited against the room charge on
 * check-out; 90% refunded (10% retained) if the booking is cancelled.
 */
class AddReservationDownpayment extends BaseMigration
{
    public function change(): void
    {
        $this->table('reservations')
            ->addColumn('downpayment', 'decimal', [
                'precision' => 10, 'scale' => 2, 'null' => true, 'after' => 'promo_rate',
            ])
            ->update();
    }
}
