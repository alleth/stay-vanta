<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * booking_sources — the admin-configurable list of OTA booking sources
 * (Cocotel, Agoda, Trip.com, TripAdvisor, or whatever a property actually
 * sells through) that the New Reservation form's Source dropdown and the
 * Promo Rates tab draw from, instead of a fixed hardcoded list. `code` is the
 * stable identifier stored on `reservations.source` / `promo_rates.source`
 * (generated from the name at creation and never changed); `name` is the
 * editable display label. 'walk_in' is NOT a row here — it stays a fixed,
 * always-available constant since it's not an OTA and never carries a promo
 * rate. `BookingSourcesTable::seedDefaultsFor()` lazily seeds a new
 * property's first four rows to match the previously hardcoded list, so
 * existing reservations/promo rates keep resolving.
 */
class CreateBookingSources extends BaseMigration
{
    public function change(): void
    {
        $this->table('booking_sources')
            ->addColumn('property_id', 'integer', ['null' => false])
            ->addColumn('code', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['property_id'])
            ->addIndex(['property_id', 'code'], ['unique' => true])
            ->create();
    }
}
