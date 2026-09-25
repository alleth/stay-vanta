<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * A discount the booking channel granted the guest.
 *
 * OTAs run their own promotions — an Agoda "10% off" deal, a Cocotel ₱300
 * voucher — and the guest arrives having been promised that price. The desk
 * records what was promised on the booking so quote() bills it: either a
 * percentage of the room subtotal or a fixed peso amount off the stay, which
 * is what `channel_discount_type` (percent | fixed) says and
 * `channel_discount_value` quantifies.
 *
 * It belongs to the channel, so it's only ever set on an online booking — a
 * walk-in has no channel to have promised anything. Both columns are null
 * when there's no channel discount, which is every existing booking.
 */
class AddChannelDiscount extends BaseMigration
{
    /**
     * Add the channel discount type and value to reservations.
     */
    public function change(): void
    {
        $this->table('reservations')
            ->addColumn('channel_discount_type', 'string', [
                'limit' => 10,
                'null' => true,
                'after' => 'sold_rate',
            ])
            ->addColumn('channel_discount_value', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'null' => true,
                'after' => 'channel_discount_type',
            ])
            ->update();
    }
}
