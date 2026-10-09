<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Model\BusinessTime;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Pins the money figures every report shows, so moving the calculation into
 * one shared class (build step 7) can't change a number without a test failing.
 *
 * The rule being pinned: **collected** = settled invoices (by `settled_at`) +
 * `paid` POS orders (by `created`); **outstanding** = what's on open
 * invoices right now. Food charged to a room isn't collected until its invoice
 * is settled, and an unpaid order isn't collected at all.
 *
 * The scenario, one property:
 *   today:        invoice settled ₱1,000 · paid order ₱250 · charge-to-room
 *                 order ₱80 · unpaid order ₱60 · two open invoices ₱300 + ₱150
 *   40 days ago:  invoice settled ₱400 · paid order ₱70
 * Forty days back is always a different calendar month and week.
 */
class CollectionFiguresApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private string $adminToken;
    private string $receptionistToken;
    private DateTime $past;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Collection Figures Resort');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "money-admin-$tag@example.test");
        $this->receptionistToken = $this->makeUser($this->propertyId, 'receptionist', "money-desk-$tag@example.test");
        $deskId = $this->userIdFor($this->receptionistToken);

        $now = DateTime::now();
        $this->past = $now->subDays(40);
        $p = $this->propertyId;

        $invoice = fn(float $total, string $status, ?DateTime $settledAt) => $this->insertRow('Invoices', [
            'property_id' => $p, 'total' => $total, 'status' => $status, 'settled_at' => $settledAt,
        ]);
        $invoice(1000, 'settled', $now);
        $invoice(400, 'settled', $this->past);
        $invoice(300, 'open', null);
        $invoice(150, 'open', null);

        $order = fn(float $total, string $payment, DateTime $created) => $this->insertRow('FoodOrders', [
            'property_id' => $p, 'receptionist_id' => $deskId, 'status' => 'served',
            'payment_status' => $payment, 'payment_method' => $payment === 'paid' ? 'cash' : null,
            'total' => $total, 'total_diners' => 1, 'created' => $created, 'modified' => $created,
        ]);
        $order(250, 'paid', $now);
        $order(80, 'charge_to_room', $now);
        $order(60, 'unpaid', $now);
        $order(70, 'paid', $this->past);
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $token, string $url): array
    {
        $this->callAs($token, 'GET', $url);
        $this->assertResponseOk("GET $url: " . $this->_response->getBody());

        return $this->responseJson();
    }

    /** The collection report (the old /reports path was removed in G8; the figures are unchanged). */
    private const COLLECTIONS = ['/api/finance/collections'];

    public function testTodaysCollection(): void
    {
        foreach (self::COLLECTIONS as $path) {
            foreach ([$this->adminToken, $this->receptionistToken] as $token) {
                $c = $this->getJson($token, $path)['collection'];

                $this->assertSame('day', $c['scope']);
                $this->assertEquals(1250, $c['total'], $path);
                $this->assertEquals(1000, $c['invoices']['total']);
                $this->assertSame(1, $c['invoices']['count']);
                $this->assertEquals(250, $c['food_orders']['total']);
                $this->assertSame(1, $c['food_orders']['count']);
                $this->assertEquals(450, $c['outstanding']['total']);
                $this->assertSame(2, $c['outstanding']['count']);
            }
        }
    }

    public function testAPastDaysCollection(): void
    {
        $day = $this->past->setTimezone(BusinessTime::timezone())->format('Y-m-d');
        foreach (self::COLLECTIONS as $path) {
            $c = $this->getJson($this->receptionistToken, $path . '?date=' . $day)['collection'];

            $this->assertEquals(470, $c['total'], $path);
            $this->assertEquals(400, $c['invoices']['total']);
            $this->assertEquals(70, $c['food_orders']['total']);
            // Outstanding is "right now", not part of the chosen day.
            $this->assertEquals(450, $c['outstanding']['total']);
        }
    }

    public function testMonthAndRangeCollection(): void
    {
        $today = BusinessTime::now();
        $from = $this->past->setTimezone(BusinessTime::timezone())->format('Y-m-d');
        foreach (self::COLLECTIONS as $path) {
            $c = $this->getJson(
                $this->adminToken,
                sprintf('%s?month=%d&year=%d', $path, $today->format('n'), $today->format('Y')),
            )['collection'];
            $this->assertEquals(1250, $c['total'], $path);

            $c = $this->getJson(
                $this->adminToken,
                $path . '?from=' . $from . '&to=' . BusinessTime::todayString(),
            )['collection'];
            $this->assertEquals(1720, $c['total'], $path);
        }
    }

    public function testManagerCollectedByPeriod(): void
    {
        $pastIsThisYear = $this->past->setTimezone(BusinessTime::timezone())->format('Y')
            === BusinessTime::now()->format('Y');
        $new = $this->getJson($this->adminToken, '/api/finance/summary')['summary'];

        foreach (['/api/finance/summary' => $new['collected']] as $path => $by) {
            $this->assertEquals(1250, $by['week'], $path);
            $this->assertEquals(1250, $by['month'], $path);
            $this->assertEquals($pastIsThisYear ? 1720 : 1250, $by['ytd'], $path);
            $this->assertEquals(1720, $by['all_time'], $path);
        }
        $this->assertEquals(450, $new['outstanding']['total']);
        $this->assertSame(2, $new['outstanding']['count']);
    }

    public function testSeasonalityRevenueForThisMonth(): void
    {
        $now = BusinessTime::now();
        foreach (['/api/finance/seasonality'] as $path) {
            $report = $this->getJson($this->adminToken, $path . '?year=' . $now->format('Y'))['report'];
            $month = $report['months'][(int)$now->format('n') - 1];

            $this->assertEquals(1250, $month['revenue'], $path);
        }
    }

    public function testOperationsCollectedToday(): void
    {
        foreach (['/api/operations/today'] as $path) {
            $ops = $this->getJson($this->receptionistToken, $path)['operations'];

            $this->assertEquals(1250, $ops['revenue_today']['collected'], $path);
            $this->assertEquals(1000, $ops['revenue_today']['invoices']);
            $this->assertEquals(250, $ops['revenue_today']['pos']);

            $this->assertSame(3, $ops['pos']['today']['orders']);
            $this->assertEquals(250, $ops['pos']['today']['paid']);
            $this->assertEquals(80, $ops['pos']['today']['charged_to_room']);
            $this->assertEquals(60, $ops['pos']['today']['unpaid']);
        }
    }

    public function testFrontDeskOpenInvoiceCount(): void
    {
        $this->callAs($this->receptionistToken, 'GET', '/api/reservations/stats');
        $this->assertResponseOk();
        $this->assertSame(2, $this->responseJson()['open_invoices']);
    }
}
