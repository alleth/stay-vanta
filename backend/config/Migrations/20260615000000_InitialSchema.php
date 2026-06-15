<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Initial StayVanta schema.
 *
 * Accountability rule: any table that represents stock/asset state or a
 * mutating action carries a `receptionist_id` (and movement logs carry it
 * NOT NULL) so the platform can always answer "who was responsible at the
 * time". See config/Migrations and the docs in CLAUDE.md.
 */
class InitialSchema extends BaseMigration
{
    public function change(): void
    {
        // ---- properties (hotels & resorts) -----------------------------
        $this->table('properties')
            ->addColumn('name', 'string', ['limit' => 150])
            ->addColumn('type', 'string', ['limit' => 20, 'default' => 'hotel']) // hotel | resort
            ->addColumn('address', 'text', ['null' => true])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->create();

        // ---- users (owner | admin | receptionist) ----------------------
        $this->table('users')
            ->addColumn('property_id', 'integer', ['null' => true]) // null for platform owner
            ->addColumn('role', 'string', ['limit' => 20]) // owner | admin | receptionist
            ->addColumn('name', 'string', ['limit' => 150])
            ->addColumn('email', 'string', ['limit' => 191])
            ->addColumn('password', 'string', ['limit' => 255])
            ->addColumn('api_token', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('token_expires', 'datetime', ['null' => true])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['email'], ['unique' => true])
            ->addIndex(['api_token'])
            ->addIndex(['property_id'])
            ->create();

        // ---- inventory categories (Food Stocks, Hygiene, Linens, ...) ---
        // `parent_id` allows sub-groups, e.g. Food Stocks -> Drinks.
        $this->table('inventory_categories')
            ->addColumn('property_id', 'integer')
            ->addColumn('parent_id', 'integer', ['null' => true])
            ->addColumn('name', 'string', ['limit' => 100])
            ->addColumn('kind', 'string', ['limit' => 20, 'default' => 'other']) // food_stock | hygiene | linen | utensil | other
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['property_id'])
            ->addIndex(['parent_id'])
            ->create();

