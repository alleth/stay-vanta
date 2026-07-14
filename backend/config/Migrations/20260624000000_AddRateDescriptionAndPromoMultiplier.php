<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * room_rates.description — what the guest gets at that rate (amenities & bed
 * type, e.g. "Queen bed, A/C, free breakfast for 2"), shown on the Rates tab.
 *
 * promo_rates.rate becomes `multiplier`: an OTA promo is now a multiple of the
 * room's original (base) rate — e.g. ×2 — instead of an absolute nightly
 * price. Booking computes base × multiplier and stamps that amount on
 * reservations.promo_rate.
 */
class AddRateDescriptionAndPromoMultiplier extends BaseMigration
{
    public function change(): void
    {
        $this->table('room_rates')
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true, 'after' => 'name'])
            ->update();

        $this->table('promo_rates')
            ->renameColumn('rate', 'multiplier')
            ->update();
    }
}
