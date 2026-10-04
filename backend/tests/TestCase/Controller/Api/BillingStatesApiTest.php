<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Model\BusinessTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Reservation billing (build step 3, business-logic changes BL1–BL4).
 *
 * A stay's billing state comes from the invoice, not from payment_status:
 * Not billed (no room charge posted) → Billed (charge on an open invoice) →
 * Settled (that invoice settled). "Post room charge" replaces "Mark paid" and
 * refuses when nothing could be posted; "Mark unpaid" is gone. "Not billed"
 * means a started stay (checked in or out) with no charge, so a future
 * booking isn't a receivable.
 */
class BillingStatesApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private int $pricedRoom;
    private int $unpricedRoom;
    private int $guestId;
    private string $adminToken;
    private string $receptionistToken;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Billing States Resort');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "bill-admin-$tag@example.test");
        $this->receptionistToken = $this->makeUser($this->propertyId, 'receptionist', "bill-desk-$tag@example.test");

        $p = $this->propertyId;
        $this->pricedRoom = $this->insertRow('Rooms', [
            'property_id' => $p, 'room_number' => 'B-1', 'room_type' => 'Deluxe', 'status' => 'available',
        ]);
        $this->unpricedRoom = $this->insertRow('Rooms', [
            'property_id' => $p, 'room_number' => 'B-2', 'room_type' => 'Deluxe', 'status' => 'available',
        ]);
        // Room-specific rate on B-1 only, so B-2 resolves no rate at all.
        $this->insertRow('RoomRates', ['property_id' => $p, 'room_id' => $this->pricedRoom, 'base_rate' => 1000]);
        $this->guestId = $this->insertRow('Guests', [
            'property_id' => $p, 'full_name' => 'Billing Guest', 'guest_type' => 'local',
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
     * Insert a reservation directly (the scenario, not the booking flow).
     *
     * @param array<string, mixed> $data
     */
    private function stay(array $data = []): int
    {
        return $this->insertRow('Reservations', $data + [
            'property_id' => $this->propertyId, 'room_id' => $this->pricedRoom, 'guest_id' => $this->guestId,
            'receptionist_id' => $this->userIdFor($this->receptionistToken), 'status' => 'checked_in',
            'source' => 'walk_in', 'payment_status' => 'unpaid', 'total_guests' => 1,
            'check_in' => $this->day(0), 'check_out' => $this->day(2),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function listed(int $id, string $query = ''): ?array
    {
        $this->callAs($this->receptionistToken, 'GET', '/api/reservations' . $query);
        $this->assertResponseOk();
        foreach ($this->responseJson()['reservations'] as $row) {
            if ((int)$row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(): array
    {
        $this->callAs($this->receptionistToken, 'GET', '/api/reservations/stats');
        $this->assertResponseOk();

        return $this->responseJson();
    }

    private function chargeLines(int $reservationId): int
    {
        return $this->getTableLocator()->get('InvoiceLines')->find()
            ->where(['source_type' => 'reservation', 'source_id' => $reservationId])->count();
    }

    public function testAStartedStayWithNoChargeIsNotBilled(): void
    {
        $id = $this->stay();

        $this->assertSame('not_billed', $this->listed($id)['billing_state']);
        $this->assertNotNull($this->listed($id, '?billing=not_billed'));
        $this->assertSame(1, $this->stats()['not_billed']);
    }

    public function testAFutureBookingIsNotAReceivable(): void
    {
        $id = $this->stay(['status' => 'booked', 'check_in' => $this->day(5), 'check_out' => $this->day(7)]);

        $this->assertSame('not_billed', $this->listed($id)['billing_state']);
        $this->assertNull($this->listed($id, '?billing=not_billed'), 'a future booking is not a receivable yet');
        $this->assertSame(0, $this->stats()['not_billed']);
    }

    public function testFrontDeskStaffPostTheRoomChargeAndTheStayIsBilled(): void
    {
        $id = $this->stay();

        $this->callAs($this->receptionistToken, 'POST', "/api/reservations/$id/post-room-charge");
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->assertSame('billed', $this->responseJson()['reservation']['billing_state']);

        $this->assertGreaterThan(0, $this->chargeLines($id));
        $this->assertSame('billed', $this->listed($id)['billing_state']);
        $this->assertNull($this->listed($id, '?billing=not_billed'));
        $stats = $this->stats();
        $this->assertSame(0, $stats['not_billed']);
        $this->assertSame(1, $stats['open_invoices']);
    }

    public function testPostingTwicePostsOnce(): void
    {
        $id = $this->stay();
        $this->callAs($this->receptionistToken, 'POST', "/api/reservations/$id/post-room-charge");
        $this->assertResponseOk();
        $lines = $this->chargeLines($id);

        $this->callAs($this->receptionistToken, 'POST', "/api/reservations/$id/post-room-charge");
        $this->assertResponseOk();
        $this->assertSame($lines, $this->chargeLines($id));
    }

    public function testSettlingTheInvoiceMakesTheStaySettled(): void
    {
        $id = $this->stay();
        $this->callAs($this->receptionistToken, 'POST', "/api/reservations/$id/post-room-charge");
        $this->assertResponseOk();
        $invoiceId = (int)$this->getTableLocator()->get('InvoiceLines')->find()
            ->where(['source_type' => 'reservation', 'source_id' => $id])->firstOrFail()->invoice_id;

        $this->callAs($this->receptionistToken, 'POST', "/api/invoices/$invoiceId/settle");
        $this->assertResponseOk((string)$this->_response->getBody());

        $this->assertSame('settled', $this->listed($id)['billing_state']);
    }

    /**
     * Hotfix (2026-10-03): cancelling a checked-in stay whose invoice is
     * settled used to delete its lines from that settled invoice and lower
     * the Collected of the day it was settled.
     */
    public function testAStayOnASettledInvoiceCannotBeCancelled(): void
    {
        $id = $this->stay();
        $this->callAs($this->receptionistToken, 'POST', "/api/reservations/$id/post-room-charge");
        $this->assertResponseOk();
        $invoices = $this->getTableLocator()->get('Invoices');
        $invoiceId = (int)$this->getTableLocator()->get('InvoiceLines')->find()
            ->where(['source_type' => 'reservation', 'source_id' => $id])->firstOrFail()->invoice_id;
        $this->callAs($this->receptionistToken, 'POST', "/api/invoices/$invoiceId/settle");
        $this->assertResponseOk();
        $totalBefore = (float)$invoices->get($invoiceId)->total;
        $linesBefore = $this->chargeLines($id);
        $roomBefore = $this->getTableLocator()->get('Rooms')->get($this->pricedRoom)->status;

        foreach ([$this->receptionistToken, $this->adminToken] as $token) {
            $this->callAs($token, 'POST', "/api/reservations/$id/cancel");
            $this->assertResponseCode(400);
            $this->assertStringContainsString('Its invoice is already settled', (string)$this->responseJson()['message']);
        }

        $this->assertSame($totalBefore, (float)$invoices->get($invoiceId)->total, 'the settled invoice is untouched');
        $this->assertSame($linesBefore, $this->chargeLines($id));
        $this->assertSame('checked_in', $this->getTableLocator()->get('Reservations')->get($id)->status);
        $this->assertSame($roomBefore, $this->getTableLocator()->get('Rooms')->get($this->pricedRoom)->status);
    }

    public function testAStayOnAnOpenInvoiceCanStillBeCancelled(): void
    {
        $id = $this->stay();
        $this->callAs($this->receptionistToken, 'POST', "/api/reservations/$id/post-room-charge");
        $this->assertResponseOk();

        $this->callAs($this->receptionistToken, 'POST', "/api/reservations/$id/cancel", ['reason' => 'Guest left early']);
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->assertSame('cancelled', $this->getTableLocator()->get('Reservations')->get($id)->status);
        $this->assertSame('not_billed', $this->listed($id)['billing_state'], 'its charge is reversed off the open invoice');
    }

    public function testAStayWithoutAGuestCannotBePostedAndSaysWhy(): void
    {
        $id = $this->stay(['guest_id' => null]);

        $this->callAs($this->receptionistToken, 'POST', "/api/reservations/$id/post-room-charge");
        $this->assertResponseCode(400);
        $this->assertSame('Add a guest first to post the room charge.', $this->responseJson()['message']);
        // The old "Mark paid" marked it paid anyway; now nothing changes.
        $this->assertSame('unpaid', $this->getTableLocator()->get('Reservations')->get($id)->payment_status);
        $this->assertSame('not_billed', $this->listed($id)['billing_state']);
    }

    public function testAStayWithNoRateCannotBePostedAndLeavesNoEmptyInvoice(): void
    {
        $id = $this->stay(['room_id' => $this->unpricedRoom]);

        $this->callAs($this->receptionistToken, 'POST', "/api/reservations/$id/post-room-charge");
        $this->assertResponseCode(400);
        $this->assertStringContainsString('no room rate', $this->responseJson()['message']);
        $this->assertSame(0, $this->getTableLocator()->get('Invoices')->find()
            ->where(['property_id' => $this->propertyId])->count(), 'the failed post must roll back its invoice');
        $this->assertSame('unpaid', $this->getTableLocator()->get('Reservations')->get($id)->payment_status);
    }

    public function testACancelledReservationCannotBeBilled(): void
    {
        $id = $this->stay(['status' => 'cancelled']);

        $this->callAs($this->adminToken, 'POST', "/api/reservations/$id/post-room-charge");
        $this->assertResponseCode(400);
        $this->assertSame(0, $this->chargeLines($id));
    }

    public function testTheOldPaymentPathPostsButNoLongerMarksUnpaid(): void
    {
        $id = $this->stay();

        $this->callAs($this->receptionistToken, 'POST', "/api/reservations/$id/payment", ['payment_status' => 'paid']);
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->assertSame('billed', $this->listed($id)['billing_state']);

        foreach ([$this->receptionistToken, $this->adminToken] as $token) {
            $this->callAs($token, 'POST', "/api/reservations/$id/payment", ['payment_status' => 'unpaid']);
            $this->assertResponseCode(400);
        }
        $this->assertSame('paid', $this->getTableLocator()->get('Reservations')->get($id)->payment_status);
        $this->assertGreaterThan(0, $this->chargeLines($id), 'the posted charge stays');
    }

    public function testAnUnknownBillingFilterIsRejected(): void
    {
        $this->callAs($this->receptionistToken, 'GET', '/api/reservations?billing=paid');
        $this->assertResponseCode(400);
    }
}