        // ---- inventory items --------------------------------------------
        $this->table('inventory_items')
            ->addColumn('property_id', 'integer')
            ->addColumn('inventory_category_id', 'integer')
            ->addColumn('name', 'string', ['limit' => 150])
            ->addColumn('unit', 'string', ['limit' => 30, 'default' => 'pcs'])
            ->addColumn('quantity', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => 0])
            ->addColumn('reorder_level', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => 0])
            // accountability: last receptionist who moved this item
            ->addColumn('last_receptionist_id', 'integer', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['property_id'])
            ->addIndex(['inventory_category_id'])
            ->create();

        // ---- stock movements (the accountability ledger) ----------------
        $this->table('stock_movements')
            ->addColumn('property_id', 'integer')
            ->addColumn('inventory_item_id', 'integer')
            ->addColumn('receptionist_id', 'integer') // NOT NULL: who performed it
            ->addColumn('direction', 'string', ['limit' => 3]) // in | out
            ->addColumn('quantity', 'decimal', ['precision' => 12, 'scale' => 2])
            ->addColumn('reason', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('reference_type', 'string', ['limit' => 50, 'null' => true]) // e.g. food_order
            ->addColumn('reference_id', 'integer', ['null' => true])
            ->addColumn('note', 'text', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addIndex(['inventory_item_id'])
            ->addIndex(['receptionist_id'])
            ->create();

        // ---- guests ------------------------------------------------------
        $this->table('guests')
            ->addColumn('property_id', 'integer')
            ->addColumn('full_name', 'string', ['limit' => 191])
            ->addColumn('nationality', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('guest_type', 'string', ['limit' => 10, 'default' => 'local']) // local | foreign
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['property_id'])
            ->create();

        // ---- rooms -------------------------------------------------------
        $this->table('rooms')
            ->addColumn('property_id', 'integer')
            ->addColumn('room_number', 'string', ['limit' => 30])
            ->addColumn('room_type', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'available']) // available | occupied | maintenance
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['property_id'])
            ->create();

        // ---- room rates --------------------------------------------------
        $this->table('room_rates')
            ->addColumn('property_id', 'integer')
            ->addColumn('room_id', 'integer', ['null' => true])
            ->addColumn('name', 'string', ['limit' => 100])
            ->addColumn('base_rate', 'decimal', ['precision' => 12, 'scale' => 2])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['property_id'])
            ->create();

        // ---- reservations -----------------------------------------------
        $this->table('reservations')
            ->addColumn('property_id', 'integer')
            ->addColumn('room_id', 'integer', ['null' => true])
            ->addColumn('guest_id', 'integer', ['null' => true])
            ->addColumn('receptionist_id', 'integer', ['null' => true]) // accountability
            ->addColumn('check_in', 'date', ['null' => true])
            ->addColumn('check_out', 'date', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'booked']) // booked | checked_in | checked_out | cancelled
            ->addColumn('source', 'string', ['limit' => 20, 'default' => 'walk_in']) // walk_in | cocotel | agoda | trip_com | tripadvisor
            ->addColumn('promo_rate', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => true])
            ->addColumn('discount_type', 'string', ['limit' => 10, 'default' => 'none']) // none | senior | pwd
            ->addColumn('additional_beds', 'integer', ['default' => 0])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['property_id'])
            ->addIndex(['room_id'])
            ->addIndex(['guest_id'])
            ->create();

        // ---- food menu ---------------------------------------------------
        // Each menu item is linked to a Food Stock inventory item so that
        // ordering decrements stock.
        $this->table('food_menu_items')
            ->addColumn('property_id', 'integer')
            ->addColumn('inventory_item_id', 'integer', ['null' => true])
            ->addColumn('name', 'string', ['limit' => 150])
            ->addColumn('price', 'decimal', ['precision' => 12, 'scale' => 2])
            ->addColumn('is_available', 'boolean', ['default' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['property_id'])
            ->create();

        // ---- food orders -------------------------------------------------
        $this->table('food_orders')
            ->addColumn('property_id', 'integer')
            ->addColumn('guest_id', 'integer', ['null' => true])
            ->addColumn('room_id', 'integer', ['null' => true]) // reflect on room (record only)
            ->addColumn('reservation_id', 'integer', ['null' => true])
            ->addColumn('receptionist_id', 'integer') // accountability: who took the order
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'open']) // open | served | cancelled
            ->addColumn('payment_status', 'string', ['limit' => 15, 'default' => 'unpaid']) // paid | charge_to_room | unpaid
            ->addColumn('total', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => 0])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['property_id'])
            ->addIndex(['guest_id'])
            ->addIndex(['room_id'])
            ->create();

        // ---- food order items -------------------------------------------
        $this->table('food_order_items')
            ->addColumn('food_order_id', 'integer')
            ->addColumn('food_menu_item_id', 'integer')
            ->addColumn('quantity', 'integer', ['default' => 1])
            ->addColumn('unit_price', 'decimal', ['precision' => 12, 'scale' => 2])
            ->addColumn('line_total', 'decimal', ['precision' => 12, 'scale' => 2])
            ->addIndex(['food_order_id'])
            ->create();

        // ---- invoices (charge-to-room food + room charges land here) -----
        $this->table('invoices')
            ->addColumn('property_id', 'integer')
            ->addColumn('guest_id', 'integer', ['null' => true])
            ->addColumn('reservation_id', 'integer', ['null' => true])
            ->addColumn('total', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 15, 'default' => 'open']) // open | settled
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['property_id'])
            ->addIndex(['guest_id'])
            ->create();

        $this->table('invoice_lines')
            ->addColumn('invoice_id', 'integer')
            ->addColumn('description', 'string', ['limit' => 191])
            ->addColumn('amount', 'decimal', ['precision' => 12, 'scale' => 2])
            ->addColumn('source_type', 'string', ['limit' => 50, 'null' => true]) // food_order | reservation
            ->addColumn('source_id', 'integer', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addIndex(['invoice_id'])
            ->create();
    }
}
