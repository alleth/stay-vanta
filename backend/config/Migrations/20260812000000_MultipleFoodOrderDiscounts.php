<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * An order can now carry more than one Senior/PWD discount beneficiary (e.g.
 * two senior citizens at the same table), each recorded with their own name
 * + ID for compliance. The statutory 20% only ever covers a qualified
 * diner's own share of the bill (RA 9994 / the PWD Magna Carta don't
 * discount a whole table just because one diner qualifies), so `food_orders`
 * gains `total_diners` and the discount is `subtotal * (beneficiaries /
 * total_diners) * 20%` — see FoodOrdersTable::place().
 *
 * `food_order_discounts` replaces the single `discount_type` /
 * `discount_name` / `discount_id_number` columns with one row per
 * beneficiary, each snapshotting its own even split of the total discount
 * (`amount`), mirroring how `food_order_item_options` already snapshots
 * picks independent of the live config. Existing single-beneficiary orders
 * are ported over: with `total_diners` defaulting to 1, one beneficiary
 * against a 1-diner order reproduces the old flat "20% off the whole
 * subtotal" math exactly.
 */
class MultipleFoodOrderDiscounts extends BaseMigration
{
    public function up(): void
    {
        $this->table('food_orders')
            ->addColumn('total_diners', 'integer', ['null' => false, 'default' => 1])
            ->update();

        $this->table('food_order_discounts')
            ->addColumn('food_order_id', 'integer', ['null' => false])
            ->addColumn('discount_type', 'string', ['limit' => 10, 'null' => false]) // senior | pwd
            ->addColumn('beneficiary_name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('id_number', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('amount', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => false, 'default' => 0])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['food_order_id'])
            ->create();

        // Port each existing single-beneficiary order. The old discount was
        // `subtotal * 0.20` and `total = subtotal - discount + cooking`, so
        // `discount = (total - cooking) * 0.25` recovers it without needing
        // a stored subtotal column.
        $this->execute(
            "INSERT INTO food_order_discounts
                (food_order_id, discount_type, beneficiary_name, id_number, amount, created, modified)
             SELECT id, discount_type, discount_name, discount_id_number,
                    ROUND((total - cooking_charge) * 0.25, 2), created, modified
             FROM food_orders
             WHERE discount_type IN ('senior', 'pwd')",
        );

        $this->table('food_orders')
            ->removeColumn('discount_type')
            ->removeColumn('discount_name')
            ->removeColumn('discount_id_number')
            ->update();
    }

    public function down(): void
    {
        $this->table('food_orders')
            ->addColumn('discount_type', 'string', ['limit' => 10, 'default' => 'none'])
            ->addColumn('discount_name', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('discount_id_number', 'string', ['limit' => 50, 'null' => true])
            ->update();

        // Best-effort collapse back to one beneficiary per order (loses the
        // others, and their exact per-beneficiary split, if there was more
        // than one) — same one-way caveat SeparateReferralDiscount's down()
        // accepts for its own irreversible collapse.
        $this->execute(
            'UPDATE food_orders fo
             JOIN (
                 SELECT food_order_id, discount_type, beneficiary_name, id_number,
                        ROW_NUMBER() OVER (PARTITION BY food_order_id ORDER BY id) AS rn
                 FROM food_order_discounts
             ) first_disc ON first_disc.food_order_id = fo.id AND first_disc.rn = 1
             SET fo.discount_type = first_disc.discount_type,
                 fo.discount_name = first_disc.beneficiary_name,
                 fo.discount_id_number = first_disc.id_number',
        );

        $this->table('food_order_discounts')->drop()->save();

        $this->table('food_orders')
            ->removeColumn('total_diners')
            ->update();
    }
}
