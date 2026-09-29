<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Model\BusinessTime;
use App\Model\Table\UsersTable;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Recording a stay that started before today — an admin-only privilege.
 *
 * Covers the gate (a receptionist is refused, except that their walk-in is
 * quietly moved to today), the status a past stay lands in (checked out if
 * it's over, checked in if it's still going), and that a past stay can't be
 * entered over nights the room really was taken.
 *
 * No fixtures, as in ReservationDiscountsApiTest: rows are built in setUp()
 * and removed in tearDown(), scoped to this test's own property.
 */
class BackdatedReservationsApiTest extends TestCase
{
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private int $roomId;
    private string $adminToken;
    private string $receptionistToken;

    protected function setUp(): void
    {
        parent::setUp();

        $properties = $this->getTableLocator()->get('Properties');
        $rooms = $this->getTableLocator()->get('Rooms');
        $rates = $this->getTableLocator()->get('RoomRates');
        $sources = $this->getTableLocator()->get('BookingSources');

        $property = $properties->saveOrFail($properties->newEntity(['name' => 'Backdating API Resort']));
        $this->propertyId = (int)$property->id;

        $room = $rooms->saveOrFail($rooms->newEntity([
            'property_id' => $this->propertyId,
            'room_number' => 'PAST-1',
            'room_type' => 'Deluxe',
            'status' => 'available',
        ]));
        $this->roomId = (int)$room->id;

        $rates->saveOrFail($rates->newEntity([
            'property_id' => $this->propertyId,
            'room_id' => $this->roomId,
            'base_rate' => 1500.0,
        ]));

        $sources->saveOrFail($sources->newEntity([
            'property_id' => $this->propertyId,
            'code' => 'agoda',
            'name' => 'Agoda',
        ]));

        $this->adminToken = $this->makeUser('admin', 'backdating-admin@example.test');
        $this->receptionistToken = $this->makeUser('receptionist', 'backdating-desk@example.test');
    }

    /**
     * Create a staff user on this property and return their plaintext token.
     */
    private function makeUser(string $role, string $email): string
    {
        $users = $this->getTableLocator()->get('Users');
        [$token, $digest] = UsersTable::issueToken();
        $users->saveOrFail($users->newEntity([
            'property_id' => $this->propertyId,
            'name' => ucfirst($role),
            'email' => $email,
            'password' => 'secret123',
            'role' => $role,
            'is_active' => true,
        ]));
        $user = $users->find()->where(['email' => $email])->firstOrFail();
        $user->set('api_token', $digest);
        $user->set('token_expires', new DateTime('+30 days'));
        $users->saveOrFail($user);

        return $token;
    }

