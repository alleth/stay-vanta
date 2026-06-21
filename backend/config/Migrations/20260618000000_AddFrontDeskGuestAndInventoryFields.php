<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Adds:
 * - reservations.checked_in_at / checked_out_at — the actual timestamps of the
 *   check-in/out *events* (distinct from the planned check_in/check_out dates),
 *   so Front Desk can show an audit log of when a guest really arrived/left.
 * - guests.address / contact_number / email — fuller guest details captured at
 *   booking; contact_number + email also feed guest de-duplication.
 * - inventory_items.tracking_type — 'consumable' (depletes when used) vs
 *   'reusable' (issued out then returned). total_quantity is the units owned of
 *   a reusable item; quantity stays the count currently available on the shelf,
 *   so in-use = total_quantity - quantity.
 */
class AddFrontDeskGuestAndInventoryFields extends BaseMigration
{
    public function change(): void
    {
        $this->table('reservations')
            ->addColumn('checked_in_at', 'datetime', ['null' => true, 'after' => 'check_out'])
            ->addColumn('checked_out_at', 'datetime', ['null' => true, 'after' => 'checked_in_at'])
            ->update();

        $this->table('guests')
            ->addColumn('address', 'string', ['limit' => 255, 'null' => true, 'after' => 'nationality'])
            ->addColumn('contact_number', 'string', ['limit' => 50, 'null' => true, 'after' => 'address'])
            ->addColumn('email', 'string', ['limit' => 191, 'null' => true, 'after' => 'contact_number'])
            ->update();

        $this->table('inventory_items')
            ->addColumn('tracking_type', 'string', [
                'limit' => 20,
                'default' => 'consumable', // consumable | reusable
                'after' => 'name',
            ])
            ->addColumn('total_quantity', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'null' => true, // only meaningful for reusable items
                'after' => 'quantity',
            ])
            ->update();
    }
}
