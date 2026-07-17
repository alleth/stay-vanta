<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * - food_menu_items.type: food | linen — separates the Food & Orders menu
 *   catalogue into two management tabs (Food / Linens) so the Linked Stock
 *   picker can be scoped to the matching inventory category.
 * - food_orders.payment_method: cash | gcash | maya | gotyme — how a `paid`
 *   order was actually settled (null for charge_to_room/unpaid).
 * - reservations.payment_status: unpaid | paid — a Front Desk operational flag
 *   the receptionist toggles once the guest has settled up, independent of
 *   the booking lifecycle (booked/checked_in/checked_out/cancelled) and of
 *   invoice settlement (Food & Orders → Invoices).
 */
class AddMenuTypeOrderPaymentAndReservationPaid extends BaseMigration
{
    public function change(): void
    {
        $this->table('food_menu_items')
            ->addColumn('type', 'string', ['limit' => 10, 'default' => 'food'])
            ->update();

        $this->table('food_orders')
            ->addColumn('payment_method', 'string', ['limit' => 10, 'null' => true])
            ->update();

        $this->table('reservations')
            ->addColumn('payment_status', 'string', ['limit' => 10, 'default' => 'unpaid'])
            ->update();
    }
}
