<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * promo_rates — admin-configured OTA nightly prices. One row per property +
 * booking source (Cocotel, Agoda, Trip.com, TripAdvisor), optionally targeting
 * a specific room (room_id null = property-wide). The New Reservation form
 * auto-fills its (read-only) promo rate from these rows when a source is
 * picked, and the backend resolves the same rate authoritatively on booking —
 * receptionists never type promo prices by hand.
 */
class CreatePromoRates extends BaseMigration
{
    public function change(): void
    {
        $this->table('promo_rates')
            ->addColumn('property_id', 'integer', ['null' => false])
            ->addColumn('room_id', 'integer', ['null' => true])
            ->addColumn('source', 'string', ['limit' => 30, 'null' => false])
            ->addColumn('rate', 'decimal', ['precision' => 10, 'scale' => 2, 'null' => false, 'default' => 0])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['property_id'])
            ->addIndex(['property_id', 'source'])
            ->create();
    }
}
