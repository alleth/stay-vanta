<?php
declare(strict_types=1);

namespace App\Model\Finance;

use App\Model\BusinessTime;
use App\Model\Table\FoodOrderEventsTable;
use App\Model\Table\InvoiceEventsTable;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Query\SelectQuery;

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

    // ---- Cash movement (build step 7c, decided 2026-10-03 as D1–D8) --------
    //
    // Collected = cash in, Refunded = cash out, Net = in − out, each on the
    // day the money moved. Built in 7c-1 beside the figures above and not yet
    // read by any report; 7c-2 switches the reports to it.

    /** The pre-7c downpayment refund: a negative line on a settled invoice. */
    public const LEGACY_REFUND_LINE = 'downpayment_refund';

    /**
     * Cash movement in `[from, to)`: what came in, what went back, and the net.
     *
     * @param string|null $from Stored-timezone lower bound, inclusive; null for no bound.
     * @param string|null $to Stored-timezone upper bound, exclusive; null for no bound.
     * @return array{collected: array{invoices: array{total: float, count: int}, pos: array{total: float, count: int}, total: float}, refunded: array{invoices: array{total: float, count: int}, pos: array{total: float, count: int}, total: float}, net: float}
     */
    public function cashMovement(?string $from, ?string $to): array
    {
        $collected = $this->cashIn($from, $to);
        $refunded = $this->refunded($from, $to);

        return [
            'collected' => $collected,
            'refunded' => $refunded,
            'net' => round($collected['total'] - $refunded['total'], 2),
        ];
    }

    /**
     * Cash in: settled invoices at the full amount they were settled for, on
     * `settled_at`, plus paid POS sales on `created` (a sale later cancelled
     * was still paid that day; its refund, if any, is cash out).
     *
     * "Full amount" differs from `total` only for an invoice from before 7c
     * carrying a downpayment refund line: that line is added back here and
     * counted as cash out on the day it was written. No row changes.
     *
     * @return array{invoices: array{total: float, count: int}, pos: array{total: float, count: int}, total: float}
     */
    public function cashIn(?string $from, ?string $to): array
    {
        $invoices = $this->settledInvoices($from, $to);
        $addBack = $this->window(
            $this->fetchTable('InvoiceLines')->find()->innerJoinWith('Invoices')->where([
                'Invoices.property_id' => $this->propertyId,
                'Invoices.status' => 'settled',
                'InvoiceLines.source_type' => self::LEGACY_REFUND_LINE,
            ]),
            'Invoices.settled_at',
            $from,
            $to,
        );
        $invoices['total'] = round($invoices['total'] - $this->total($addBack, 'InvoiceLines.amount')['total'], 2);
        $pos = $this->paidSales($from, $to);

        return ['invoices' => $invoices, 'pos' => $pos, 'total' => round($invoices['total'] + $pos['total'], 2)];
    }

    /**
     * Cash out, on the day it went back: `refunded` / `refunded_on_cancel`
     * invoice events and `refunded` POS events by `occurred_at`, plus the
     * downpayment refund lines from before 7c by the time they were written.
     * Amounts are stored negative; returned as positive sums.
     *
     * @return array{invoices: array{total: float, count: int}, pos: array{total: float, count: int}, total: float}
     */
    public function refunded(?string $from, ?string $to): array
    {
        $legacy = $this->total($this->window(
            $this->fetchTable('InvoiceLines')->find()->innerJoinWith('Invoices')->where([
                'Invoices.property_id' => $this->propertyId,
                'Invoices.status' => 'settled',
                'InvoiceLines.source_type' => self::LEGACY_REFUND_LINE,
            ]),
            'InvoiceLines.created',
            $from,
            $to,
        ), 'InvoiceLines.amount');
        $events = $this->total($this->window(
            $this->fetchTable('InvoiceEvents')->find()->where([
                'property_id' => $this->propertyId,
                'event_type IN' => InvoiceEventsTable::REFUND_TYPES,
            ]),
            'occurred_at',
            $from,
            $to,
        ), 'amount');
        $pos = $this->total($this->window(
            $this->fetchTable('FoodOrderEvents')->find()->where([
                'property_id' => $this->propertyId,
                'event_type' => FoodOrderEventsTable::REFUNDED,
            ]),
            'occurred_at',
            $from,
            $to,
        ), 'amount');

        $invoices = [
            'total' => round(-($legacy['total'] + $events['total']), 2),
            'count' => $legacy['count'] + $events['count'],
        ];
        $pos = ['total' => round(-$pos['total'], 2), 'count' => $pos['count']];

        return ['invoices' => $invoices, 'pos' => $pos, 'total' => round($invoices['total'] + $pos['total'], 2)];
    }

    /**
     * Cash movement over one of the hotel's days (YYYY-MM-DD).
     *
     * @return array<string, mixed> As cashMovement().
     */
    public function cashMovementOn(string $date): array
    {
        return $this->cashMovement(BusinessTime::startOf($date), BusinessTime::endOf($date));
    }

    /**
     * @param \Cake\ORM\Query\SelectQuery $query Query to bound.
     * @param string $column Datetime column.
     * @param string|null $from Lower bound, inclusive.
     * @param string|null $to Upper bound, exclusive.
     * @return \Cake\ORM\Query\SelectQuery
     */
    private function window(SelectQuery $query, string $column, ?string $from, ?string $to): SelectQuery
    {
        if ($from !== null) {
            $query->where([$column . ' >=' => $from]);
        }
        if ($to !== null) {
            $query->where([$column . ' <' => $to]);
        }

        return $query;
    }

    /**
     * Σ of a money column and the row count.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query.
     * @param string $column Money column.
     * @return array{total: float, count: int}
     */
    private function total(SelectQuery $query, string $column): array
    {
        $row = $query->select(['s' => $query->func()->sum($column), 'c' => $query->func()->count('*')])
            ->disableHydration()->first();

        return ['total' => round((float)($row['s'] ?? 0), 2), 'count' => (int)($row['c'] ?? 0)];
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
