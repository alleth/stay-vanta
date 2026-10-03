<?php
declare(strict_types=1);

namespace App\Model\Finance;

use App\Model\BusinessTime;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * The one definition of a property's money figures (build step 7a). Every
 * report that shows Collected or Outstanding reads it from here: Finance
 * (collections, summary, seasonality and their old /reports paths) and
 * Operations ("Collected today", the POS paid trend).
 *
 * - **Collected** in a window = settled invoices by `settled_at` (room
 *   charges, downpayments net of refunds, food charged to the room) + `paid`
 *   POS sales by `created`. Charge-to-room food already lives inside
 *   invoices, so only `paid` sales are added (no double counting).
 * - **Outstanding** = the total on open invoices right now; not tied to a
 *   window.
 *
 * Windows are stored-timezone bounds `[from, to)` built with BusinessTime
 * (the hotel's day, not UTC's); null leaves that side open. One query per
 * figure, never `GROUP BY` (ONLY_FULL_GROUP_BY on MySQL 9, see CLAUDE.md).
 *
 * Step 7a moved these queries here unchanged; what Collected means (e.g.
 * refunds on the day they happen) is step 7b's decision, made here once.
 */
class Collections
{
    use LocatorAwareTrait;

    /**
     * @param int $propertyId The property whose money this is.
     */
    public function __construct(private int $propertyId)
    {
    }

    /**
     * Collected in `[from, to)`: invoice and POS parts, each with its count,
     * and their total.
     *
     * @param string|null $from Stored-timezone lower bound, inclusive; null for no bound.
     * @param string|null $to Stored-timezone upper bound, exclusive; null for no bound.
     * @return array{invoices: array{total: float, count: int}, pos: array{total: float, count: int}, total: float}
     */
    public function collected(?string $from, ?string $to): array
    {
        $invoices = $this->settledInvoices($from, $to);
        $pos = $this->paidSales($from, $to);

        return [
            'invoices' => $invoices,
            'pos' => $pos,
            'total' => round($invoices['total'] + $pos['total'], 2),
        ];
    }

    /**
     * Collected over one of the hotel's days (YYYY-MM-DD).
     *
     * @return array{invoices: array{total: float, count: int}, pos: array{total: float, count: int}, total: float}
     */
    public function collectedOn(string $date): array
    {
        return $this->collected(BusinessTime::startOf($date), BusinessTime::endOf($date));
    }

    /**
     * Collected this week, this month, this year (the hotel's calendar) and
     * all time.
     *
     * @return array{week: float, month: float, ytd: float, all_time: float}
     */
    public function byPeriod(): array
    {
        $now = BusinessTime::now();
        $ranges = [
            'week' => BusinessTime::stored($now->startOfWeek()),
            'month' => BusinessTime::stored($now->startOfMonth()),
            'ytd' => BusinessTime::stored($now->startOfYear()),
            'all_time' => null,
        ];

        return array_map(fn(?string $from) => $this->collected($from, null)['total'], $ranges);
    }

    /**
     * Settled invoices in the window, by `settled_at`.
     *
     * @return array{total: float, count: int}
     */
    public function settledInvoices(?string $from, ?string $to): array
    {
        return $this->sum('Invoices', ['status' => 'settled'], 'settled_at', $from, $to);
    }

    /**
     * Paid POS sales in the window, by `created`.
     *
     * @return array{total: float, count: int}
     */
    public function paidSales(?string $from, ?string $to): array
    {
        return $this->sum('FoodOrders', ['payment_status' => 'paid'], 'created', $from, $to);
    }

    /**
     * What's been charged but not collected: open invoices, right now.
     *
     * @return array{total: float, count: int}
     */
    public function outstanding(): array
    {
        return $this->sum('Invoices', ['status' => 'open'], null, null, null);
    }

    /**
     * Σ total and count of a table's rows for this property.
     *
     * @param array<string, mixed> $conditions Extra conditions.
     * @return array{total: float, count: int}
     */
    private function sum(string $table, array $conditions, ?string $dateColumn, ?string $from, ?string $to): array
    {
        $query = $this->fetchTable($table)->find()
            ->where(['property_id' => $this->propertyId] + $conditions);
        if ($dateColumn !== null && $from !== null) {
            $query->where([$dateColumn . ' >=' => $from]);
        }
        if ($dateColumn !== null && $to !== null) {
            $query->where([$dateColumn . ' <' => $to]);
        }
        $row = $query->select(['s' => $query->func()->sum('total'), 'c' => $query->func()->count('*')])
            ->disableHydration()->first();

        return ['total' => round((float)($row['s'] ?? 0), 2), 'count' => (int)($row['c'] ?? 0)];
    }
}