    /**
     * Sign the next request. Needed before every request: request config
     * doesn't survive one, so a second call would otherwise come back 401.
     */
    private function authedAs(string $token): void
    {
        $this->configRequest([
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * A date relative to the hotel's today (what "past" is measured against).
     */
    private function day(int $offset): string
    {
        return BusinessTime::today()->addDays($offset)->format('Y-m-d');
    }

    /**
     * The hotel-local date of a serialized timestamp — stored times are UTC,
     * so a local midnight reads as the previous day in UTC.
     */
    private function localDay(string $timestamp): string
    {
        return DateTime::parse($timestamp)->setTimezone(BusinessTime::timezone())->format('Y-m-d');
    }

    /**
     * @return array<string, mixed>
     */
    private function book(string $token, array $data): array
    {
        $this->authedAs($token);
        $this->post('/api/reservations', json_encode($data + [
            'room_id' => $this->roomId,
            'source' => 'walk_in',
        ]));

        return (array)json_decode((string)$this->_response->getBody(), true);
    }

    protected function tearDown(): void
    {
        $invoiceIds = $this->getTableLocator()->get('Invoices')->find()
            ->where(['property_id' => $this->propertyId])
            ->all()->extract('id')->toList();
        if ($invoiceIds) {
            $this->getTableLocator()->get('InvoiceLines')->deleteAll(['invoice_id IN' => $invoiceIds]);
        }
        $this->getTableLocator()->get('Reservations')->deleteAll(['property_id' => $this->propertyId]);
        $tables = ['FoodOrders', 'Invoices', 'Guests', 'RoomRates', 'Rooms', 'BookingSources', 'Users'];
        foreach ($tables as $name) {
            $this->getTableLocator()->get($name)->deleteAll(['property_id' => $this->propertyId]);
        }
        $this->getTableLocator()->get('Properties')->deleteAll(['id' => $this->propertyId]);

        parent::tearDown();
    }

    public function testReceptionistCannotBookAPastStay(): void
    {
        $this->book($this->receptionistToken, [
            'source' => 'agoda',
            'booking_reference' => 'AG-1',
            'check_in' => $this->day(-3),
            'check_out' => $this->day(-1),
        ]);

        $this->assertResponseCode(403);
        $this->assertSame(0, $this->getTableLocator()->get('Reservations')
            ->find()->where(['property_id' => $this->propertyId])->count());
    }

    public function testReceptionistWalkInWithAStaleDateStartsToday(): void
    {
        $body = $this->book($this->receptionistToken, [
            'check_in' => $this->day(-3),
            'check_out' => $this->day(2),
        ]);

        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $this->assertSame($this->day(0), $body['reservation']['check_in']);
        $this->assertSame('checked_in', $body['reservation']['status']);
    }

    public function testAdminPastStayThatEndedIsSavedCheckedOut(): void
    {
        $body = $this->book($this->adminToken, [
            'check_in' => $this->day(-5),
            'check_out' => $this->day(-2),
        ]);

        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $reservation = $body['reservation'];
        $this->assertSame('checked_out', $reservation['status']);
        $this->assertSame($this->day(-5), $reservation['check_in']);
        // The event times are the stay's own dates, not the moment of entry.
        $this->assertSame($this->day(-5), $this->localDay($reservation['checked_in_at']));
        $this->assertSame($this->day(-2), $this->localDay($reservation['checked_out_at']));
        $this->assertSame('available', $this->getTableLocator()->get('Rooms')->get($this->roomId)->status);
    }

    public function testAdminPastStayStillGoingIsSavedCheckedIn(): void
    {
        $body = $this->book($this->adminToken, [
            'check_in' => $this->day(-2),
            'check_out' => $this->day(1),
        ]);

        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $this->assertSame('checked_in', $body['reservation']['status']);
        $this->assertNull($body['reservation']['checked_out_at']);
        $this->assertSame('occupied', $this->getTableLocator()->get('Rooms')->get($this->roomId)->status);
    }

    public function testAdminPastStayCannotOverlapAFinishedOne(): void
    {
        $this->book($this->adminToken, ['check_in' => $this->day(-6), 'check_out' => $this->day(-3)]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());

        $body = $this->book($this->adminToken, ['check_in' => $this->day(-4), 'check_out' => $this->day(-1)]);

        $this->assertResponseCode(422);
        $this->assertArrayHasKey('roomAvailable', $body['errors']['room_id']);

        // Back to back is fine: the check-out day is free for the next stay.
        $this->book($this->adminToken, ['check_in' => $this->day(-3), 'check_out' => $this->day(-1)]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
    }

    public function testReceptionistCannotMoveABookingIntoThePast(): void
    {
        $body = $this->book($this->receptionistToken, [
            'source' => 'agoda',
            'booking_reference' => 'AG-2',
            'check_in' => $this->day(3),
            'check_out' => $this->day(5),
        ]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $id = $body['reservation']['id'];

        $this->authedAs($this->receptionistToken);
        $this->patch("/api/reservations/{$id}", json_encode(['check_in' => $this->day(-1)]));
        $this->assertResponseCode(403);

        // Leaving the date alone is still an ordinary edit.
        $this->authedAs($this->receptionistToken);
        $this->patch("/api/reservations/{$id}", json_encode([
            'check_in' => $this->day(3),
            'additional_beds' => 1,
        ]));
        $this->assertResponseOk((string)$this->_response->getBody());
    }

    /**
     * @return array<string, mixed> The saved reservation.
     */
    private function recordPastStay(int $from, int $to, array $extra = []): array
    {
        $body = $this->book(
            $this->adminToken,
            ['check_in' => $this->day($from), 'check_out' => $this->day($to)] + $extra,
        );
        $this->assertResponseCode(201, (string)$this->_response->getBody());

        return $body['reservation'];
    }

    public function testAdminCanCorrectAFinishedStay(): void
    {
        $stay = $this->recordPastStay(-5, -2);

        $this->authedAs($this->adminToken);
        $this->patch("/api/reservations/{$stay['id']}", json_encode([
            'check_in' => $this->day(-6),
            'check_out' => $this->day(-1),
        ]));

        $this->assertResponseOk((string)$this->_response->getBody());
        $saved = json_decode((string)$this->_response->getBody(), true)['reservation'];
        $this->assertSame('checked_out', $saved['status']);
        // The recorded moments move with the dates.
        $this->assertSame($this->day(-6), $this->localDay($saved['checked_in_at']));
        $this->assertSame($this->day(-1), $this->localDay($saved['checked_out_at']));
    }

    public function testAFinishedStayCannotBeMovedToEndAfterToday(): void
    {
        $stay = $this->recordPastStay(-5, -2);

        $this->authedAs($this->adminToken);
        $this->patch("/api/reservations/{$stay['id']}", json_encode(['check_out' => $this->day(2)]));

        $this->assertResponseCode(400);
    }

    public function testReceptionistCannotEditAFinishedStay(): void
    {
        $stay = $this->recordPastStay(-5, -2);

        $this->authedAs($this->receptionistToken);
        $this->patch("/api/reservations/{$stay['id']}", json_encode(['additional_beds' => 1]));

        $this->assertResponseCode(400);
    }

    public function testAdminDeletesAStayWithNothingTransacted(): void
    {
        $stay = $this->recordPastStay(-2, 1);
        $this->assertSame('occupied', $this->getTableLocator()->get('Rooms')->get($this->roomId)->status);

        $this->authedAs($this->adminToken);
        $this->delete("/api/reservations/{$stay['id']}");

        $this->assertResponseOk((string)$this->_response->getBody());
        $this->assertFalse($this->getTableLocator()->get('Reservations')->exists(['id' => $stay['id']]));
        // Nobody is recorded in the room any more.
        $this->assertSame('available', $this->getTableLocator()->get('Rooms')->get($this->roomId)->status);
    }

    public function testReceptionistCannotDelete(): void
    {
        $stay = $this->recordPastStay(-5, -2);

        $this->authedAs($this->receptionistToken);
        $this->delete("/api/reservations/{$stay['id']}");

        $this->assertResponseCode(403);
        $this->assertTrue($this->getTableLocator()->get('Reservations')->exists(['id' => $stay['id']]));
    }

    public function testOncePaidAStayCanNeitherBeEditedNorDeleted(): void
    {
        $stay = $this->recordPastStay(-5, -2, ['guest_name' => 'Paid Guest']);

        $this->authedAs($this->adminToken);
        $this->post("/api/reservations/{$stay['id']}/payment", json_encode(['payment_status' => 'paid']));
        $this->assertResponseOk((string)$this->_response->getBody());

        $this->authedAs($this->adminToken);
        $this->patch("/api/reservations/{$stay['id']}", json_encode(['additional_beds' => 1]));
        $this->assertResponseCode(400);

        $this->authedAs($this->adminToken);
        $this->delete("/api/reservations/{$stay['id']}");
        $this->assertResponseCode(400);
        $this->assertTrue($this->getTableLocator()->get('Reservations')->exists(['id' => $stay['id']]));
    }

    public function testTheListPagesAndAppliesTheSinceWindow(): void
    {
        // Six finished stays, back to back, all well before today.
        for ($i = 0; $i < 6; $i++) {
            $this->recordPastStay(-40 + $i * 3, -38 + $i * 3);
        }

        $this->authedAs($this->receptionistToken);
        $this->get('/api/reservations?page=2&limit=5');
        $this->assertResponseOk((string)$this->_response->getBody());
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(6, $body['total']);
        $this->assertCount(1, $body['reservations']);

        // "Today's activity" hides stays that finished before today.
        $this->authedAs($this->receptionistToken);
        $this->get('/api/reservations?limit=25&since=' . $this->day(0));
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(0, $body['total']);

        // The Calendar sees the stay touching a date, and only that one.
        $this->authedAs($this->receptionistToken);
        $this->get('/api/reservations?on_date=' . $this->day(-39));
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(1, $body['total']);
    }

    public function testStatsCountInTheDatabaseNotFromAPage(): void
    {
        $this->recordPastStay(-5, -2);
        $this->recordPastStay(-2, 0);

        $this->authedAs($this->receptionistToken);
        $this->get('/api/reservations/stats');
        $this->assertResponseOk((string)$this->_response->getBody());
        $stats = json_decode((string)$this->_response->getBody(), true);

        // Only the stay ending today was checked out today.
        $this->assertSame(1, $stats['checked_out_today']);
        $this->assertSame(0, $stats['booked']);
        $this->assertSame(0, $stats['cancelled_today']);
        // Neither was marked paid — a finished stay still owing counts.
        $this->assertSame(2, $stats['unpaid']);
    }

    public function testOnceSettledAReservationCantBeMarkedUnpaidEditedOrDeleted(): void
    {
        // A booking that isn't checked in yet: before this rule, only stays
        // already under way were locked once their charge was posted.
        $body = $this->book($this->adminToken, [
            'check_in' => $this->day(2),
            'check_out' => $this->day(4),
            'source' => 'agoda',
            'booking_reference' => 'AG-SETTLED',
        ]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $id = $body['reservation']['id'];

        // An invoice needs a guest. Attached after booking on purpose: booked
        // with one, this advance booking would take a downpayment, and that
        // locks edits for its own reason — this test is about settlement.
        $guests = $this->getTableLocator()->get('Guests');
        $guest = $guests->saveOrFail($guests->newEntity([
            'property_id' => $this->propertyId,
            'full_name' => 'Settled Guest',
            'guest_type' => 'local',
        ]));
        $reservations = $this->getTableLocator()->get('Reservations');
        $reservations->saveOrFail($reservations->get($id)->set('guest_id', $guest->id));

        $this->authedAs($this->adminToken);
        $this->post("/api/reservations/{$id}/payment", json_encode(['payment_status' => 'paid']));
        $this->assertResponseOk((string)$this->_response->getBody());

        $line = $this->getTableLocator()->get('InvoiceLines')->find()
            ->where(['source_type' => 'reservation', 'source_id' => $id])->firstOrFail();
        $this->authedAs($this->adminToken);
        $this->post("/api/invoices/{$line->invoice_id}/settle", json_encode([]));
        $this->assertResponseOk((string)$this->_response->getBody());

        $this->authedAs($this->adminToken);
        $this->post("/api/reservations/{$id}/payment", json_encode(['payment_status' => 'unpaid']));
        $this->assertResponseCode(400);

        $this->authedAs($this->adminToken);
        $this->patch("/api/reservations/{$id}", json_encode(['check_out' => $this->day(5)]));
        $this->assertResponseCode(400);

        $this->authedAs($this->adminToken);
        $this->delete("/api/reservations/{$id}");
        $this->assertResponseCode(400);
        $this->assertStringNotContainsString('Cancel it instead', (string)$this->_response->getBody());

        // The listing tells the table so.
        $this->authedAs($this->adminToken);
        $this->get('/api/reservations?limit=25');
        $rows = json_decode((string)$this->_response->getBody(), true)['reservations'];
        $this->assertSame('settled', $rows[0]['room_charge_invoice']);
    }

    public function testAFoodOrderDuringTheStayBlocksDelete(): void
    {
        $stay = $this->recordPastStay(-5, -2, ['guest_name' => 'Hungry Guest']);

        $orders = $this->getTableLocator()->get('FoodOrders');
        $admin = $this->getTableLocator()->get('Users')->find()
            ->where(['email' => 'backdating-admin@example.test'])->firstOrFail();
        $order = $orders->newEntity([
            'property_id' => $this->propertyId,
            'guest_id' => $stay['guest_id'],
            'receptionist_id' => $admin->id,
            'status' => 'served',
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'total' => 250,
        ]);
        // Dated inside the stay; Timestamp leaves an already-set `created` alone.
        $order->set('created', new DateTime($this->day(-3) . ' 12:00:00'));
        $orders->saveOrFail($order);

        $this->authedAs($this->adminToken);
        $this->delete("/api/reservations/{$stay['id']}");

        $this->assertResponseCode(400);
        $this->assertStringContainsString('food orders', (string)$this->_response->getBody());
    }
}
