<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use LogicException;

/**
 * Build step 9: configuration accountability (approved 2026-10-04, C1–C9 and
 * impact classes). Every change to a configuration row records one
 * `config_changes` row, in the save's transaction, with who, role, reason,
 * before/after and impact (price > booking > operational > administrative):
 * - a price change and every deletion need a reason (refused, nothing saved
 *   or recorded, without one);
 * - deletions are soft and hide the row;
 * - a save that changes nothing is refused and records nothing;
 * - side effects (a booking source created by a promo-rate save, the
 *   built-in early check-in charge) are recorded too;
 * - operational saves (room occupancy, a booklet's next number) record nothing;
 * - code can't save audited configuration without an actor, or hard-delete it.
 */
class ConfigAuditApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private string $adminToken;
    private string $deskToken;
    private string $ownerToken;
    private int $roomId;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Config Audit Inn');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "cfg-admin-$tag@example.test");
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', "cfg-desk-$tag@example.test");
        $this->ownerToken = $this->makeUser(null, 'owner', "cfg-owner-$tag@example.test");
        $this->roomId = $this->insertRow('Rooms', [
            'property_id' => $this->propertyId, 'room_number' => 'C-1', 'room_type' => 'Deluxe', 'status' => 'available',
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    /**
     * Changes recorded by requests (fixtures are recorded with source `system`).
     *
     * @return list<\Cake\Datasource\EntityInterface>
     */
    private function changes(string $entityType, ?int $entityId = null): array
    {
        $where = ['property_id' => $this->propertyId, 'entity_type' => $entityType, 'source' => 'web'];
        if ($entityId !== null) {
            $where['entity_id'] = $entityId;
        }

        return $this->getTableLocator()->get('ConfigChanges')->find()->where($where)->orderBy(['id' => 'ASC'])->all()->toList();
    }

    public function testARoomRateChangeRecordsWhoWhatWhyAndItsImpact(): void
    {
        $this->callAs($this->adminToken, 'POST', '/api/room-rates', ['room_id' => $this->roomId, 'base_rate' => 1000, 'description' => 'Sea view']);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $rateId = (int)$this->responseJson()['roomRate']['id'];
        [$created] = $this->changes('room_rate', $rateId);
        $this->assertSame(['created', 'price', 'admin'], [$created->event_type, $created->impact, $created->actor_role]);
        $this->assertSame($this->userIdFor($this->adminToken), (int)$created->actor_id);
        $this->assertEquals(1000, $created->changes['after']['base_rate']);
        $this->assertSame($this->_response->getHeaderLine('X-Request-Id'), $created->correlation_id);

        // A price change needs a reason: refused, nothing saved or recorded.
        $this->callAs($this->adminToken, 'PATCH', "/api/room-rates/$rateId", ['room_id' => $this->roomId, 'base_rate' => 1200, 'description' => 'Sea view']);
        $this->assertResponseCode(400);
        $this->assertEquals(1000, $this->getTableLocator()->get('RoomRates')->get($rateId)->base_rate);
        $this->assertCount(1, $this->changes('room_rate', $rateId));

        $this->callAs($this->adminToken, 'PATCH', "/api/room-rates/$rateId", ['room_id' => $this->roomId, 'base_rate' => 1200, 'description' => 'Sea view', 'reason' => 'Peak season']);
        $this->assertResponseOk((string)$this->_response->getBody());
        $updated = $this->changes('room_rate', $rateId)[1];
        $this->assertSame(['updated', 'price', 'Peak season'], [$updated->event_type, $updated->impact, $updated->reason]);
        $this->assertEquals(['base_rate' => ['before' => 1000, 'after' => 1200]], $updated->changes);

        // The same save again changes nothing: refused, nothing recorded.
        $this->callAs($this->adminToken, 'PATCH', "/api/room-rates/$rateId", ['room_id' => $this->roomId, 'base_rate' => 1200, 'description' => 'Sea view', 'reason' => 'Again']);
        $this->assertResponseCode(400);
        $this->assertStringContainsString('Nothing to change', (string)$this->responseJson()['message']);

        // A description is operational: no reason asked.
        $this->callAs($this->adminToken, 'PATCH', "/api/room-rates/$rateId", ['room_id' => $this->roomId, 'base_rate' => 1200, 'description' => 'Garden view']);
        $this->assertResponseOk();
        $this->assertSame('operational', $this->changes('room_rate', $rateId)[2]->impact);
        $this->assertCount(3, $this->changes('room_rate', $rateId));
    }

    public function testAPromoRateAndTheSourceItCreatesAreOneRecordedAction(): void
    {
        $this->callAs($this->adminToken, 'POST', '/api/promo-rates', ['source_name' => 'Agoda', 'multiplier' => 1.1]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $promoId = (int)$this->responseJson()['promoRate']['id'];
        $request = $this->_response->getHeaderLine('X-Request-Id');
        [$source] = $this->changes('booking_source');
        [$promo] = $this->changes('promo_rate', $promoId);
        $this->assertSame([$request, $request], [$source->correlation_id, $promo->correlation_id]);
        $this->assertSame(['created', 'booking'], [$source->event_type, $source->impact]);
        $this->assertSame('Agoda', $source->snapshot['label']);

        // Deleting is soft and needs a reason.
        $this->callAs($this->adminToken, 'DELETE', "/api/promo-rates/$promoId");
        $this->assertResponseCode(400);
        $this->callAs($this->adminToken, 'DELETE', "/api/promo-rates/$promoId", ['reason' => 'Agoda deal ended']);
        $this->assertResponseOk((string)$this->_response->getBody());
        $deleted = $this->changes('promo_rate', $promoId)[1];
        $this->assertSame(['deleted', 'Agoda deal ended'], [$deleted->event_type, $deleted->reason]);
        $this->assertEquals(1.1, $deleted->changes['before']['multiplier']);
        $this->callAs($this->adminToken, 'GET', '/api/promo-rates');
        $this->assertSame([], $this->responseJson()['promoRates'], 'a deleted promo is hidden');
        $this->assertNotNull($this->getTableLocator()->get('PromoRates')->find('all', withDeleted: true)
            ->where(['id' => $promoId])->firstOrFail()->deleted_at, 'but kept');
    }

    public function testExtraChargesTheBuiltInSeedAndImpactClasses(): void
    {
        // Opening the list creates the built-in early check-in charge: recorded as the system's.
        $this->callAs($this->adminToken, 'GET', '/api/extra-charges');
        $this->assertResponseOk();
        $seed = $this->getTableLocator()->get('ConfigChanges')->find()
            ->where(['property_id' => $this->propertyId, 'entity_type' => 'extra_charge'])->firstOrFail();
        $this->assertSame(['created', 'system'], [$seed->event_type, $seed->source]);
        $this->assertNull($seed->actor_id);

        $this->callAs($this->adminToken, 'POST', '/api/extra-charges', ['name' => 'Extra bed', 'amount' => 500]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $chargeId = (int)$this->responseJson()['extraCharge']['id'];
        $this->callAs($this->adminToken, 'PATCH', "/api/extra-charges/$chargeId", ['name' => 'Extra bed', 'amount' => 500, 'is_active' => false]);
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->assertSame('booking', $this->changes('extra_charge', $chargeId)[1]->impact, 'switching a charge off changes what can be booked');
        $this->callAs($this->adminToken, 'PATCH', "/api/extra-charges/$chargeId", ['name' => 'Extra bed', 'amount' => 650, 'is_active' => false]);
        $this->assertResponseCode(400, 'a price change needs a reason');
    }

    public function testRoomsAreAuditedButTheirOccupancyIsNot(): void
    {
        $this->callAs($this->adminToken, 'POST', '/api/rooms', ['room_number' => 'C-2', 'room_type' => 'Twin', 'status' => 'available']);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $roomId = (int)$this->responseJson()['room']['id'];
        $this->assertSame('booking', $this->changes('room', $roomId)[0]->impact);

        // Front Desk marking a room for maintenance is operational state, not configuration.
        $this->callAs($this->deskToken, 'PATCH', "/api/rooms/$roomId", [
            'room_number' => 'C-2', 'room_type' => 'Twin', 'status' => 'maintenance',
        ]);
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->assertCount(1, $this->changes('room', $roomId));

        $this->callAs($this->adminToken, 'DELETE', "/api/rooms/$roomId", ['reason' => 'Room merged into C-1']);
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->assertSame('deleted', $this->changes('room', $roomId)[1]->event_type);
        $this->callAs($this->adminToken, 'GET', '/api/rooms');
        $this->assertNotContains($roomId, array_map('intval', array_column($this->responseJson()['rooms'], 'id')));
    }

    public function testAMenuItemAndItsOptionsAreOneChange(): void
    {
        $item = [
            'name' => 'Tapsilog', 'type' => 'food', 'price' => 180,
            'option_groups' => [['name' => 'Drink', 'kind' => 'choice', 'options' => [['label' => 'Iced tea', 'price_delta' => 0], ['label' => 'Coffee', 'price_delta' => 20]]]],
        ];
        $this->callAs($this->adminToken, 'POST', '/api/food-menu-items', $item);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $menuId = (int)$this->responseJson()['menuItem']['id'];
        [$created] = $this->changes('menu_item', $menuId);
        $this->assertSame('created', $created->event_type);
        $this->assertEquals(20, $created->changes['after']['option_prices'][1]['price_delta']);

        // An option's price is a price change: refused without a reason.
        $item['option_groups'][0]['options'][1]['price_delta'] = 25;
        $this->callAs($this->adminToken, 'PATCH', "/api/food-menu-items/$menuId", $item);
        $this->assertResponseCode(400);
        $this->assertCount(1, $this->changes('menu_item', $menuId));
        $this->callAs($this->adminToken, 'PATCH', "/api/food-menu-items/$menuId", $item + ['reason' => 'Coffee supplier raised prices']);
        $this->assertResponseOk((string)$this->_response->getBody());
        $updated = $this->changes('menu_item', $menuId)[1];
        $this->assertSame(['updated', 'price'], [$updated->event_type, $updated->impact]);
        $this->assertEquals(20, $updated->changes['option_prices']['before'][1]['price_delta']);
        $this->assertEquals(25, $updated->changes['option_prices']['after'][1]['price_delta']);

        // The same again: nothing to change.
        $this->callAs($this->adminToken, 'PATCH', "/api/food-menu-items/$menuId", $item + ['reason' => 'Again']);
        $this->assertResponseCode(400);
        $this->assertCount(2, $this->changes('menu_item', $menuId));
    }

    public function testUsingAReceiptBookletIsNotAConfigurationChange(): void
    {
        $this->callAs($this->adminToken, 'POST', '/api/receipt-series', ['type' => 'invoice', 'start_number' => 1, 'end_number' => 50]);
        $this->assertResponseOk((string)$this->_response->getBody());
        $seriesId = (int)$this->responseJson()['series']['id'];
        $this->getTableLocator()->get('ReceiptSeries')->assignNext($this->propertyId, 'invoice');
        $this->assertCount(1, $this->changes('receipt_series', $seriesId), 'issuing number 1 records nothing');
    }

    public function testThePlatformOwnersPropertyChangesAreAudited(): void
    {
        $this->callAs($this->ownerToken, 'PATCH', "/api/properties/{$this->propertyId}", ['subscription_fee' => 2500]);
        $this->assertResponseCode(400, 'the fee is a price');
        $this->callAs($this->ownerToken, 'PATCH', "/api/properties/{$this->propertyId}", ['subscription_fee' => 2500, 'reason' => 'New annual plan']);
        $this->assertResponseOk((string)$this->_response->getBody());
        [$change] = $this->changes('property', $this->propertyId);
        $this->assertSame(['updated', 'price', 'owner'], [$change->event_type, $change->impact, $change->actor_role]);
    }

    public function testCodeCannotChangeConfigurationAroundTheAudit(): void
    {
        $rates = $this->getTableLocator()->get('RoomRates');
        $rate = $rates->newEntity(['property_id' => $this->propertyId, 'room_id' => $this->roomId, 'base_rate' => 900]);
        try {
            $rates->save($rate);
            $this->fail('A configuration save without an actor must be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('EventContext', $e->getMessage());
        }

        $rooms = $this->getTableLocator()->get('Rooms');
        $this->expectException(LogicException::class);
        $rooms->delete($rooms->get($this->roomId));
    }
}
