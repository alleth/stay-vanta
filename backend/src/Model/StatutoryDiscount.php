<?php
declare(strict_types=1);

namespace App\Model;

/**
 * The Philippine statutory Senior Citizen / PWD discount (RA 9994 and the PWD
 * Magna Carta), and how it splits across the people it actually covers.
 *
 * The rule the law states, and the one both modules implement: the 20% covers
 * a qualified person's *own* share of a bill, not the whole bill just because
 * one person on it qualifies. So the discount is
 * `subtotal * (beneficiaries / payers) * 20%`, where `payers` is however many
 * people the bill is split between — diners on a food order
 * (`food_orders.total_diners`), guests on a booking (`reservations.total_guests`).
 *
 * The total is computed in whole cents first and then handed out evenly, any
 * leftover cent going to the earliest beneficiaries, so the shares always sum
 * to exactly the total: each is posted as its own invoice line, and a third of
 * a cent lost in rounding would leave the folio disagreeing with the total it
 * is supposed to add up to.
 *
 * It lives here rather than on either table because Food & Orders and Front
 * Desk apply the identical rule to different bills, and two copies of money
 * arithmetic drift. FoodOrdersTable::splitStatutoryDiscount() and
 * ReservationsTable::quote() both come through this.
 */
final class StatutoryDiscount
{
    /** Statutory Senior Citizen / PWD discount. */
    public const RATE = 0.20;

    /** What a beneficiary can qualify as. */
    public const TYPES = ['senior', 'pwd'];

    /**
     * Split the discount on `$subtotal` across `$beneficiaryCount` qualified
     * people out of `$payers` sharing the bill.
     *
     * @return array{total: float, shares: list<float>}
     */
    public static function split(float $subtotal, int $payers, int $beneficiaryCount): array
    {
        if ($beneficiaryCount < 1 || $payers < 1) {
            return ['total' => 0.0, 'shares' => []];
        }

        $totalCents = (int)round($subtotal * $beneficiaryCount / $payers * self::RATE * 100);
        $baseCents = intdiv($totalCents, $beneficiaryCount);
        $remainder = $totalCents % $beneficiaryCount;

        // Cast deliberately: PHP's `/` hands back an int when the division is
        // exact, so an even 200.00 discount would come out as int(200) while
        // 6.67 came out as a float. Callers are promised floats throughout.
        $shares = [];
        for ($i = 0; $i < $beneficiaryCount; $i++) {
            $shares[] = (float)(($baseCents + ($i < $remainder ? 1 : 0)) / 100);
        }

        return ['total' => (float)($totalCents / 100), 'shares' => $shares];
    }

    /**
     * How a beneficiary's type reads on an invoice line — "Senior" / "PWD",
     * not the stored code.
     */
    public static function label(string $type): string
    {
        return $type === 'senior' ? 'Senior' : 'PWD';
    }
}
