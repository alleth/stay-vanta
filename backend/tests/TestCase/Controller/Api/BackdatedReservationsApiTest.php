<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Model\Table\UsersTable;
use Cake\I18n\Date;
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

    private function day(int $offset): string
    {
        return Date::today()->addDays($offset)->format('Y-m-d');
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
        $this->getTableLocator()->get('Reservations')->deleteAll(['property_id' => $this->propertyId]);
        foreach (['Invoices', 'Guests', 'RoomRates', 'Rooms', 'BookingSources', 'Users'] as $name) {
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
        $this->assertStringStartsWith($this->day(-5), $reservation['checked_in_at']);
        $this->assertStringStartsWith($this->day(-2), $reservation['checked_out_at']);
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
}
