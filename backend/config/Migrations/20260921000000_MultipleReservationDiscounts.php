<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * A room can hold more than one Senior/PWD guest, and until now a booking
 * could record only one — as a bare `reservations.discount_type` enum with no
 * name and no ID number, so nothing said *who* the discount was granted to.
 *
 * This brings bookings in line with what food orders already do
 * (MultipleFoodOrderDiscounts): one `reservation_discounts` row per
 * beneficiary, each with its own name + ID for compliance, and a
 * `total_guests` count on the booking so the statutory 20% covers each
 * beneficiary's own share of the room rather than the whole room — the rule
 * RA 9994 and the PWD Magna Carta actually state (see App\Model\StatutoryDiscount).
 *
 * Unlike `food_order_discounts` there is no `amount` column. A food order is a
 * finished transaction with a stored total, so its split is snapshotted; a
 * booking's price is recomputed live by ReservationsTable::quote() from
 * whatever the room rate resolves to now, and a stored amount would be a stale
 * second opinion of it. The snapshot that matters — name, ID and peso amount
 * at the moment money changed hands — is the invoice line
 * ReservationsController::postRoomCharge() writes per beneficiary.
 *
 * Existing senior/pwd bookings are ported to a single beneficiary row: with
 * `total_guests` defaulting to 1, one beneficiary out of one guest reproduces
 * the old flat "20% off the whole subtotal" math exactly. Their name is taken
 * from the linked guest where there is one, and the ID number lands blank —
 * the old form never asked for it, and inventing one would be worse than
 * recording that it wasn't captured.
 */
class MultipleReservationDiscounts extends BaseMigration
{
    public function up(): void
    {
        $this->table('reservations')
            ->addColumn('total_guests', 'integer', [
                'null' => false,
                'default' => 1,
                'after' => 'discount_amount',
            ])
            ->update();

        $this->table('reservation_discounts')
            ->addColumn('reservation_id', 'integer', ['null' => false])
            ->addColumn('discount_type', 'string', ['limit' => 10, 'null' => false]) // senior | pwd
            ->addColumn('beneficiary_name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('id_number', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['reservation_id'])
            ->create();

        $this->execute(
            "INSERT INTO reservation_discounts
                (reservation_id, discount_type, beneficiary_name, id_number, created, modified)
             SELECT r.id, r.discount_type, COALESCE(g.full_name, 'Not recorded'), '', r.created, r.modified
             FROM reservations r
             LEFT JOIN guests g ON g.id = r.guest_id
             WHERE r.discount_type IN ('senior', 'pwd')",
        );

        $this->table('reservations')
            ->removeColumn('discount_type')
            ->update();
    }

    public function down(): void
    {
        $this->table('reservations')
            ->addColumn('discount_type', 'string', ['limit' => 10, 'default' => 'none'])
            ->update();

        // Best-effort collapse back to one beneficiary per booking (it loses
        // the others, and every name and ID number) — the same one-way caveat
        // MultipleFoodOrderDiscounts::down() accepts for its own collapse.
        $this->execute(
            'UPDATE reservations r
             JOIN (
                 SELECT reservation_id, discount_type,
                        ROW_NUMBER() OVER (PARTITION BY reservation_id ORDER BY id) AS rn
                 FROM reservation_discounts
             ) first_disc ON first_disc.reservation_id = r.id AND first_disc.rn = 1
             SET r.discount_type = first_disc.discount_type',
        );

        $this->table('reservation_discounts')->drop()->save();

        $this->table('reservations')
            ->removeColumn('total_guests')
            ->update();
    }
}
