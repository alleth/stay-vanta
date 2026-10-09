<?php
declare(strict_types=1);

namespace App\Model\Finance;

use App\Model\BusinessTime;
use App\Model\Table\FoodOrderEventsTable;
use App\Model\Table\InvoiceEventsTable;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Query\SelectQuery;

/**
 * The one definition of a property's money figures. Every report that shows
 * Collected, Refunded, Net Collected or Outstanding reads it from here:
 * Finance (collections, summary, seasonality)
 * and Operations (today's money, the POS trend).
 *
 * Since build step 7c-2 (decided 2026-10-03, D1–D8) the figures are **cash
 * movement**: Collected = cash in (settled invoices at the full amount
 * settled, by `settled_at`; paid POS sales by `created`), Refunded = cash out
 * on the day it went back (refund events by `occurred_at`; pre-7c downpayment
 * refund lines by when they were written), Net Collected = in − out. A past
 * day never changes once it has ended. Outstanding = open invoices now.
 *
 * historical() still computes the model before 7c-2 from the same data, but
 * only for the read-only restatement comparison (`bin/cake cash_restatement`).
 * Reports always use cash movement: the one-release rollback switch
 * (`APP_COLLECTED_MODEL`) was retired in G8 (L-D4).
 *
 * Windows are stored-timezone bounds `[from, to)` built with BusinessTime
 * (the hotel's day, not UTC's); null leaves that side open. One query per
 * figure, never `GROUP BY` (ONLY_FULL_GROUP_BY on MySQL 9, see CLAUDE.md).
 */
class Collections
{
    use LocatorAwareTrait;

    /** The model every report uses (reported as `model` for API stability). */
    public const MODEL_CASH = 'cash';

    /**
     * @param int $propertyId The property whose money this is.
     */
    public function __construct(private int $propertyId)
    {
    }

    /**
     * The figures every report shows for `[from, to)`: cash in (invoice and
     * POS parts), cash out (the same parts) and Net Collected.
     *
     * @param string|null $from Stored-timezone lower bound, inclusive; null for no bound.
     * @param string|null $to Stored-timezone upper bound, exclusive; null for no bound.
     * @return array<string, mixed> `collected` and `refunded` ({invoices, pos} each {total, count}, total), `net`.
     */
    public function figures(?string $from, ?string $to): array
    {
        return $this->cashMovement($from, $to);
    }

    /**
     * figures() over one of the hotel's days (YYYY-MM-DD).
     *
     * @return array<string, mixed> As figures().
     */
    public function figuresOn(string $date): array
    {
        return $this->figures(BusinessTime::startOf($date), BusinessTime::endOf($date));
    }

    /**
     * figures() for this week, this month, this year (the hotel's calendar)
     * and all time.
     *
     * @return array<string, array<string, mixed>> Keyed week, month, ytd, all_time.
     */
    public function figuresByPeriod(): array
    {
        $now = BusinessTime::now();
        $ranges = [
            'week' => BusinessTime::stored($now->startOfWeek()),
            'month' => BusinessTime::stored($now->startOfMonth()),
            'ytd' => BusinessTime::stored($now->startOfYear()),
            'all_time' => null,
        ];

        return array_map(fn(?string $from) => $this->figures($from, null), $ranges);
    }

