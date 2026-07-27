<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Referral is no longer a `discount_type` of its own — it now stacks with
 * senior/pwd instead of being mutually exclusive with them (a guest can be
 * a senior citizen *and* have a referral discount). `discount_amount`
 * already held the flat referral peso amount and keeps that same meaning;
 * only `discount_type` needs normalizing back to 'none' on existing rows so
 * they still pass ReservationsTable::DISCOUNT_TYPES validation (which no
 * longer includes 'referral').
 */
class SeparateReferralDiscount extends BaseMigration
{
    public function up(): void
    {
        $this->execute("UPDATE reservations SET discount_type = 'none' WHERE discount_type = 'referral'");
    }

    public function down(): void
    {
        $this->execute(
            "UPDATE reservations SET discount_type = 'referral' " .
            'WHERE discount_type = \'none\' AND discount_amount IS NOT NULL',
        );
    }
}
