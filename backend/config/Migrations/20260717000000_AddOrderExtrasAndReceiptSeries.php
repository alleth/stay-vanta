<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * - inventory_items.parent_id: consumables can be itemized — sub-items nest
 *   under a parent item (one level deep).
 * - food_orders: statutory PWD/Senior discount (20% off the items subtotal,
 *   with the beneficiary's name + ID number) and an optional cooking charge
 *   (guest-brought food cooked by the kitchen).
 * - food_order_items: lines can be custom (no menu item) — a typed
 *   description + price, e.g. cooking of guest-brought food.
 * - receipt_series: registered physical booklet series (sales invoice /
 *   official receipt numbers); settling an invoice can consume the next
 *   number, stamped onto invoices.invoice_number / or_number.
 */
class AddOrderExtrasAndReceiptSeries extends BaseMigration
{
    public function change(): void
    {
        $this->table('inventory_items')
            ->addColumn('parent_id', 'integer', ['null' => true, 'default' => null])
            ->addIndex(['parent_id'])
            ->update();

        $this->table('food_orders')
            ->addColumn('discount_type', 'string', ['limit' => 10, 'default' => 'none']) // none | senior | pwd
            ->addColumn('discount_name', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('discount_id_number', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('cooking_charge', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => 0])
            ->update();

        $this->table('food_order_items')
            ->changeColumn('food_menu_item_id', 'integer', ['null' => true])
            ->addColumn('description', 'string', ['limit' => 150, 'null' => true]) // custom (non-menu) lines
            ->update();

        $this->table('invoices')
            ->addColumn('invoice_number', 'string', ['limit' => 30, 'null' => true]) // physical sales invoice no.
            ->addColumn('or_number', 'string', ['limit' => 30, 'null' => true]) // official receipt no.
            ->update();

        $this->table('receipt_series')
            ->addColumn('property_id', 'integer')
            ->addColumn('type', 'string', ['limit' => 20]) // invoice | official_receipt
            ->addColumn('prefix', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('start_number', 'integer')
            ->addColumn('end_number', 'integer')
            ->addColumn('next_number', 'integer')
            ->addColumn('pad_length', 'integer', ['default' => 0]) // zero-padding width, from the typed start
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['property_id'])
            ->create();
    }
}
