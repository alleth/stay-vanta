<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Finance;

use App\Model\BusinessTime;
use App\Model\Finance\Collections;
use App\Test\TestCase\Controller\Api\ApiScenarioTrait;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Build step 7a moved every Collected / Outstanding query into
 * App\Model\Finance\Collections without changing a figure. This test keeps
 * the queries as they were before the move (copied verbatim from
 * FinanceController and OperationsController at f920156) as the reference,
 * and holds the shared class and the API to them on data chosen to break a
 * careless rewrite: amounts in odd cents, sales exactly on the hotel's day
 * boundaries, every POS payment state (incl. a cancelled paid sale), open
 * invoices, and a second property's money that must never count.
 *
 * Step 7b may change what Collected means; when it does, this reference is
 * retired deliberately (and CollectionFiguresApiTest's pins updated), not
 * edited to match.
 */
class CollectionsEquivalenceTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private string $adminToken;

    /**
     * @var list<string> The hotel days the scenario touches.
     */
    private array $days = [];

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Collections Equivalence Inn');
        $other = $this->createProperty('Collections Equivalence Other');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "coll-admin-$tag@example.test");
        $deskId = $this->userIdFor($this->makeUser($this->propertyId, 'receptionist', "coll-desk-$tag@example.test"));

        $today = BusinessTime::todayString();
        $yesterday = BusinessTime::today()->subDays(1)->format('Y-m-d');
        $tomorrow = BusinessTime::today()->addDays(1)->format('Y-m-d');
        $longAgo = BusinessTime::today()->subDays(45)->format('Y-m-d');
        $this->days = [$longAgo, $yesterday, $today, $tomorrow];

        // Moments on the hotel's clock, as stored (UTC) values.
        $first = fn(string $day) => new DateTime(BusinessTime::startOf($day));
        $last = fn(string $day) => (new DateTime(BusinessTime::endOf($day)))->subSeconds(1);
        $noon = fn(string $day) => (new DateTime(BusinessTime::startOf($day)))->addHours(12);

        foreach ([$this->propertyId, $other] as $p) {
            $invoice = fn(string $total, string $status, ?DateTime $at) => $this->insertRow('Invoices', [
                'property_id' => $p, 'total' => $total, 'status' => $status, 'settled_at' => $at,
            ]);
            $invoice('1000.10', 'settled', $first($today));
            $invoice('333.33', 'settled', $last($today));
            $invoice('0.01', 'settled', $first($yesterday));
            $invoice('499.99', 'settled', $last($yesterday));
            $invoice('2500.00', 'settled', $noon($longAgo));
            $invoice('120.00', 'settled', $first($tomorrow));
            $invoice('0.00', 'settled', $noon($today));
            $invoice('300.30', 'open', null);
            $invoice('150.15', 'open', null);

            $order = fn(string $total, string $status, string $payment, DateTime $at) => $this->insertRow('FoodOrders', [
                'property_id' => $p, 'receptionist_id' => $deskId, 'status' => $status,
                'payment_status' => $payment, 'payment_method' => $payment === 'paid' ? 'cash' : null,
                'total' => $total, 'total_diners' => 1, 'created' => $at, 'modified' => $at,
            ]);
            $order('250.25', 'served', 'paid', $first($today));
            $order('99.99', 'open', 'paid', $last($today));
            $order('45.50', 'cancelled', 'paid', $noon($today));
            $order('80.00', 'served', 'charge_to_room', $noon($today));
            $order('60.00', 'open', 'unpaid', $noon($today));
            $order('70.07', 'served', 'paid', $last($yesterday));
            $order('15.00', 'served', 'paid', $noon($longAgo));
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    // ---- The reference: the pre-7a queries, verbatim ----------------------

    /**
     * FinanceController::collections() before 7a (also Operations' invoice
     * part and its POS trend, which used the same two queries per day).
     *
     * @return array<string, mixed>
     */
    private function oldWindow(string $from, string $to): array
    {
        $propertyId = $this->propertyId;
        $inv = $this->fetchTable('Invoices')->find()->where([
            'property_id' => $propertyId,
            'status' => 'settled',
            'settled_at >=' => $from,
            'settled_at <' => $to,
        ]);
        $invRow = $inv->select(['s' => $inv->func()->sum('total'), 'c' => $inv->func()->count('*')])
            ->disableHydration()->first();

        $food = $this->fetchTable('FoodOrders')->find()->where([
            'property_id' => $propertyId,
            'payment_status' => 'paid',
            'created >=' => $from,
            'created <' => $to,
        ]);
        $foodRow = $food->select(['s' => $food->func()->sum('total'), 'c' => $food->func()->count('*')])
            ->disableHydration()->first();

        $invTotal = round((float)($invRow['s'] ?? 0), 2);
        $foodTotal = round((float)($foodRow['s'] ?? 0), 2);

        return [
            'invoices' => ['total' => $invTotal, 'count' => (int)($invRow['c'] ?? 0)],
            'food_orders' => ['total' => $foodTotal, 'count' => (int)($foodRow['c'] ?? 0)],
            'total' => round($invTotal + $foodTotal, 2),
        ];
    }

    /**
     * FinanceController::collectedByPeriod() before 7a.
     *
     * @return array<string, float>
     */
    private function oldByPeriod(): array
    {
        $propertyId = $this->propertyId;
        $now = BusinessTime::now();
        $ranges = [
            'week' => BusinessTime::stored($now->startOfWeek()),
            'month' => BusinessTime::stored($now->startOfMonth()),
            'ytd' => BusinessTime::stored($now->startOfYear()),
            'all_time' => null,
        ];

        $invoices = $this->fetchTable('Invoices');
        $foodOrders = $this->fetchTable('FoodOrders');
        $collected = [];
        foreach ($ranges as $key => $from) {
            $inv = $invoices->find()
                ->where(['property_id' => $propertyId, 'status' => 'settled']);
            $food = $foodOrders->find()
                ->where(['property_id' => $propertyId, 'payment_status' => 'paid']);
            if ($from !== null) {
                $inv->where(['settled_at >=' => $from]);
                $food->where(['created >=' => $from]);
            }
            $invTotal = (float)$inv->select(['s' => $inv->func()->sum('total')])->first()->s;
            $foodTotal = (float)$food->select(['s' => $food->func()->sum('total')])->first()->s;
            $collected[$key] = round($invTotal + $foodTotal, 2);
        }

        return $collected;
    }

    /**
     * FinanceController::outstanding() before 7a.
     *
     * @return array{total: float, count: int}
     */
    private function oldOutstanding(): array
    {
        $query = $this->fetchTable('Invoices')->find()
            ->where(['property_id' => $this->propertyId, 'status' => 'open']);
        $row = $query->select(['s' => $query->func()->sum('total'), 'c' => $query->func()->count('*')])
            ->disableHydration()->first();

        return ['total' => round((float)($row['s'] ?? 0), 2), 'count' => (int)($row['c'] ?? 0)];
    }

    // ---- The comparisons ---------------------------------------------------

    /**
     * @return list<array{0: string, 1: string}> Every window the reports use: each day, the month, a range.
     */
    private function windows(): array
    {
        $windows = array_map(fn($d) => [BusinessTime::startOf($d), BusinessTime::endOf($d)], $this->days);
        $now = BusinessTime::now();
        $windows[] = [BusinessTime::stored($now->startOfMonth()), BusinessTime::stored($now->startOfMonth()->addMonths(1))];
        $windows[] = [BusinessTime::startOf($this->days[0]), BusinessTime::endOf($this->days[3])];

        return $windows;
    }

    public function testTheSharedCalculationMatchesThePre7aQueries(): void
    {
        $money = new Collections($this->propertyId);
        foreach ($this->windows() as [$from, $to]) {
            $old = $this->oldWindow($from, $to);
            $new = $money->collected($from, $to);
            $this->assertSame($old['invoices'], $new['invoices'], "invoices in [$from, $to)");
            $this->assertSame($old['food_orders'], $new['pos'], "POS in [$from, $to)");
            $this->assertSame($old['total'], $new['total'], "total in [$from, $to)");
            $this->assertSame($old['invoices'], $money->settledInvoices($from, $to));
            $this->assertSame($old['food_orders'], $money->paidSales($from, $to));
        }
        foreach ($this->days as $day) {
            $this->assertSame(
                $money->collected(BusinessTime::startOf($day), BusinessTime::endOf($day)),
                $money->collectedOn($day),
            );
        }
        $this->assertSame($this->oldByPeriod(), $money->byPeriod());
        $this->assertSame($this->oldOutstanding(), $money->outstanding());

        // The scenario really exercises what it claims to.
        $today = $this->oldWindow(BusinessTime::startOf($this->days[2]), BusinessTime::endOf($this->days[2]));
        $this->assertSame(['total' => 1333.43, 'count' => 3], $today['invoices']);
        $this->assertSame(['total' => 395.74, 'count' => 3], $today['food_orders']);
        $this->assertSame(['total' => 450.45, 'count' => 2], $this->oldOutstanding());
    }

    public function testEveryReportServesTheSameFiguresAsBefore(): void
    {
        foreach ($this->days as $day) {
            $old = $this->oldWindow(BusinessTime::startOf($day), BusinessTime::endOf($day));
            foreach (['/api/finance/collections', '/api/reports/daily-collection'] as $path) {
                $this->callAs($this->adminToken, 'GET', "$path?date=$day");
                $this->assertResponseOk();
                $c = $this->responseJson()['collection'];
                $this->assertEquals($old['invoices'], $c['invoices'], "$path $day invoices");
                $this->assertEquals($old['food_orders'], $c['food_orders'], "$path $day POS");
                $this->assertEquals($old['total'], $c['total'], "$path $day total");
                $this->assertEquals($this->oldOutstanding(), $c['outstanding']);
            }
        }

        $now = BusinessTime::now();
        $monthFrom = BusinessTime::stored($now->startOfMonth());
        $monthTo = BusinessTime::stored($now->startOfMonth()->addMonths(1));
        $this->callAs($this->adminToken, 'GET', sprintf('/api/finance/collections?month=%d&year=%d', $now->month, $now->year));
        $this->assertEquals($this->oldWindow($monthFrom, $monthTo)['total'], $this->responseJson()['collection']['total']);
        $this->callAs($this->adminToken, 'GET', "/api/finance/collections?from={$this->days[0]}&to={$this->days[3]}");
        $this->assertEquals(
            $this->oldWindow(BusinessTime::startOf($this->days[0]), BusinessTime::endOf($this->days[3]))['total'],
            $this->responseJson()['collection']['total'],
        );

        $this->callAs($this->adminToken, 'GET', '/api/finance/summary');
        $summary = $this->responseJson()['summary'];
        $this->assertEquals($this->oldByPeriod(), $summary['collected']);
        $this->assertEquals($this->oldOutstanding(), $summary['outstanding']);
        $this->callAs($this->adminToken, 'GET', '/api/reports/admin-dashboard');
        $this->assertEquals($this->oldByPeriod(), $this->responseJson()['dashboard']['revenue']);

        $this->callAs($this->adminToken, 'GET', "/api/finance/seasonality?year={$now->year}");
        foreach ($this->responseJson()['report']['months'] as $m) {
            $first = sprintf('%04d-%02d-01', $now->year, $m['month']);
            $next = $m['month'] === 12 ? sprintf('%04d-01-01', $now->year + 1) : sprintf('%04d-%02d-01', $now->year, $m['month'] + 1);
            $this->assertEquals(
                $this->oldWindow(BusinessTime::startOf($first), BusinessTime::startOf($next))['total'],
                $m['revenue'],
                "seasonality month {$m['month']}",
            );
        }

        $this->callAs($this->adminToken, 'GET', '/api/operations/today');
        $this->assertResponseOk();
        $ops = $this->responseJson()['operations'];
        $today = $this->oldWindow(BusinessTime::startOf($this->days[2]), BusinessTime::endOf($this->days[2]));
        $this->assertEquals(
            ['collected' => $today['total'], 'invoices' => $today['invoices']['total'], 'pos' => $today['food_orders']['total']],
            $ops['revenue_today'],
        );
        foreach ($ops['pos']['trend'] as $point) {
            $this->assertEquals(
                $this->oldWindow(BusinessTime::startOf($point['date']), BusinessTime::endOf($point['date']))['food_orders']['total'],
                $point['total'],
                "POS trend {$point['date']}",
            );
        }
    }
}
