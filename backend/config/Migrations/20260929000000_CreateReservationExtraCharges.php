<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * The admin's custom extra charges (Front Desk → Extra Charges) could be
 * configured but never put on a booking — only the built-in early check-in
 * fee was ever billed. This is the missing link: one row per charge picked on
 * a reservation, with a quantity (2 × extra towel).
 *
 * The charge's name and unit amount are snapshotted, like
 * `food_order_item_options` snapshots a pick: the admin can reprice,
 * deactivate or delete the charge afterwards without changing what a booking
 * that already carries it is billed. `extra_charge_id` stays as a plain
 * reference (no foreign key) for the same reason — the charge may be gone.
 */
class CreateReservationExtraCharges extends BaseMigration
{
    public function change(): void
    {
        $this->table('reservation_extra_charges')
            ->addColumn('reservation_id', 'integer', ['null' => false])
            ->addColumn('extra_charge_id', 'integer', ['null' => true])
            ->addColumn('name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('amount', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => false])
            ->addColumn('quantity', 'integer', ['null' => false, 'default' => 1])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['reservation_id'])
            ->create();
    }
}
