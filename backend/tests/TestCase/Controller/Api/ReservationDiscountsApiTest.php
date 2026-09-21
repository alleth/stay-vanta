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
 * Booking with Senior/PWD beneficiaries, over HTTP.
 *
 * ReservationsTableTest covers the arithmetic; this covers the wiring around
 * it — that the beneficiaries survive the round trip, that an edit replaces
 * them rather than adding to them, and that the two rules protecting the
 * discount (a name and ID on each, never more of them than guests) are
 * actually enforced at the door. A booking's discount ends up on a real
 * invoice, so "it prices right in isolation" isn't the whole question.
 *
 * There are no fixtures in this suite, so the rows are built in setUp() and
 * removed in tearDown(), scoped to this test's own property.
 */
class ReservationDiscountsApiTest extends TestCase
{
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private int $roomId;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $properties = $this->getTableLocator()->get('Properties');
        $rooms = $this->getTableLocator()->get('Rooms');
        $rates = $this->getTableLocator()->get('RoomRates');
        $users = $this->getTableLocator()->get('Users');

        $property = $properties->saveOrFail($properties->newEntity(['name' => 'Discounts API Resort']));
        $this->propertyId = (int)$property->id;

        $room = $rooms->saveOrFail($rooms->newEntity([
            'property_id' => $this->propertyId,
            'room_number' => 'DISC-1',
            'room_type' => 'Deluxe',
            'status' => 'available',
        ]));
        $this->roomId = (int)$room->id;

        $rates->saveOrFail($rates->newEntity([
            'property_id' => $this->propertyId,
            'room_id' => $this->roomId,
            'base_rate' => 1500.0,
        ]));

        [$token, $digest] = UsersTable::issueToken();
        $this->token = $token;
        $users->saveOrFail($users->newEntity([
            'property_id' => $this->propertyId,
            'name' => 'Discounts API Receptionist',
            'email' => 'discounts-api@example.test',
            'password' => 'secret123',
            'role' => 'receptionist',
            'is_active' => true,
        ]));
        $user = $users->find()->where(['email' => 'discounts-api@example.test'])->firstOrFail();
        $user->set('api_token', $digest);
        $user->set('token_expires', new DateTime('+30 days'));
        $users->saveOrFail($user);

