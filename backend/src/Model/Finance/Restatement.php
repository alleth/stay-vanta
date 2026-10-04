<?php
declare(strict_types=1);

namespace App\Model\Finance;

use App\Model\BusinessTime;
use App\Model\Table\FoodOrderEventsTable;
use App\Model\Table\InvoiceEventsTable;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * What switching Collected to cash movement (build step 7c-2) will change,
 * for one property, computed from the data as it is and changing nothing.
 *
 * Only refunds move figures: a refund is now counted on the day the money
 * went back instead of against the day it was collected. So the days and
 * months to list are those of every refund (its own day and its invoice's or
 * sale's collection day); each is compared old figure vs new. Also checked:
 * all-time Net under cash movement equals all-time Collected before (each
 * refund is subtracted exactly once either way), and the paid POS sales that
 * were cancelled with no refund on record, which stay collected because no
 * refund is ever invented.
 *
 * Run on deploy by a log-only migration (7c-1) and by
 * `bin/cake cash_restatement`, so the list is read before the switch.
 */
class Restatement
{
    use LocatorAwareTrait;

    /**
     * @param int $propertyId The property.
     */
    public function __construct(private int $propertyId)
    {
    }

    /**
     * Log the report for every property (or one).
     *
     * @param int|null $propertyId Only this property.
     * @return array<int, array<string, mixed>> Reports by property id.
     */
    public static function logAll(?int $propertyId = null): array
    {
        $query = (new self(0))->fetchTable('Properties')->find()->select(['id'])->orderBy(['id' => 'ASC']);
        if ($propertyId !== null) {
            $query->where(['id' => $propertyId]);
        }
        $reports = [];
        foreach ($query->all() as $property) {
            $reports[(int)$property->id] = (new self((int)$property->id))->log();
        }

        return $reports;
    }

    /**
     * @return array{days: list<array<string, mixed>>, months: list<array<string, mixed>>, all_time: array<string, mixed>, pos_cancelled_paid: array{count: int, total: float}}
     */
    public function report(): array
    {
        $money = new Collections($this->propertyId);
        [$days, $months] = $this->refundDates();

        $rows = [];
        foreach ($days as $day) {
            $rows[] = $this->compare($day, BusinessTime::startOf($day), BusinessTime::endOf($day), $money);
        }
        $monthRows = [];
        foreach ($months as $month) {
            $next = (new DateTimeImmutable($month . '-01'))->modify('+1 month')->format('Y-m-d');
            $first = BusinessTime::startOf($month . '-01');
            $monthRows[] = $this->compare($month, $first, BusinessTime::startOf($next), $money);
        }

        $before = $money->historical(null, null)['net'];
        $after = $money->cashMovement(null, null)['net'];
        $cancelled = $this->fetchTable('FoodOrders')->find()->where([
            'property_id' => $this->propertyId,
            'payment_status' => 'paid',
            'status' => 'cancelled',
        ]);
        $cancelledRow = $cancelled
            ->select(['s' => $cancelled->func()->sum('total'), 'c' => $cancelled->func()->count('*')])
            ->disableHydration()->first();

        return [
            'days' => $rows,
            'months' => $monthRows,
            'all_time' => ['before' => $before, 'net_after' => $after, 'holds' => $before === $after],
            'pos_cancelled_paid' => [
                'count' => (int)($cancelledRow['c'] ?? 0),
                'total' => round((float)($cancelledRow['s'] ?? 0), 2),
            ],
        ];
    }

    /**
     * Log the report, one line per changed day and month plus a summary;
     * a failed invariant is logged as a warning.
     *
     * @return array<string, mixed> The report.
     */
    public function log(): array
    {
        $report = $this->report();
        $p = $this->propertyId;
        foreach (['days' => 'day', 'months' => 'month'] as $key => $label) {
            foreach ($report[$key] as $r) {
                if (!$r['changes']) {
                    continue;
                }
                Log::info(sprintf(
                    'cash restatement, property %d, %s %s: collected %.2f before; '
                    . 'after collected %.2f, refunded %.2f, net %.2f',
                    $p,
                    $label,
                    $r['period'],
                    $r['before'],
                    $r['collected'],
                    $r['refunded'],
                    $r['net'],
                ));
            }
        }
        $changedDays = count(array_filter($report['days'], fn($r) => $r['changes']));
        $changedMonths = count(array_filter($report['months'], fn($r) => $r['changes']));
        $line = sprintf(
            'cash restatement, property %d: %d day(s) and %d month(s) change; '
            . 'all time %.2f before, net %.2f after (%s); '
            . '%d cancelled paid POS sale(s) totalling %.2f stay collected (no refund on record)',
            $p,
            $changedDays,
            $changedMonths,
            $report['all_time']['before'],
            $report['all_time']['net_after'],
            $report['all_time']['holds'] ? 'invariant holds' : 'INVARIANT FAILED',
            $report['pos_cancelled_paid']['count'],
            $report['pos_cancelled_paid']['total'],
        );
        $report['all_time']['holds'] ? Log::info($line) : Log::warning($line);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function compare(string $period, string $from, string $to, Collections $money): array
    {
        $before = $money->historical($from, $to)['net'];
        $after = $money->cashMovement($from, $to);

        return [
            'period' => $period,
            'before' => $before,
            'collected' => $after['collected']['total'],
            'refunded' => $after['refunded']['total'],
            'net' => $after['net'],
            'changes' => $before !== $after['net'] || $after['refunded']['total'] !== 0.0,
        ];
    }

    /**
     * The hotel days and months touched by a refund: the day the money went
     * back, and the day the refunded invoice or sale was collected.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function refundDates(): array
    {
        $moments = [];
        $legacy = $this->fetchTable('InvoiceLines')->find()
            ->select(['refunded_at' => 'InvoiceLines.created', 'collected_at' => 'Invoices.settled_at'])
            ->innerJoinWith('Invoices')
            ->where([
                'Invoices.property_id' => $this->propertyId,
                'Invoices.status' => 'settled',
                'InvoiceLines.source_type' => Collections::LEGACY_REFUND_LINE,
            ])
            ->disableHydration()->all();
        foreach ($legacy as $row) {
            $moments[] = $row['refunded_at'];
            $moments[] = $row['collected_at'];
        }
        $invoiceRefunds = $this->fetchTable('InvoiceEvents')->find()
            ->select(['refunded_at' => 'InvoiceEvents.occurred_at', 'collected_at' => 'Invoices.settled_at'])
            ->innerJoin(['Invoices' => 'invoices'], ['Invoices.id = InvoiceEvents.invoice_id'])
            ->where([
                'InvoiceEvents.property_id' => $this->propertyId,
                'InvoiceEvents.event_type IN' => InvoiceEventsTable::REFUND_TYPES,
            ])
            ->disableHydration()->all();
        foreach ($invoiceRefunds as $row) {
            $moments[] = $row['refunded_at'];
            $moments[] = $row['collected_at'];
        }
        $saleRefunds = $this->fetchTable('FoodOrderEvents')->find()
            ->select(['refunded_at' => 'FoodOrderEvents.occurred_at', 'collected_at' => 'FoodOrders.created'])
            ->innerJoin(['FoodOrders' => 'food_orders'], ['FoodOrders.id = FoodOrderEvents.food_order_id'])
            ->where([
                'FoodOrderEvents.property_id' => $this->propertyId,
                'FoodOrderEvents.event_type' => FoodOrderEventsTable::REFUNDED,
            ])
            ->disableHydration()->all();
        foreach ($saleRefunds as $row) {
            $moments[] = $row['refunded_at'];
            $moments[] = $row['collected_at'];
        }

        $days = [];
        foreach (array_filter($moments) as $moment) {
            $days[$this->hotelDay($moment)] = true;
        }
        $days = array_keys($days);
        sort($days);
        $months = array_values(array_unique(array_map(fn($d) => substr($d, 0, 7), $days)));

        return [$days, $months];
    }

    /**
     * The hotel's calendar day of a stored (UTC) moment.
     */
    private function hotelDay(mixed $moment): string
    {
        $utc = $moment instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($moment)
            : new DateTimeImmutable((string)$moment, new DateTimeZone('UTC'));

        return $utc->setTimezone(new DateTimeZone(BusinessTime::timezone()))->format('Y-m-d');
    }
}
