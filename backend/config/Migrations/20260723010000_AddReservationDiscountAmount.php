<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * reservations.discount_amount — the staff-entered flat peso amount for a
 * `referral` discount (a third discount_type alongside the statutory
 * senior/pwd 20%). Unlike senior/pwd, a referral isn't a fixed rate: the
 * receptionist decides the amount at booking time. Null for every other
 * discount_type; ReservationsTable::quote() caps it at the subtotal so a
 * booking's total can never go negative.
 */
class AddReservationDiscountAmount extends BaseMigration
{
    public function change(): void
    {
        $this->table('reservations')
            ->addColumn('discount_amount', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'null' => true,
                'after' => 'discount_type',
            ])
            ->update();
    }
}