        $this->authed();
    }

    /**
     * Sign the next request as the receptionist.
     *
     * Called before every request rather than once in setUp(): the request
     * config doesn't survive a request, so a test's second call would
     * otherwise go out unauthenticated and come back 401.
     */
    private function authed(): void
    {
        $this->configRequest([
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $reservations = $this->getTableLocator()->get('Reservations');
        $ids = $reservations->find()
            ->where(['property_id' => $this->propertyId])
            ->all()
            ->extract('id')
            ->toList();
        if ($ids) {
            $this->getTableLocator()->get('ReservationDiscounts')->deleteAll(['reservation_id IN' => $ids]);
        }
        $reservations->deleteAll(['property_id' => $this->propertyId]);
        foreach (['Invoices', 'Guests', 'RoomRates', 'Rooms', 'BookingSources', 'Users'] as $name) {
            $this->getTableLocator()->get($name)->deleteAll(['property_id' => $this->propertyId]);
        }
        $this->getTableLocator()->get('Properties')->deleteAll(['id' => $this->propertyId]);

        parent::tearDown();
    }

    public function testBookingWithTwoBeneficiariesPricesAndListsThem(): void
    {
        $this->post('/api/reservations', json_encode([
            'room_id' => $this->roomId,
            'check_in' => Date::today()->format('Y-m-d'),
            'check_out' => Date::today()->addDays(3)->format('Y-m-d'),
            'source' => 'walk_in',
            'guest_name' => 'Test Guest',
            'total_guests' => 3,
            'discount_beneficiaries' => [
                ['discount_type' => 'senior', 'name' => 'Lola Remy', 'id_number' => 'SC-1'],
                ['discount_type' => 'pwd', 'name' => 'Mang Tonyo', 'id_number' => 'PWD-2'],
            ],
        ]));

        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $body = json_decode((string)$this->_response->getBody(), true);
        $reservation = $body['reservation'];

        $this->assertSame(3, $reservation['total_guests']);
        $this->assertCount(2, $reservation['reservation_discounts']);
        // 4500 * 2/3 * 20% = 600, split 300/300.
        $this->assertEqualsWithDelta(600.0, $reservation['quote']['statutory_discount'], 0.001);
        $this->assertEqualsWithDelta([300.0, 300.0], $reservation['quote']['statutory_shares'], 0.001);
        $this->assertEqualsWithDelta(3900.0, $reservation['quote']['total'], 0.001);

        // The index carries them too (it's what the table's badges read).
        $this->authed();
        $this->get('/api/reservations');
        $this->assertResponseOk();
        $listed = json_decode((string)$this->_response->getBody(), true)['reservations'][0];
        $this->assertCount(2, $listed['reservation_discounts']);
        $this->assertSame('Lola Remy', $listed['reservation_discounts'][0]['beneficiary_name']);
    }

    public function testEditReplacesTheBeneficiariesWholesale(): void
    {
        // An online booking stays `booked` (a walk-in checks in on save, and
        // edit() refuses a checked-in booking), and today's date keeps it out
        // of downpayment territory, which would block the edit too.
        $sources = $this->getTableLocator()->get('BookingSources');
        $sources->saveOrFail($sources->newEntity([
            'property_id' => $this->propertyId,
            'code' => 'agoda',
            'name' => 'Agoda',
        ]));

        $this->post('/api/reservations', json_encode([
            'room_id' => $this->roomId,
            'check_in' => Date::today()->format('Y-m-d'),
            'check_out' => Date::today()->addDays(3)->format('Y-m-d'),
            'source' => 'agoda',
            'booking_reference' => 'AG-123',
            'guest_name' => 'Test Guest',
            'total_guests' => 2,
            'discount_beneficiaries' => [
                ['discount_type' => 'senior', 'name' => 'Lola Remy', 'id_number' => 'SC-1'],
            ],
        ]));
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $created = json_decode((string)$this->_response->getBody(), true)['reservation'];
        $id = $created['id'];
        $this->assertSame('booked', $created['status']);
        $this->assertEqualsWithDelta(450.0, $created['quote']['statutory_discount'], 0.001);

        // Swap the one senior for two PWD guests: the old row must go, not
        // linger alongside the new ones.
        $this->authed();
        $this->patch("/api/reservations/{$id}", json_encode([
            'total_guests' => 2,
            'discount_beneficiaries' => [
                ['discount_type' => 'pwd', 'name' => 'Mang Tonyo', 'id_number' => 'PWD-1'],
                ['discount_type' => 'pwd', 'name' => 'Aling Bebang', 'id_number' => 'PWD-2'],
            ],
        ]));
        $this->assertResponseOk((string)$this->_response->getBody());
        $edited = json_decode((string)$this->_response->getBody(), true)['reservation'];

        $this->assertCount(2, $edited['reservation_discounts']);
        $this->assertSame(
            ['pwd', 'pwd'],
            array_column($edited['reservation_discounts'], 'discount_type'),
        );
        // 2 of 2 guests now qualify: the full 20% of 4500.
        $this->assertEqualsWithDelta(900.0, $edited['quote']['statutory_discount'], 0.001);

        // Clearing them entirely drops the discount.
        $this->authed();
        $this->patch("/api/reservations/{$id}", json_encode(['discount_beneficiaries' => []]));
        $this->assertResponseOk((string)$this->_response->getBody());
        $cleared = json_decode((string)$this->_response->getBody(), true)['reservation'];
        $this->assertCount(0, $cleared['reservation_discounts']);
        $this->assertEqualsWithDelta(0.0, $cleared['quote']['statutory_discount'], 0.001);
    }

    public function testMoreBeneficiariesThanGuestsIsRejected(): void
    {
        $this->post('/api/reservations', json_encode([
            'room_id' => $this->roomId,
            'check_in' => Date::today()->format('Y-m-d'),
            'check_out' => Date::today()->addDays(2)->format('Y-m-d'),
            'source' => 'walk_in',
            'guest_name' => 'Test Guest',
            'total_guests' => 1,
            'discount_beneficiaries' => [
                ['discount_type' => 'senior', 'name' => 'A', 'id_number' => '1'],
                ['discount_type' => 'senior', 'name' => 'B', 'id_number' => '2'],
            ],
        ]));
        $this->assertResponseCode(400);
        $this->assertStringContainsString('cannot exceed', (string)$this->_response->getBody());
    }

    public function testABeneficiaryWithoutAnIdIsRejected(): void
    {
        $this->post('/api/reservations', json_encode([
            'room_id' => $this->roomId,
            'check_in' => Date::today()->format('Y-m-d'),
            'check_out' => Date::today()->addDays(2)->format('Y-m-d'),
            'source' => 'walk_in',
            'guest_name' => 'Test Guest',
            'discount_beneficiaries' => [
                ['discount_type' => 'senior', 'name' => 'Lola Remy', 'id_number' => ''],
            ],
        ]));
        $this->assertResponseCode(400);
        $this->assertStringContainsString('ID number', (string)$this->_response->getBody());
    }
}
