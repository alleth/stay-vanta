<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Two things an online booking has that a walk-in doesn't.
 *
 * `booking_reference` is the channel's own confirmation number (the Agoda or
 * Cocotel code on the guest's printout) — what you quote back to the OTA when
 * a stay is disputed or a remittance doesn't match. Required for an online
 * booking, always null for a walk-in, which has no channel to reference.
 *
 * `sold_rate` is the nightly rate the channel sold the room for, gross —
 * before it takes its commission. It is deliberately NOT part of pricing:
 * `promo_rate` remains what the property charges and what quote() bills, and
 * this is recorded alongside it so the OTA's price can be reconciled against
 * what they eventually remit. Nullable: the figure isn't always to hand when
 * the booking is entered.
 */
class AddOnlineBookingFields extends BaseMigration
{
    /**
     * Add the channel reference and sold rate to reservations.
     */
    public function change(): void
    {
        $this->table('reservations')
            ->addColumn('booking_reference', 'string', [
                'limit' => 100,
                'null' => true,
                'after' => 'source',
            ])
            ->addColumn('sold_rate', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'null' => true,
                'after' => 'promo_rate',
            ])
            ->update();
    }
}
