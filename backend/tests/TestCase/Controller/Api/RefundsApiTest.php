<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Model\BusinessTime;
use App\Model\Finance\Collections;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Build step 7c-2: refunds are cash out, recorded as their own events
 * (decided 2026-10-03, D3/D4/D7/D8 and S1), never as changes to a settled
 * invoice. Pinned here, per the approval's extra requirement:
 * - a refund is recorded exactly once: a repeated request (same key) is 409
 *   and records nothing, a sale is refunded once, a cancellation refunds once;
 * - a refund never alters the settled invoice or its lines;
 * - every refund carries amount, method, actor, role, reason, occurred_at and
 *   the request's correlation id;
 * - all-time Net Collected equals all-time Collected under the model before.
 */
class RefundsApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private string $adminToken;
    private string $deskToken;
    private int $roomId;
    private int $guestId;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Refunds Resort');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "refund-admin-$tag@example.test");
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', "refund-desk-$tag@example.test");
        $this->roomId = $this->insertRow('Rooms', [
            'property_id' => $this->propertyId, 'room_number' => 'R-1', 'room_type' => 'Deluxe', 'status' => 'available',
        ]);
        $this->insertRow('RoomRates', ['property_id' => $this->propertyId, 'room_id' => $this->roomId, 'base_rate' => 1000]);
        $this->guestId = $this->insertRow('Guests', [
            'property_id' => $this->propertyId, 'full_name' => 'Rafa Cruz', 'guest_type' => 'local',
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    private function day(int $offset): string
    {
        return BusinessTime::today()->addDays($offset)->format('Y-m-d');
    }

    /** A two-night stay, billed (₱2,000) and settled by Front Desk today; returns the invoice id. */
    private function settledStay(): int
    {
        $id = $this->insertRow('Reservations', [
            'property_id' => $this->propertyId, 'room_id' => $this->roomId, 'guest_id' => $this->guestId,
            'receptionist_id' => $this->userIdFor($this->deskToken), 'status' => 'checked_in', 'source' => 'walk_in',
            'payment_status' => 'unpaid', 'total_guests' => 1, 'check_in' => $this->day(0), 'check_out' => $this->day(2),
        ]);
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$id/post-room-charge");
        $this->assertResponseOk((string)$this->_response->getBody());
        $invoiceId = (int)$this->getTableLocator()->get('InvoiceLines')->find()
            ->where(['source_type' => 'reservation', 'source_id' => $id])->firstOrFail()->invoice_id;
        $this->callAs($this->deskToken, 'POST', "/api/invoices/$invoiceId/settle");
        $this->assertResponseOk();

        return $invoiceId;
    }

    /** A paid sale (₱300), served unless told otherwise; returns its id. */
    private function paidSale(string $status = 'served', string $payment = 'paid'): int
    {
        return $this->insertRow('FoodOrders', [
            'property_id' => $this->propertyId, 'receptionist_id' => $this->userIdFor($this->deskToken),
            'status' => $status, 'payment_status' => $payment, 'payment_method' => $payment === 'paid' ? 'cash' : null,
            'total' => '300.00', 'total_diners' => 1,
        ]);
    }

    /**
     * The settled invoice as stored, with its lines: what a refund must never change.
     *
     * @return array<string, mixed>
     */
    private function invoiceRecord(int $invoiceId): array
    {
        $invoice = $this->getTableLocator()->get('Invoices')->get($invoiceId)->toArray();
        unset($invoice['modified']);
        $lines = $this->getTableLocator()->get('InvoiceLines')->find()
            ->where(['invoice_id' => $invoiceId])->orderBy(['id' => 'ASC'])->disableHydration()->all()->toList();

        return ['invoice' => $invoice, 'lines' => $lines];
    }

    private function events(string $table, array $where): int
    {
        return $this->getTableLocator()->get($table)->find()->where($where)->count();
    }

    public function testAManagerRefundsASettledInvoiceExactlyOnce(): void
    {
        $invoiceId = $this->settledStay();
        $before = $this->invoiceRecord($invoiceId);
        $url = "/api/invoices/$invoiceId/refund";
        $refunds = ['invoice_id' => $invoiceId, 'event_type' => 'refunded'];

        $this->callAs($this->deskToken, 'POST', $url, ['amount' => 100, 'method' => 'cash', 'reason' => 'Overcharged']);
        $this->assertResponseCode(403, 'Front Desk Staff may not refund');
        foreach (
            [
                [['amount' => 100, 'method' => 'cash'], 'a reason is required'],
                [['amount' => 100, 'method' => 'cheque', 'reason' => 'Overcharged'], 'an unknown method'],
                [['amount' => 0, 'method' => 'cash', 'reason' => 'Overcharged'], 'nothing to refund'],
                [['method' => 'cash', 'reason' => 'Overcharged'], 'no amount'],
                [['amount' => 2000.01, 'method' => 'cash', 'reason' => 'Overcharged'], 'more than was collected'],
            ] as [$body, $why]
        ) {
            $this->callAs($this->adminToken, 'POST', $url, $body);
            $this->assertResponseCode(400, $why);
        }
        $this->assertStringContainsString('2,000.00', (string)$this->responseJson()['message']);
        $this->assertSame(0, $this->events('InvoiceEvents', $refunds), 'nothing refused was recorded');

        $body = ['amount' => 500, 'method' => 'gcash', 'reason' => 'Minibar charged twice', 'refund_key' => 'key-1'];
        $this->callAs($this->adminToken, 'POST', $url, $body);
        $this->assertResponseOk((string)$this->_response->getBody());
        $requestId = $this->_response->getHeaderLine('X-Request-Id');
        $invoice = $this->responseJson()['invoice'];
        $this->assertEquals(1500, $invoice['refundable']);
        $this->assertCount(1, $invoice['refunds']);
        $this->assertSame('gcash', $invoice['refunds'][0]['method']);

        $event = $this->getTableLocator()->get('InvoiceEvents')->find()->where($refunds)->firstOrFail();
        $this->assertSame(-500.0, (float)$event->amount);
        $this->assertSame('gcash', $event->method);
        $this->assertSame($this->userIdFor($this->adminToken), (int)$event->actor_id);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('Minibar charged twice', $event->reason);
        $this->assertSame($requestId, $event->correlation_id);
        $this->assertNotNull($event->occurred_at);
        $this->assertSame('web', $event->source);

        // The same request again (a double click, a retry) is refused and records nothing.
        $this->callAs($this->adminToken, 'POST', $url, $body);
        $this->assertResponseCode(409);
        $this->assertSame(1, $this->events('InvoiceEvents', $refunds));

        // A second, different refund may take what's left, and no more.
        $this->callAs($this->adminToken, 'POST', $url, ['amount' => 1500, 'method' => 'cash', 'reason' => 'Stay cut short', 'refund_key' => 'key-2']);
        $this->assertResponseOk();
        $this->assertEquals(0, $this->responseJson()['invoice']['refundable']);
        $this->callAs($this->adminToken, 'POST', $url, ['amount' => 0.01, 'method' => 'cash', 'reason' => 'One more', 'refund_key' => 'key-3']);
        $this->assertResponseCode(400);
        $this->assertSame(2, $this->events('InvoiceEvents', $refunds));

        // The settled invoice and its lines are exactly as they were.
        $this->assertEquals($before, $this->invoiceRecord($invoiceId));

        // Cash in today ₱2,000, cash out ₱2,000, net nil; all time, nothing invented or lost.
        $money = new Collections($this->propertyId);
        $today = $money->figuresOn($this->day(0));
        $this->assertSame([2000.0, 2000.0, 0.0], [$today['collected']['total'], $today['refunded']['total'], $today['net']]);
        $this->assertSame($money->historical(null, null)['net'], $money->cashMovement(null, null)['net']);
    }

    public function testAnOpenInvoiceCannotBeRefunded(): void
    {
        $id = $this->insertRow('Reservations', [
            'property_id' => $this->propertyId, 'room_id' => $this->roomId, 'guest_id' => $this->guestId,
            'receptionist_id' => $this->userIdFor($this->deskToken), 'status' => 'checked_in', 'source' => 'walk_in',
            'payment_status' => 'unpaid', 'total_guests' => 1, 'check_in' => $this->day(0), 'check_out' => $this->day(1),
        ]);
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$id/post-room-charge");
        $invoiceId = (int)$this->getTableLocator()->get('InvoiceLines')->find()
            ->where(['source_type' => 'reservation', 'source_id' => $id])->firstOrFail()->invoice_id;

        $this->callAs($this->adminToken, 'POST', "/api/invoices/$invoiceId/refund", ['amount' => 100, 'method' => 'cash', 'reason' => 'Too early']);
        $this->assertResponseCode(400);
        $this->assertStringContainsString('open', (string)$this->responseJson()['message']);
        $this->assertSame(0, $this->events('InvoiceEvents', ['invoice_id' => $invoiceId, 'event_type IN' => ['refunded', 'refunded_on_cancel']]));
    }

    public function testCancellingAPaidSaleAsksWhetherMoneyWentBack(): void
    {
        $refunded = $this->paidSale();
        $kept = $this->paidSale();
        $url = fn(int $id) => "/api/food-orders/$id/cancel";
        $refundsOf = fn(int $id) => $this->events('FoodOrderEvents', ['food_order_id' => $id, 'event_type' => 'refunded']);

        // Money returned needs its method; refused, nothing changes.
        $this->callAs($this->adminToken, 'POST', $url($refunded), ['reason' => 'Wrong dish', 'refund' => ['returned' => true]]);
        $this->assertResponseCode(400);
        $this->assertSame('served', $this->getTableLocator()->get('FoodOrders')->get($refunded)->status);

        $this->callAs($this->adminToken, 'POST', $url($refunded), [
            'reason' => 'Wrong dish', 'refund' => ['returned' => true, 'method' => 'maya'], 'refund_key' => 'sale-key-1',
        ]);
        $this->assertResponseOk((string)$this->_response->getBody());
        $event = $this->getTableLocator()->get('FoodOrderEvents')->find()
            ->where(['food_order_id' => $refunded, 'event_type' => 'refunded'])->firstOrFail();
        $this->assertSame([-300.0, 'maya', 'Wrong dish', 'admin'], [(float)$event->amount, $event->method, $event->reason, $event->actor_role]);
        $this->assertSame($this->_response->getHeaderLine('X-Request-Id'), $event->correlation_id);
        $this->assertSame(1, $this->events('FoodOrderEvents', ['food_order_id' => $refunded, 'event_type' => 'cancelled_after_payment']));

        // Cancelled once (a repeat is refused as already cancelled), refunded once.
        $this->callAs($this->adminToken, 'POST', $url($refunded), [
            'reason' => 'Again', 'refund' => ['returned' => true, 'method' => 'maya'], 'refund_key' => 'sale-key-1',
        ]);
        $this->assertResponseCode(400);
        $this->callAs($this->adminToken, 'POST', "/api/food-orders/$refunded/refund", ['method' => 'cash', 'reason' => 'Again']);
        $this->assertResponseCode(409);
        $this->assertSame(1, $refundsOf($refunded));

        // Cancelled without money going back: the sale stays collected.
        $this->callAs($this->adminToken, 'POST', $url($kept), ['reason' => 'Guest left', 'refund' => ['returned' => false]]);
        $this->assertResponseOk();
        $this->assertSame(0, $refundsOf($kept));

        // A refund confirmed later is recorded today, by a Manager with a reason, once.
        $later = "/api/food-orders/$kept/refund";
        $this->callAs($this->deskToken, 'POST', $later, ['method' => 'cash', 'reason' => 'Paid back after all']);
        $this->assertResponseCode(403);
        $this->callAs($this->adminToken, 'POST', $later, ['method' => 'cash']);
        $this->assertResponseCode(400, 'a reason is required');
        $this->callAs($this->adminToken, 'POST', $later, ['method' => 'cash', 'reason' => 'Paid back after all', 'refund_key' => 'sale-key-2']);
        $this->assertResponseOk();
        $this->callAs($this->adminToken, 'POST', $later, ['method' => 'cash', 'reason' => 'Paid back after all', 'refund_key' => 'sale-key-2']);
        $this->assertResponseCode(409);
        $this->assertSame(1, $refundsOf($kept));

        // Nothing was paid for an unpaid sale, so nothing can go back.
        $unpaid = $this->paidSale('open', 'unpaid');
        $this->callAs($this->deskToken, 'POST', $url($unpaid), ['reason' => 'Mistake', 'refund' => ['returned' => true, 'method' => 'cash']]);
        $this->assertResponseCode(400);
        $this->assertSame('open', $this->getTableLocator()->get('FoodOrders')->get($unpaid)->status);

        // A served sale can't be "refunded later" before it's cancelled.
        $served = $this->paidSale();
        $this->callAs($this->adminToken, 'POST', "/api/food-orders/$served/refund", ['method' => 'cash', 'reason' => 'Not yet']);
        $this->assertResponseCode(400);

        // Today: ₱900 in from three paid sales, ₱600 back; all time equal to the old model.
        $money = new Collections($this->propertyId);
        $today = $money->figuresOn($this->day(0));
        $this->assertSame([900.0, 600.0, 300.0], [$today['collected']['pos']['total'], $today['refunded']['pos']['total'], $today['net']]);
        $this->assertSame($money->historical(null, null)['net'], $money->cashMovement(null, null)['net']);
    }

    public function testCancellingAnAdvanceBookingRefundsItsDownpaymentOnce(): void
    {
        $this->insertRow('BookingSources', ['property_id' => $this->propertyId, 'name' => 'Agoda', 'code' => 'agoda']);
        $this->callAs($this->deskToken, 'POST', '/api/reservations', [
            'room_id' => $this->roomId, 'guest_id' => $this->guestId, 'source' => 'agoda', 'booking_reference' => 'AG-7',
            'check_in' => $this->day(5), 'check_out' => $this->day(7), 'total_guests' => 1,
        ]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $id = (int)$this->responseJson()['reservation']['id'];
        $invoice = $this->getTableLocator()->get('Invoices')->invoiceForLine('downpayment', $id);
        $before = $this->invoiceRecord((int)$invoice->id);
        $downpayment = (float)$invoice->total;

        $this->callAs($this->deskToken, 'POST', "/api/reservations/$id/cancel", ['refund_method' => 'bank']);
        $this->assertResponseCode(400, 'an unknown method');
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$id/cancel", ['refund_method' => 'gotyme', 'reason' => 'Plans changed']);
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$id/cancel", ['refund_method' => 'gotyme', 'reason' => 'Plans changed']);
        $this->assertResponseCode(400, 'cancelled once');

        $events = $this->getTableLocator()->get('InvoiceEvents')->find()
            ->where(['invoice_id' => $invoice->id, 'event_type' => 'refunded_on_cancel'])->all()->toList();
        $this->assertCount(1, $events);
        $this->assertEqualsWithDelta(-$downpayment * 0.9, (float)$events[0]->amount, 0.001);
        $this->assertSame('gotyme', $events[0]->method);
        $this->assertSame('receptionist', $events[0]->actor_role);
        $this->assertEquals($before, $this->invoiceRecord((int)$invoice->id), 'the settled downpayment invoice never changes');

        // Collected today: the full downpayment in, 90% out, 10% retained.
        $today = (new Collections($this->propertyId))->figuresOn($this->day(0));
        $this->assertEqualsWithDelta(
            [$downpayment, $downpayment * 0.9, $downpayment * 0.1],
            [$today['collected']['total'], $today['refunded']['total'], $today['net']],
            0.001,
        );
    }
}
