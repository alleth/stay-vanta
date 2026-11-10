<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Model\BusinessTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Reservation Types phase 1 (approved 2026-11-10): an online booking may be
 * saved without the channel's Booking ID, a placeholder such as "-" is
 * refused, and the ID can be added later through
 * `POST /reservations/{id}/booking-reference` — only while the reservation is
 * still editable, under the same rule as an edit, and never after settlement.
 * Each addition records one `booking_reference_added` event.
 */
class BookingReferenceApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private string $adminToken;
    private string $deskToken;
    private int $roomId;
    private int $otherRoomId;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Booking ID Inn');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "bref-admin-$tag@example.test");
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', "bref-desk-$tag@example.test");
        $this->roomId = $this->insertRow('Rooms', [
            'property_id' => $this->propertyId, 'room_number' => 'B-1', 'room_type' => 'Deluxe', 'status' => 'available',
        ]);
        $this->otherRoomId = $this->insertRow('Rooms', [
            'property_id' => $this->propertyId, 'room_number' => 'B-2', 'room_type' => 'Deluxe', 'status' => 'available',
        ]);
        $this->insertRow('RoomRates', ['property_id' => $this->propertyId, 'room_id' => null, 'base_rate' => 1000]);
        $this->insertRow('BookingSources', ['property_id' => $this->propertyId, 'name' => 'Agoda', 'code' => 'agoda']);
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
     * An online booking for today and tomorrow, with no guest (so no downpayment).
     *
     * @param array<string, mixed> $extra Fields to add or override.
     */
    private function book(array $extra = [], string $token = ''): void
    {
        $this->callAs($token ?: $this->deskToken, 'POST', '/api/reservations', $extra + [
            'room_id' => $this->roomId, 'source' => 'agoda',
            'check_in' => $this->day(0), 'check_out' => $this->day(2), 'total_guests' => 1,
        ]);
    }

    /** An online booking with no Booking ID; returns its id. */
    private function bookingWithoutId(): int
    {
        $this->book();
        $this->assertResponseCode(201, (string)$this->_response->getBody());

        return (int)$this->responseJson()['reservation']['id'];
    }

    private function addId(int $id, string $value, string $token = ''): void
    {
        $this->callAs($token ?: $this->deskToken, 'POST', "/api/reservations/{$id}/booking-reference", [
            'booking_reference' => $value,
        ]);
    }

    private function storedReference(int $id): ?string
    {
        return $this->getTableLocator()->get('Reservations')->get($id)->booking_reference;
    }

    /**
     * @return list<string>
     */
    private function types(int $id): array
    {
        return $this->getTableLocator()->get('ReservationEvents')->find()
            ->where(['reservation_id' => $id])->orderBy(['id' => 'ASC'])
            ->all()->extract('event_type')->toList();
    }

    private function giveGuest(int $id): void
    {
        $guestId = $this->insertRow('Guests', [
            'property_id' => $this->propertyId, 'full_name' => 'Booking Guest', 'guest_type' => 'local',
        ]);
        $reservations = $this->getTableLocator()->get('Reservations');
        $reservations->saveOrFail($reservations->get($id)->set('guest_id', $guestId));
    }

    // ---- Saving without an ID ----------------------------------------------

    public function testAnOnlineBookingCanBeSavedWithoutABookingId(): void
    {
        $id = $this->bookingWithoutId();

        $this->assertNull($this->storedReference($id));
        $this->assertSame(['booked'], $this->types($id));
    }

    public function testAPlaceholderIsRefusedOnANewBooking(): void
    {
        foreach (['-', '--', ' - ', '/', '.', '#'] as $placeholder) {
            $this->book(['booking_reference' => $placeholder]);
            $this->assertResponseCode(422, "'$placeholder' must be refused");
            $this->assertStringContainsString('booking_reference', (string)$this->_response->getBody());
        }
        $this->assertSame(0, $this->getTableLocator()->get('Reservations')->find()
            ->where(['property_id' => $this->propertyId])->count());
    }

    public function testARealIdStillSaves(): void
    {
        $this->book(['booking_reference' => 'AG-12345']);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $this->assertSame('AG-12345', $this->responseJson()['reservation']['booking_reference']);
    }

    public function testAnEditCannotSetAPlaceholder(): void
    {
        $this->book(['booking_reference' => 'AG-1']);
        $id = (int)$this->responseJson()['reservation']['id'];

        $this->callAs($this->deskToken, 'PATCH', "/api/reservations/{$id}", ['booking_reference' => '-']);
        $this->assertResponseCode(422);
        $this->assertSame('AG-1', $this->storedReference($id));
    }

    public function testAnExistingPlaceholderDoesNotBlockOtherEdits(): void
    {
        // Rows saved with "-" before this change stay as they are, and can
        // still be edited for anything else.
        $id = $this->bookingWithoutId();
        $this->getTableLocator()->get('Reservations')->updateAll(['booking_reference' => '-'], ['id' => $id]);

        $this->callAs($this->deskToken, 'PATCH', "/api/reservations/{$id}", ['total_guests' => 2]);
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->assertSame('-', $this->storedReference($id));
    }

    // ---- Finding missing IDs -----------------------------------------------

    public function testTheMissingFilterListsOnlineBookingsWithoutARealId(): void
    {
        $blank = $this->bookingWithoutId();

        $this->book(['room_id' => $this->otherRoomId]);
        $dash = (int)$this->responseJson()['reservation']['id'];
        $this->getTableLocator()->get('Reservations')->updateAll(['booking_reference' => '-'], ['id' => $dash]);

        $this->book(['booking_reference' => 'AG-9', 'check_in' => $this->day(3), 'check_out' => $this->day(4)]);
        $withId = (int)$this->responseJson()['reservation']['id'];

        $this->callAs($this->deskToken, 'POST', '/api/reservations', [
            'room_id' => $this->roomId, 'source' => 'walk_in', 'guest_name' => 'Walk In',
            'check_in' => $this->day(5), 'check_out' => $this->day(6),
        ]);
        $walkIn = (int)$this->responseJson()['reservation']['id'];

        $this->callAs($this->deskToken, 'GET', '/api/reservations?booking_reference=missing&limit=25');
        $this->assertResponseOk();
        $ids = array_map(fn($r) => (int)$r['id'], $this->responseJson()['reservations']);
        sort($ids);
        $expected = [$blank, $dash];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertNotContains($withId, $ids);
        $this->assertNotContains($walkIn, $ids);
    }

    public function testAnUnknownBookingReferenceFilterIsRefused(): void
    {
        $this->callAs($this->deskToken, 'GET', '/api/reservations?booking_reference=present');
        $this->assertResponseCode(400);
    }

    // ---- Adding the ID later -----------------------------------------------

    public function testFrontDeskAddsAMissingIdToABookingAndItIsRecorded(): void
    {
        $id = $this->bookingWithoutId();

        $this->addId($id, ' AG-777 ');
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->assertSame('AG-777', $this->responseJson()['reservation']['booking_reference']);
        $this->assertSame('AG-777', $this->storedReference($id));
        $this->assertSame(['booked', 'booking_reference_added'], $this->types($id));

        $event = $this->getTableLocator()->get('ReservationEvents')->find()
            ->where(['reservation_id' => $id, 'event_type' => 'booking_reference_added'])->firstOrFail();
        $this->assertNotNull($event->actor_id);
        $changes = is_string($event->changes) ? json_decode($event->changes, true) : $event->changes;
        $this->assertSame(['before' => null, 'after' => 'AG-777'], $changes['booking_reference']);
    }

    public function testAPlaceholderCountsAsMissingAndCanBeReplaced(): void
    {
        $id = $this->bookingWithoutId();
        $this->getTableLocator()->get('Reservations')->updateAll(['booking_reference' => '-'], ['id' => $id]);

        $this->addId($id, 'AG-888');
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->assertSame('AG-888', $this->storedReference($id));
    }

    public function testTheSameRequestTwiceRecordsOnce(): void
    {
        $id = $this->bookingWithoutId();

        $this->addId($id, 'AG-1');
        $this->assertResponseOk();
        $this->addId($id, 'AG-1');
        $this->assertResponseCode(400);

        $this->assertSame(['booked', 'booking_reference_added'], $this->types($id));
    }

    public function testAnExistingIdIsNotChangedThroughThisAction(): void
    {
        $this->book(['booking_reference' => 'AG-1']);
        $id = (int)$this->responseJson()['reservation']['id'];

        $this->addId($id, 'AG-2');
        $this->assertResponseCode(400);
        $this->assertSame('AG-1', $this->storedReference($id));
        $this->assertSame(['booked'], $this->types($id));
    }

    public function testThePlaceholderOrNothingIsNotAnId(): void
    {
        $id = $this->bookingWithoutId();

        foreach (['-', '', '   '] as $value) {
            $this->addId($id, $value);
            $this->assertContains($this->_response->getStatusCode(), [400, 422], "'$value' must be refused");
        }
        $this->assertNull($this->storedReference($id));
        $this->assertSame(['booked'], $this->types($id));
    }

    public function testAWalkInHasNoBookingId(): void
    {
        $this->callAs($this->deskToken, 'POST', '/api/reservations', [
            'room_id' => $this->roomId, 'source' => 'walk_in', 'guest_name' => 'Walk In',
            'check_in' => $this->day(0), 'check_out' => $this->day(1),
        ]);
        $id = (int)$this->responseJson()['reservation']['id'];

        $this->addId($id, 'AG-1', $this->adminToken);
        $this->assertResponseCode(400);
        $this->assertNull($this->storedReference($id));
    }

    public function testAfterCheckInOnlySomeoneWhoCanCorrectAStayMayAddIt(): void
    {
        $id = $this->bookingWithoutId();
        $this->callAs($this->deskToken, 'POST', "/api/reservations/{$id}/check-in", []);
        $this->assertResponseOk((string)$this->_response->getBody());

        // Front Desk Staff can't edit a stay, so they can't add the ID either.
        $this->addId($id, 'AG-5');
        $this->assertResponseCode(400);
        $this->assertNull($this->storedReference($id));

        $this->addId($id, 'AG-5', $this->adminToken);
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->assertSame('AG-5', $this->storedReference($id));
    }

    public function testNotOnceTheRoomChargeIsPosted(): void
    {
        $id = $this->bookingWithoutId();
        $this->giveGuest($id);
        $this->callAs($this->deskToken, 'POST', "/api/reservations/{$id}/check-in", []);
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->callAs($this->adminToken, 'POST', "/api/reservations/{$id}/post-room-charge", []);
        $this->assertResponseOk((string)$this->_response->getBody());

        $this->addId($id, 'AG-6', $this->adminToken);
        $this->assertResponseCode(400);
        $this->assertNull($this->storedReference($id));
    }

    public function testNeverAfterSettlement(): void
    {
        // A future booking, billed and settled before arrival: still `booked`,
        // but locked by its settled invoice.
        $this->book(['check_in' => $this->day(2), 'check_out' => $this->day(3)]);
        $id = (int)$this->responseJson()['reservation']['id'];
        $this->giveGuest($id);
        $this->callAs($this->adminToken, 'POST', "/api/reservations/{$id}/post-room-charge", []);
        $this->assertResponseOk((string)$this->_response->getBody());
        $line = $this->getTableLocator()->get('InvoiceLines')->find()
            ->where(['source_type' => 'reservation', 'source_id' => $id])->firstOrFail();
        $this->callAs($this->adminToken, 'POST', "/api/invoices/{$line->invoice_id}/settle", []);
        $this->assertResponseOk((string)$this->_response->getBody());

        $this->addId($id, 'AG-7', $this->adminToken);
        $this->assertResponseCode(400);
        $this->assertNull($this->storedReference($id));
    }

    public function testNotOnABookingThatTookADownpayment(): void
    {
        // Editing is refused once a downpayment was collected, so adding the
        // ID is too (phase 5 revisits editing after a deposit).
        $this->book([
            'guest_name' => 'Advance Guest', 'check_in' => $this->day(3), 'check_out' => $this->day(4),
        ]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $id = (int)$this->responseJson()['reservation']['id'];
        $this->assertGreaterThan(0, (float)$this->getTableLocator()->get('Reservations')->get($id)->downpayment);

        $this->addId($id, 'AG-8', $this->adminToken);
        $this->assertResponseCode(400);
        $this->assertNull($this->storedReference($id));
    }

    public function testNotOnACancelledBooking(): void
    {
        $id = $this->bookingWithoutId();
        $this->callAs($this->deskToken, 'POST', "/api/reservations/{$id}/cancel", []);
        $this->assertResponseOk((string)$this->_response->getBody());

        $this->addId($id, 'AG-9', $this->adminToken);
        $this->assertResponseCode(400);
        $this->assertNull($this->storedReference($id));
    }
}
