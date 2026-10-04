<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Model\BusinessTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Build step 9, part 2: "what changed the price?" (C1, approved 2026-10-04).
 * A booking's nightly rate is fixed when it's made; a later rate change
 * affects only new bookings; editing the room or source re-prices it, with
 * the old and new rate in its event. Every price-setting event records the
 * price and where the rate came from, and GET /reservations/{id}/price
 * explains it: the price used, its source, its discounts, the configuration
 * changes made afterwards (and whether they moved it), and what was billed.
 * Pinned in pesos.
 */
class PriceProvenanceApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private string $adminToken;
    private string $deskToken;
    private int $roomA;
    private int $roomB;
    private int $propertyRate;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Price Provenance Inn');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "price-admin-$tag@example.test");
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', "price-desk-$tag@example.test");
        $this->roomA = $this->insertRow('Rooms', [
            'property_id' => $this->propertyId, 'room_number' => 'P-1', 'room_type' => 'Suite', 'status' => 'available',
        ]);
        $this->roomB = $this->insertRow('Rooms', [
            'property_id' => $this->propertyId, 'room_number' => 'P-2', 'room_type' => 'Twin', 'status' => 'available',
        ]);
        $this->propertyRate = $this->insertRow('RoomRates', ['property_id' => $this->propertyId, 'room_id' => null, 'base_rate' => 1000]);
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

    private function raisePropertyRate(int $to, string $reason): void
    {
        $this->callAs($this->adminToken, 'PATCH', "/api/room-rates/{$this->propertyRate}", [
            'room_id' => '', 'base_rate' => $to, 'reason' => $reason,
        ]);
        $this->assertResponseOk((string)$this->_response->getBody());
    }

    /** The room charge posted for a stay (its `reservation` line). */
    private function postedCharge(int $id): float
    {
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$id/post-room-charge");
        $this->assertResponseOk((string)$this->_response->getBody());

        return (float)$this->getTableLocator()->get('InvoiceLines')->find()
            ->where(['source_type' => 'reservation', 'source_id' => $id])->firstOrFail()->amount;
    }

    /**
     * @return array<string, mixed>
     */
    private function price(int $id): array
    {
        $this->callAs($this->adminToken, 'GET', "/api/reservations/$id/price");
        $this->assertResponseOk((string)$this->_response->getBody());

        return $this->responseJson();
    }

    public function testARateFixedAtBookingIsWhatIsBilled(): void
    {
        // Booked at ₱1,000 a night for 2 nights.
        $this->callAs($this->deskToken, 'POST', '/api/reservations', [
            'room_id' => $this->roomB, 'source' => 'walk_in', 'guest_name' => 'Locked Guest', 'check_out' => $this->day(2),
        ]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $id = (int)$this->responseJson()['reservation']['id'];
        $reservation = $this->getTableLocator()->get('Reservations')->get($id);
        $this->assertEquals(1000, $reservation->nightly_rate);
        $this->assertSame($this->propertyRate, $reservation->rate_source['room_rate_id']);
        $this->assertSame('property', $reservation->rate_source['scope']);

        // The Manager raises the rate to ₱1,200 afterwards.
        $this->raisePropertyRate(1200, 'Peak season');

        // Billed at the rate it was booked at: 2 × ₱1,000.
        $this->assertSame(2000.0, $this->postedCharge($id));

        $price = $this->price($id);
        $this->assertSame('locked', $price['basis']);
        $this->assertEquals(2000, $price['current']['total']);
        $this->assertSame('walked_in', $price['history'][0]['event']);
        $this->assertEquals(1000, $price['history'][0]['price']['nightly_rate']);
        $this->assertEquals(2000, $price['history'][0]['price']['total']);
        $this->assertStringContainsString('price-desk', (string)$price['history'][0]['actor']);
        [$change] = $price['config_changes'];
        $this->assertSame(['room_rate', 'updated', 'Peak season', false], [
            $change['entity_type'], $change['event'], $change['reason'], $change['moved_this_price'],
        ]);
        $this->assertEquals(['before' => 1000, 'after' => 1200], $change['changes']['base_rate']);
        $this->assertStringContainsString('fixed at booking', $change['why']);
        $this->assertEquals(2000, $price['posted'][0]['amount']);
    }

    /**
     * Decided 2026-10-05: Front Desk sees the guest-facing explanation (the
     * change, its reason, whether it moved the price) but not who made it;
     * the Manager sees who.
     */
    public function testFrontDeskSeesWhyButNotWhoChangedTheRate(): void
    {
        $this->callAs($this->deskToken, 'POST', '/api/reservations', [
            'room_id' => $this->roomB, 'source' => 'walk_in', 'guest_name' => 'Visible Guest', 'check_out' => $this->day(1),
        ]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $id = (int)$this->responseJson()['reservation']['id'];
        $this->raisePropertyRate(1300, 'New season');

        $this->callAs($this->deskToken, 'GET', "/api/reservations/$id/price");
        $this->assertResponseOk();
        $desk = $this->responseJson();
        $this->assertFalse($desk['shows_actors']);
        $this->assertSame('New season', $desk['config_changes'][0]['reason']);
        $this->assertEquals(['before' => 1000, 'after' => 1300], $desk['config_changes'][0]['changes']['base_rate']);
        $this->assertNull($desk['config_changes'][0]['actor'], 'the Manager’s name stays with Managers');
        $this->assertStringNotContainsString('price-admin', (string)$this->_response->getBody());
        $this->assertStringContainsString('price-desk', (string)$desk['history'][0]['actor'], 'their own booking events still say who');

        $manager = $this->price($id);
        $this->assertTrue($manager['shows_actors']);
        $this->assertStringContainsString('price-admin', (string)$manager['config_changes'][0]['actor']);
    }

    public function testABookingFromBeforeStep9StillReadsTheLiveRateAndSaysWhy(): void
    {
        $id = $this->insertRow('Reservations', [
            'property_id' => $this->propertyId, 'room_id' => $this->roomB,
            'guest_id' => $this->insertRow('Guests', ['property_id' => $this->propertyId, 'full_name' => 'Live Guest', 'guest_type' => 'local']),
            'status' => 'checked_in', 'source' => 'walk_in', 'payment_status' => 'unpaid', 'total_guests' => 1,
            'check_in' => $this->day(0), 'check_out' => $this->day(2),
        ]);
        $this->raisePropertyRate(1200, 'Peak season');

        // No rate was fixed: billed at today's ₱1,200.
        $this->assertSame(2400.0, $this->postedCharge($id));

        $price = $this->price($id);
        $this->assertSame('live', $price['basis']);
        $this->assertSame([], $price['history'], 'no price was recorded before step 9');
        [$change] = $price['config_changes'];
        $this->assertTrue($change['moved_this_price']);
        $this->assertSame('Peak season', $change['reason']);
        $this->assertNotEmpty($price['notes']);
    }

    public function testChangingTheRoomRepricesAndTheEventSaysSo(): void
    {
        $this->insertRow('RoomRates', ['property_id' => $this->propertyId, 'room_id' => $this->roomA, 'base_rate' => 1500]);
        $this->callAs($this->deskToken, 'POST', '/api/reservations', [
            'room_id' => $this->roomA, 'source' => 'agoda', 'booking_reference' => 'AG-P1',
            'check_in' => $this->day(1), 'check_out' => $this->day(3), 'total_guests' => 1,
        ]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $id = (int)$this->responseJson()['reservation']['id'];
        $this->assertEquals(1500, $this->getTableLocator()->get('Reservations')->get($id)->nightly_rate);

        // A change that doesn't touch the room or source keeps the fixed rate.
        $this->callAs($this->deskToken, 'PATCH', "/api/reservations/$id", ['total_guests' => 2]);
        $this->assertResponseOk();
        $this->assertEquals(1500, $this->getTableLocator()->get('Reservations')->get($id)->nightly_rate);

        $this->callAs($this->deskToken, 'PATCH', "/api/reservations/$id", ['room_id' => $this->roomB]);
        $this->assertResponseOk((string)$this->_response->getBody());
        $event = $this->getTableLocator()->get('ReservationEvents')->find()
            ->where(['reservation_id' => $id])->orderBy(['id' => 'DESC'])->firstOrFail();
        $this->assertEquals(['before' => 1500, 'after' => 1000], $event->changes['nightly_rate']);
        $this->assertEquals(2000, $event->snapshot['price']['total']);
        $this->assertSame('property', $event->snapshot['price']['rate_source']['scope']);
    }

    public function testAChannelPromoIsTheRateAndItsSourceIsRecorded(): void
    {
        $promoId = $this->insertRow('PromoRates', ['property_id' => $this->propertyId, 'room_id' => null, 'source' => 'agoda', 'multiplier' => 1.1]);
        $this->callAs($this->deskToken, 'POST', '/api/reservations', [
            'room_id' => $this->roomB, 'source' => 'agoda', 'booking_reference' => 'AG-P2',
            'check_in' => $this->day(1), 'check_out' => $this->day(2), 'total_guests' => 1,
        ]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $id = (int)$this->responseJson()['reservation']['id'];

        $price = $this->price($id);
        $this->assertSame('promo', $price['basis']);
        $this->assertEquals(1100, $price['current']['nightly_rate']);
        $this->assertSame($promoId, $price['rate_source']['promo_rate_id']);
        $this->assertEquals(1.1, $price['rate_source']['multiplier']);
        $this->assertEquals(1000, $price['rate_source']['base_rate']);
    }
}
