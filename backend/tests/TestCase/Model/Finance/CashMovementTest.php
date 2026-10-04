<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Finance;

use App\Model\BusinessTime;
use App\Model\Finance\Collections;
use App\Model\Finance\Restatement;
use App\Test\TestCase\Controller\Api\ApiScenarioTrait;
use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Build step 7c: the cash-movement model (Collected = cash in, Refunded =
 * cash out, Net = in − out, each on the day the money moved), the
 * restatement report that lists what switching to it changes against the
 * model before (`historical`), the reports switched to it (7c-2), and the
 * `historical` rollback setting giving the old figures back from the same
 * data.
 *
 * Pinned with the 7b review's worked example 1 (a ₱1,000 downpayment
 * collected 28 Sep, ₱900 refunded 2 Oct as a pre-7c refund line), plus a
 * cancelled paid sale with no refund on record (stays collected) and, on a
 * second property, the refund events 7c-2 will write.
 */
class CashMovementTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $legacy;
    private int $events;
    private string $adminToken;

    /** A moment on the hotel's clock, as stored. */
    private function at(string $day, int $hour): DateTime
    {
        return (new DateTime(BusinessTime::startOf($day)))->addHours($hour);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->legacy = $this->createProperty('Cash Movement Legacy Inn');
        $this->events = $this->createProperty('Cash Movement Events Inn');
        $this->adminToken = $this->makeUser($this->legacy, 'admin', "cash-admin-$tag@example.test");
        $deskId = $this->userIdFor($this->makeUser($this->legacy, 'receptionist', "cash-desk-$tag@example.test"));

        // Property 1: the downpayment from before 7c, its refund a line on the settled invoice.
        $dp = $this->insertRow('Invoices', [
            'property_id' => $this->legacy, 'total' => '100.00', 'status' => 'settled',
            'settled_at' => $this->at('2026-09-28', 10),
        ]);
        $this->insertRow('InvoiceLines', [
            'invoice_id' => $dp, 'description' => 'Downpayment (50%)', 'amount' => '1000.00',
            'source_type' => 'downpayment', 'source_id' => 1, 'created' => $this->at('2026-09-28', 10),
        ]);
        $this->insertRow('InvoiceLines', [
            'invoice_id' => $dp, 'description' => 'Downpayment refund on cancellation (10% retained)',
            'amount' => '-900.00', 'source_type' => 'downpayment_refund', 'source_id' => 1,
            'created' => $this->at('2026-10-02', 9),
        ]);
        $this->insertRow('Invoices', [
            'property_id' => $this->legacy, 'total' => '500.00', 'status' => 'settled',
            'settled_at' => $this->at('2026-10-02', 15),
        ]);
        $this->insertRow('Invoices', ['property_id' => $this->legacy, 'total' => '300.00', 'status' => 'open']);
        $this->insertRow('FoodOrders', [
            'property_id' => $this->legacy, 'receptionist_id' => $deskId, 'status' => 'cancelled',
            'payment_status' => 'paid', 'payment_method' => 'cash', 'total' => '500.00', 'total_diners' => 1,
            'created' => $this->at('2026-10-03', 12), 'modified' => $this->at('2026-10-03', 13),
        ]);

        // Property 2: refunds as the events 7c-2 writes (inserted directly here).
        $invoice = $this->insertRow('Invoices', [
            'property_id' => $this->events, 'total' => '500.00', 'status' => 'settled',
            'settled_at' => $this->at('2026-10-02', 15),
        ]);
        $sale = $this->insertRow('FoodOrders', [
            'property_id' => $this->events, 'receptionist_id' => $deskId, 'status' => 'cancelled',
            'payment_status' => 'paid', 'payment_method' => 'gcash', 'total' => '300.00', 'total_diners' => 1,
            'created' => $this->at('2026-10-03', 12), 'modified' => $this->at('2026-10-04', 10),
        ]);
        $connection = $this->getTableLocator()->get('Invoices')->getConnection();
        $event = fn(string $at) => [
            'property_id' => $this->events, 'actor_id' => null, 'actor_role' => null, 'source' => 'system',
            'reason' => 'Test refund', 'correlation_id' => 'test-' . $tag, 'occurred_at' => $at, 'created' => $at,
        ];
        $connection->insert('invoice_events', $event(BusinessTime::stored($this->at('2026-10-05', 10))) + [
            'invoice_id' => $invoice, 'event_type' => 'refunded', 'amount' => '-200.00', 'method' => 'cash',
        ]);
        $connection->insert('food_order_events', $event(BusinessTime::stored($this->at('2026-10-04', 10))) + [
            'food_order_id' => $sale, 'event_type' => 'refunded', 'amount' => '-300.00', 'method' => 'gcash',
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    /**
     * @return array{0: float, 1: float, 2: float} Collected, refunded, net.
     */
    private function cash(int $propertyId, string $from, string $to): array
    {
        $m = (new Collections($propertyId))->cashMovement($from, $to);

        return [$m['collected']['total'], $m['refunded']['total'], $m['net']];
    }

    private function day(int $propertyId, string $day): array
    {
        return $this->cash($propertyId, BusinessTime::startOf($day), BusinessTime::endOf($day));
    }

    public function testARefundCountsOnTheDayTheMoneyWentBack(): void
    {
        // Worked example 1: the collection day keeps the full downpayment.
        $this->assertSame([1000.0, 0.0, 1000.0], $this->day($this->legacy, '2026-09-28'));
        $this->assertSame([500.0, 900.0, -400.0], $this->day($this->legacy, '2026-10-02'));
        // A cancelled paid sale with no refund on record stays collected.
        $this->assertSame([500.0, 0.0, 500.0], $this->day($this->legacy, '2026-10-03'));
        $this->assertSame(
            [1000.0, 0.0, 1000.0],
            $this->cash($this->legacy, BusinessTime::startOf('2026-09-01'), BusinessTime::startOf('2026-10-01')),
        );
        $this->assertSame(
            [1000.0, 900.0, 100.0],
            $this->cash($this->legacy, BusinessTime::startOf('2026-10-01'), BusinessTime::startOf('2026-11-01')),
        );

        $money = new Collections($this->legacy);
        $all = $money->cashMovement(null, null);
        $this->assertSame(['total' => 1500.0, 'count' => 2], $all['collected']['invoices']);
        $this->assertSame(['total' => 900.0, 'count' => 1], $all['refunded']['invoices']);
        // The invariant: all-time Net equals all-time Collected as reported today.
        $this->assertSame($money->historical(null, null)['net'], $all['net']);
        $this->assertSame(1100.0, $all['net']);
        // Outstanding is untouched by any of it.
        $this->assertSame(['total' => 300.0, 'count' => 1], $money->outstanding());
    }

    public function testRefundEventsCountOnTheirOwnDay(): void
    {
        $this->assertSame([500.0, 0.0, 500.0], $this->day($this->events, '2026-10-02'));
        $this->assertSame([300.0, 0.0, 300.0], $this->day($this->events, '2026-10-03'));
        $this->assertSame([0.0, 300.0, -300.0], $this->day($this->events, '2026-10-04'));
        $this->assertSame([0.0, 200.0, -200.0], $this->day($this->events, '2026-10-05'));

        $m = (new Collections($this->events))->cashMovementOn('2026-10-04');
        $this->assertSame(['total' => 300.0, 'count' => 1], $m['refunded']['pos']);
        $this->assertSame(['total' => 0.0, 'count' => 0], $m['refunded']['invoices']);
        $m = (new Collections($this->events))->cashMovementOn('2026-10-05');
        $this->assertSame(['total' => 200.0, 'count' => 1], $m['refunded']['invoices']);
        // Another property's refunds never count.
        $this->assertSame([0.0, 0.0, 0.0], $this->day($this->legacy, '2026-10-05'));
    }

    public function testTheRestatementListsExactlyWhatWillChange(): void
    {
        $report = (new Restatement($this->legacy))->report();
        $changed = array_values(array_filter($report['days'], fn($r) => $r['changes']));
        $this->assertSame([
            ['period' => '2026-09-28', 'before' => 100.0, 'collected' => 1000.0, 'refunded' => 0.0, 'net' => 1000.0, 'changes' => true],
            ['period' => '2026-10-02', 'before' => 500.0, 'collected' => 500.0, 'refunded' => 900.0, 'net' => -400.0, 'changes' => true],
        ], $changed);
        $this->assertSame([
            ['period' => '2026-09', 'before' => 100.0, 'collected' => 1000.0, 'refunded' => 0.0, 'net' => 1000.0, 'changes' => true],
            ['period' => '2026-10', 'before' => 1000.0, 'collected' => 1000.0, 'refunded' => 900.0, 'net' => 100.0, 'changes' => true],
        ], $report['months']);
        $this->assertSame(['before' => 1100.0, 'net_after' => 1100.0, 'holds' => true], $report['all_time']);
        $this->assertSame(['count' => 1, 'total' => 500.0], $report['pos_cancelled_paid']);

        $events = (new Restatement($this->events))->report();
        $this->assertSame(
            // Under the old model the refunds lowered the collection days (2 and
            // 3 Oct); now they land on the days the money went back (4 and 5 Oct).
            ['2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05'],
            array_column(array_filter($events['days'], fn($r) => $r['changes']), 'period'),
        );
    }

    public function testReportsShowCashMovement(): void
    {
        $expect = [
            '2026-09-28' => [1000.0, 0.0, 1000.0],
            '2026-10-02' => [500.0, 900.0, -400.0],
            '2026-10-03' => [500.0, 0.0, 500.0],
        ];
        foreach ($expect as $day => [$in, $out, $net]) {
            $this->callAs($this->adminToken, 'GET', "/api/finance/collections?date=$day");
            $this->assertResponseOk();
            $c = $this->responseJson()['collection'];
            $this->assertEquals($in, $c['collected']['total'], "cash in on $day");
            $this->assertEquals($out, $c['refunded']['total'], "cash out on $day");
            $this->assertEquals($net, $c['net'], "net on $day");
            $this->assertEquals($net, $c['total'], 'total is Net Collected');
            $this->assertSame('cash', $c['model']);
        }
        // The pre-7c refund line is listed, with what wasn't recorded left empty.
        $this->callAs($this->adminToken, 'GET', '/api/finance/collections?date=2026-10-02');
        $refunds = $this->responseJson()['collection']['refunds'];
        $this->assertCount(1, $refunds);
        $this->assertEquals(['refund_line', 900, null, false], [
            $refunds[0]['type'], $refunds[0]['amount'], $refunds[0]['method'], $refunds[0]['recorded'],
        ]);
        $this->assertEquals(['not_recorded' => 900], $this->responseJson()['collection']['refunded']['by_method']);
    }

    public function testTheHistoricalSettingGivesTheOldFiguresBack(): void
    {
        Configure::write('App.collectedModel', 'historical');
        try {
            foreach (['2026-09-28' => 100.0, '2026-10-02' => 500.0, '2026-10-03' => 500.0] as $day => $total) {
                $this->callAs($this->adminToken, 'GET', "/api/finance/collections?date=$day");
                $this->assertResponseOk();
                $c = $this->responseJson()['collection'];
                $this->assertEquals($total, $c['total'], "Collected on $day as before 7c-2");
                $this->assertEquals(0, $c['refunded']['total']);
                $this->assertSame('historical', $c['model']);
            }
            // Refund events count against the day their invoice or sale was collected.
            $m = (new Collections($this->events))->figuresOn('2026-10-02');
            $this->assertSame(300.0, $m['net']);
            $m = (new Collections($this->events))->figuresOn('2026-10-03');
            $this->assertSame(0.0, $m['net']);
        } finally {
            Configure::delete('App.collectedModel');
        }
    }
}
