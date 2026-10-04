<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Model\BusinessTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Invoice accountability (build step 6): every change to an invoice is
 * recorded in invoice_events with who, when, why and which request; lines
 * are never deleted (a reversal answers them); settled invoices never change,
 * except the documented downpayment refund.
 */
class InvoiceLedgerApiTest extends TestCase
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
        $this->propertyId = $this->createProperty('Invoice Ledger Resort');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "ledger-admin-$tag@example.test");
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', "ledger-desk-$tag@example.test");
        $this->roomId = $this->insertRow('Rooms', [
            'property_id' => $this->propertyId, 'room_number' => 'L-1', 'room_type' => 'Deluxe', 'status' => 'available',
        ]);
        $this->insertRow('RoomRates', ['property_id' => $this->propertyId, 'room_id' => $this->roomId, 'base_rate' => 1000]);
        $this->guestId = $this->insertRow('Guests', [
            'property_id' => $this->propertyId, 'full_name' => 'Gia Ramos', 'guest_type' => 'local',
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

    /**
     * A checked-in stay with its room charge posted; returns [reservation id, invoice id, request id].
     *
     * @return array{0: int, 1: int, 2: string}
     */
    private function billedStay(): array
    {
        $id = $this->insertRow('Reservations', [
            'property_id' => $this->propertyId, 'room_id' => $this->roomId, 'guest_id' => $this->guestId,
            'receptionist_id' => $this->userIdFor($this->deskToken), 'status' => 'checked_in', 'source' => 'walk_in',
            'payment_status' => 'unpaid', 'total_guests' => 1, 'check_in' => $this->day(0), 'check_out' => $this->day(2),
        ]);
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$id/post-room-charge");
        $this->assertResponseOk((string)$this->_response->getBody());
        $requestId = $this->_response->getHeaderLine('X-Request-Id');
        $invoiceId = (int)$this->getTableLocator()->get('InvoiceLines')->find()
            ->where(['source_type' => 'reservation', 'source_id' => $id])->firstOrFail()->invoice_id;

        return [$id, $invoiceId, $requestId];
    }

    /**
     * @return list<string>
     */
    private function types(int $invoiceId): array
    {
        return $this->getTableLocator()->get('InvoiceEvents')->find()
            ->where(['invoice_id' => $invoiceId])->orderBy(['id' => 'ASC'])->all()->extract('event_type')->toList();
    }

    private function total(int $invoiceId): float
    {
        return (float)$this->getTableLocator()->get('Invoices')->get($invoiceId)->total;
    }

    public function testPostingAChargeOpensTheInvoiceAndRecordsTheLine(): void
    {
        [, $invoiceId, $requestId] = $this->billedStay();

        $this->assertSame(['opened', 'line_added'], $this->types($invoiceId));
        $added = $this->getTableLocator()->get('InvoiceEvents')->find()
            ->where(['invoice_id' => $invoiceId, 'event_type' => 'line_added'])->firstOrFail();
        $this->assertSame($this->userIdFor($this->deskToken), (int)$added->actor_id);
        $this->assertSame('receptionist', $added->actor_role);
        $this->assertSame($requestId, $added->correlation_id, 'the request that posted it');
        $this->assertSame(2000.0, (float)$added->amount);
        $this->assertSame(2000.0, (float)$added->total_after);
        $this->assertNotNull($added->invoice_line_id);
        $this->assertSame('Gia Ramos', $added->snapshot['guest_name']);
    }

    public function testCancellingReversesLinesInsteadOfDeletingThem(): void
    {
        [$id, $invoiceId] = $this->billedStay();
        $lines = $this->getTableLocator()->get('InvoiceLines')->find()->where(['invoice_id' => $invoiceId])->count();

        $this->callAs($this->deskToken, 'POST', "/api/reservations/$id/cancel", ['reason' => 'Guest left early']);
        $this->assertResponseOk((string)$this->_response->getBody());

        $this->assertSame($lines * 2, $this->getTableLocator()->get('InvoiceLines')->find()
            ->where(['invoice_id' => $invoiceId])->count(), 'every line stays, each answered by a reversal');
        $this->assertSame(0.0, $this->total($invoiceId));
        $this->assertSame(['opened', 'line_added', 'line_reversed_on_cancel'], $this->types($invoiceId));
        $this->assertNull(
            $this->getTableLocator()->get('Invoices')->invoiceForLine('reservation', $id),
            'a reversed charge no longer counts as posted',
        );
    }

    public function testAManagerReversesALineWithAReason(): void
    {
        [, $invoiceId] = $this->billedStay();
        $line = $this->getTableLocator()->get('InvoiceLines')->find()->where(['invoice_id' => $invoiceId])->firstOrFail();
        $url = "/api/invoices/$invoiceId/lines/{$line->id}/reverse";

        $this->callAs($this->deskToken, 'POST', $url, ['reason' => 'Wrong room']);
        $this->assertResponseCode(403, 'Front Desk Staff may not reverse');
        $this->callAs($this->adminToken, 'POST', $url);
        $this->assertResponseCode(400, 'a reason is required');
        $this->assertSame(['opened', 'line_added'], $this->types($invoiceId), 'nothing recorded by the refusals');

        $this->callAs($this->adminToken, 'POST', $url, ['reason' => 'Charged the wrong room']);
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->assertSame(0.0, $this->total($invoiceId));
        $reversal = $this->getTableLocator()->get('InvoiceEvents')->find()
            ->where(['invoice_id' => $invoiceId, 'event_type' => 'line_reversed'])->firstOrFail();
        $this->assertSame('Charged the wrong room', $reversal->reason);
        $this->assertSame('admin', $reversal->actor_role);
        $this->assertSame(-2000.0, (float)$reversal->amount);
        $this->assertSame((int)$line->id, (int)$reversal->changes['reverses']['line_id']);

        $this->callAs($this->adminToken, 'POST', $url, ['reason' => 'Again']);
        $this->assertResponseCode(400, 'a line is reversed once');
        $reversalLine = (int)$reversal->invoice_line_id;
        $this->callAs($this->adminToken, 'POST', "/api/invoices/$invoiceId/lines/$reversalLine/reverse", ['reason' => 'x']);
        $this->assertResponseCode(400, 'a reversal is not reversed');
    }

    public function testASettledInvoiceNeverChanges(): void
    {
        [, $invoiceId] = $this->billedStay();
        $line = $this->getTableLocator()->get('InvoiceLines')->find()->where(['invoice_id' => $invoiceId])->firstOrFail();
        $this->callAs($this->deskToken, 'POST', "/api/invoices/$invoiceId/settle");
        $this->assertResponseOk();

        $this->callAs($this->adminToken, 'POST', "/api/invoices/$invoiceId/lines/{$line->id}/reverse", ['reason' => 'Late']);
        $this->assertResponseCode(400);
        $this->assertStringContainsString('settled', (string)$this->responseJson()['message']);
        $this->assertSame(2000.0, $this->total($invoiceId));
        $this->assertSame(['opened', 'line_added', 'settled'], $this->types($invoiceId));
    }

    public function testADownpaymentIsSettledOnCreationAndItsRefundIsCashOut(): void
    {
        // An advance (online) booking for a guest on file collects a 50% downpayment.
        $this->insertRow('BookingSources', ['property_id' => $this->propertyId, 'name' => 'Agoda', 'code' => 'agoda']);
        $this->callAs($this->deskToken, 'POST', '/api/reservations', [
            'room_id' => $this->roomId, 'guest_id' => $this->guestId, 'source' => 'agoda', 'booking_reference' => 'AG-1',
            'check_in' => $this->day(5), 'check_out' => $this->day(7), 'total_guests' => 1,
        ]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $bookId = $this->_response->getHeaderLine('X-Request-Id');
        $reservation = $this->responseJson()['reservation'];
        $this->assertSame('booked', $reservation['status']);
        $downpaymentInvoice = $this->getTableLocator()->get('Invoices')->invoiceForLine('downpayment', (int)$reservation['id']);
        $this->assertNotNull($downpaymentInvoice, 'an advance booking collects a downpayment');
        $invoiceId = (int)$downpaymentInvoice->id;
        $this->assertSame(['opened', 'line_added', 'settled_on_creation'], $this->types($invoiceId));
        $this->assertSame(
            [$bookId],
            array_values(array_unique($this->getTableLocator()->get('InvoiceEvents')->find()
                ->where(['invoice_id' => $invoiceId])->all()->extract('correlation_id')->toList())),
            'the booking and its downpayment are one action',
        );
        $before = $this->total($invoiceId);

        // Since step 7c the refund is cash out with its method, asked for first.
        $this->callAs($this->adminToken, 'POST', "/api/reservations/{$reservation['id']}/cancel");
        $this->assertResponseCode(400, 'how the refund was paid is required');
        $this->assertSame('booked', $this->getTableLocator()->get('Reservations')->get($reservation['id'])->status);
        $this->callAs($this->adminToken, 'POST', "/api/reservations/{$reservation['id']}/cancel", [
            'refund_method' => 'cash', 'reason' => 'Guest changed plans',
        ]);
        $this->assertResponseOk((string)$this->_response->getBody());

        $refund = $this->getTableLocator()->get('InvoiceEvents')->find()
            ->where(['invoice_id' => $invoiceId, 'event_type' => 'refunded_on_cancel'])->firstOrFail();
        $this->assertSame('admin', $refund->actor_role);
        $this->assertSame($this->_response->getHeaderLine('X-Request-Id'), $refund->correlation_id);
        $this->assertEqualsWithDelta(-$before * 0.9, (float)$refund->amount, 0.01, '90% refunded');
        $this->assertSame('cash', $refund->method);
        $this->assertSame('advance_booking_cancellation', $refund->changes['policy']['rule']);
        // The settled downpayment invoice never changes: no line, same total.
        $invoice = $this->getTableLocator()->get('Invoices')->get($invoiceId);
        $this->assertSame('settled', $invoice->status);
        $this->assertSame($before, (float)$invoice->total);
        $this->assertFalse($this->getTableLocator()->get('InvoiceLines')->exists([
            'invoice_id' => $invoiceId, 'source_type' => 'downpayment_refund',
        ]));
        $this->assertSame(['opened', 'line_added', 'settled_on_creation', 'refunded_on_cancel'], $this->types($invoiceId));
    }

    public function testAManagerReversesARoomChargeFromFrontDesk(): void
    {
        [$id, $invoiceId] = $this->billedStay();
        $url = "/api/reservations/$id/reverse-room-charge";

        $this->callAs($this->deskToken, 'POST', $url, ['reason' => 'x']);
        $this->assertResponseCode(403, 'Front Desk Staff may not reverse');
        $this->callAs($this->adminToken, 'POST', $url);
        $this->assertResponseCode(400, 'a reason is required');

        $this->callAs($this->adminToken, 'POST', $url, ['reason' => 'Booked the wrong room type']);
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->assertSame('not_billed', $this->responseJson()['reservation']['billing_state']);
        $this->assertSame(0.0, $this->total($invoiceId));
        $reversal = $this->getTableLocator()->get('InvoiceEvents')->find()
            ->where(['invoice_id' => $invoiceId, 'event_type' => 'line_reversed'])->firstOrFail();
        $this->assertSame('Booked the wrong room type', $reversal->reason);
        $this->assertSame('admin', $reversal->actor_role);

        $this->callAs($this->adminToken, 'POST', $url, ['reason' => 'Again']);
        $this->assertResponseCode(400, 'nothing left to reverse');
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$id/post-room-charge");
        $this->assertResponseOk('the stay can be billed afresh');
        $this->assertSame(2000.0, $this->total($invoiceId));

        $this->callAs($this->deskToken, 'POST', "/api/invoices/$invoiceId/settle");
        $this->assertResponseOk();
        $this->callAs($this->adminToken, 'POST', $url, ['reason' => 'Too late']);
        $this->assertResponseCode(400, 'never once settled');
        $this->assertStringContainsString('settled', (string)$this->responseJson()['message']);
    }

    public function testTheFolioCarriesItsHistoryAndWhoSettled(): void
    {
        [, $invoiceId] = $this->billedStay();
        $this->callAs($this->deskToken, 'POST', "/api/invoices/$invoiceId/settle");
        $this->assertResponseOk();

        $this->callAs($this->adminToken, 'GET', "/api/invoices/$invoiceId");
        $this->assertResponseOk();
        $invoice = $this->responseJson()['invoice'];
        $this->assertSame(['opened', 'line_added', 'settled'], array_column($invoice['history'], 'event'));
        foreach ($invoice['history'] as $event) {
            $this->assertTrue($event['recorded']);
            $this->assertStringContainsString('ledger-desk', (string)$event['actor']);
        }
        $this->assertSame((int)$invoice['invoice_lines'][0]['id'], $invoice['history'][1]['line_id']);
        $this->assertStringContainsString('ledger-desk', (string)$invoice['settled_by']['name']);

        $this->callAs($this->adminToken, 'GET', '/api/invoices?date=all');
        $this->assertResponseOk();
        $listed = array_values(array_filter($this->responseJson()['invoices'], fn($i) => (int)$i['id'] === $invoiceId))[0];
        $this->assertStringContainsString('ledger-desk', (string)$listed['settled_by']['name']);
        $this->assertTrue($listed['settled_by']['recorded']);
    }
}