    /**
     * The model before 7c-2, from today's data: settled invoices by
     * `settled_at` and paid sales by `created`, each less the refund events
     * against it, so a refund lowers the day its invoice or sale was
     * collected (pre-7c refund lines already sit inside the invoice total).
     * Nothing counts as Refunded and Net equals Collected. Used only as the
     * "before" of the read-only restatement report, never by a screen.
     *
     * @return array<string, mixed> As figures().
     */
    public function historical(?string $from, ?string $to): array
    {
        $invoices = $this->settledInvoices($from, $to);
        $invoiceRefunds = $this->total($this->window(
            $this->fetchTable('InvoiceEvents')->find()
                ->innerJoin(['Invoices' => 'invoices'], ['Invoices.id = InvoiceEvents.invoice_id'])
                ->where([
                    'InvoiceEvents.property_id' => $this->propertyId,
                    'InvoiceEvents.event_type IN' => InvoiceEventsTable::REFUND_TYPES,
                ]),
            'Invoices.settled_at',
            $from,
            $to,
        ), 'InvoiceEvents.amount');
        $invoices['total'] = round($invoices['total'] + $invoiceRefunds['total'], 2);

        $pos = $this->paidSales($from, $to);
        $posRefunds = $this->total($this->window(
            $this->fetchTable('FoodOrderEvents')->find()
                ->innerJoin(['FoodOrders' => 'food_orders'], ['FoodOrders.id = FoodOrderEvents.food_order_id'])
                ->where([
                    'FoodOrderEvents.property_id' => $this->propertyId,
                    'FoodOrderEvents.event_type' => FoodOrderEventsTable::REFUNDED,
                ]),
            'FoodOrders.created',
            $from,
            $to,
        ), 'FoodOrderEvents.amount');
        $pos['total'] = round($pos['total'] + $posRefunds['total'], 2);

        $collected = ['invoices' => $invoices, 'pos' => $pos, 'total' => round($invoices['total'] + $pos['total'], 2)];
        $none = ['total' => 0.0, 'count' => 0];

        return [
            'collected' => $collected,
            'refunded' => ['invoices' => $none, 'pos' => $none, 'total' => 0.0],
            'net' => $collected['total'],
        ];
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
    // day the money moved. What figures() returns under the `cash` model.

    /**
     * Every refund in `[from, to)`, newest first, for the Refunds list: when,
     * invoice or sale, guest, amount (positive), method, who, why. A refund
     * line from before 7c reads `recorded: false` for whatever wasn't
     * stored (its method; its actor unless step 6 recorded it), never guessed.
     *
     * @return list<array<string, mixed>>
     */
    public function refunds(?string $from, ?string $to): array
    {
        $items = [];
        $invoiceEvents = $this->window($this->fetchTable('InvoiceEvents')->find()->where([
            'property_id' => $this->propertyId,
            'event_type IN' => InvoiceEventsTable::REFUND_TYPES,
        ]), 'occurred_at', $from, $to)->all();
        foreach ($invoiceEvents as $e) {
            $items[] = [
                'id' => 'invoice-event-' . $e->id, 'kind' => 'invoice', 'type' => $e->event_type,
                'at' => $e->occurred_at, 'invoice_id' => (int)$e->invoice_id, 'order_id' => null,
                'amount' => round(-(float)$e->amount, 2), 'method' => $e->method,
                'actor_id' => $e->actor_id, 'recorded' => true, 'reason' => $e->reason,
            ];
        }

        $legacy = $this->window($this->fetchTable('InvoiceLines')->find()->innerJoinWith('Invoices')->where([
            'Invoices.property_id' => $this->propertyId,
            'Invoices.status' => 'settled',
            'InvoiceLines.source_type' => self::LEGACY_REFUND_LINE,
        ]), 'InvoiceLines.created', $from, $to)->all();
        foreach ($legacy as $line) {
            // Step 6 recorded who wrote the refund line (refund_recorded); older ones are unknown.
            $recordedBy = $this->fetchTable('InvoiceEvents')->find()
                ->select(['actor_id'])
                ->where(['invoice_line_id' => $line->id, 'event_type' => InvoiceEventsTable::REFUND_RECORDED])
                ->disableHydration()->first();
            $items[] = [
                'id' => 'invoice-line-' . $line->id, 'kind' => 'invoice', 'type' => 'refund_line',
                'at' => $line->created, 'invoice_id' => (int)$line->invoice_id, 'order_id' => null,
                'amount' => round(-(float)$line->amount, 2), 'method' => null,
                'actor_id' => $recordedBy['actor_id'] ?? null, 'recorded' => false, 'reason' => null,
            ];
        }

        $saleEvents = $this->window($this->fetchTable('FoodOrderEvents')->find()->where([
            'property_id' => $this->propertyId,
            'event_type' => FoodOrderEventsTable::REFUNDED,
        ]), 'occurred_at', $from, $to)->all();
        foreach ($saleEvents as $e) {
            $items[] = [
                'id' => 'sale-event-' . $e->id, 'kind' => 'pos', 'type' => $e->event_type,
                'at' => $e->occurred_at, 'invoice_id' => null, 'order_id' => (int)$e->food_order_id,
                'amount' => round(-(float)$e->amount, 2), 'method' => $e->method,
                'actor_id' => $e->actor_id, 'recorded' => true, 'reason' => $e->reason,
            ];
        }

        usort($items, fn($a, $b) => [$b['at'], $b['id']] <=> [$a['at'], $a['id']]);

        return $items;
    }

    /**
     * Refunded per method (`not_recorded` for refunds from before 7c).
     *
     * @param list<array<string, mixed>> $refunds From refunds().
     * @return array<string, float>
     */
    public static function byMethod(array $refunds): array
    {
        $totals = [];
        foreach ($refunds as $r) {
            $key = $r['method'] ?? 'not_recorded';
            $totals[$key] = round(($totals[$key] ?? 0.0) + $r['amount'], 2);
        }
        ksort($totals);

        return $totals;
    }

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
