<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Pins what Operations → Activity shows today, before it moves onto the
 * shared event index (build step 5). The move must leave every assertion
 * here green: same items, same fields, same order, same paging.
 *
 * The test clock is frozen, so every row gets an explicit, distinct time.
 * Two rows at the same instant have no promised order.
 */
class ActivityFeedApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private int $otherPropertyId;
    private string $adminToken;
    private int $adminId;
    private string $deskName;
    private int $deskId;
    private int $itemId;
    private int $roomId;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Activity Feed Resort');
        $this->otherPropertyId = $this->createProperty('Activity Feed Other');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "feed-admin-$tag@example.test");
        $this->adminId = $this->userIdFor($this->adminToken);
        $deskToken = $this->makeUser($this->propertyId, 'receptionist', "feed-desk-$tag@example.test");
        $this->deskId = $this->userIdFor($deskToken);
        $this->deskName = (string)$this->getTableLocator()->get('Users')->get($this->deskId)->name;

        $categoryId = $this->insertRow('InventoryCategories', [
            'property_id' => $this->propertyId, 'name' => 'Linens', 'kind' => 'linen',
        ]);
        $this->itemId = $this->insertRow('InventoryItems', [
            'property_id' => $this->propertyId, 'inventory_category_id' => $categoryId,
            'name' => 'Bath towel', 'unit' => 'pc',
            'quantity' => 10, 'tracking_type' => 'consumable',
        ]);
        $this->roomId = $this->insertRow('Rooms', [
            'property_id' => $this->propertyId, 'room_number' => 'F-101', 'room_type' => 'Deluxe',
            'status' => 'available',
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    private function at(int $minutes): DateTime
    {
        return new DateTime('2026-09-30 00:00:00 +' . $minutes . ' minutes');
    }

    private function movement(int $minutes, string $direction, float $qty, ?string $reason, ?int $propertyId = null): int
    {
        return $this->insertRow('StockMovements', [
            'property_id' => $propertyId ?? $this->propertyId, 'inventory_item_id' => $this->itemId,
            'receptionist_id' => $this->deskId, 'direction' => $direction, 'quantity' => $qty,
            'reason' => $reason, 'created' => $this->at($minutes),
        ]);
    }

    private function order(int $minutes, float $total, string $payment, string $status, ?int $roomId): int
    {
        return $this->insertRow('FoodOrders', [
            'property_id' => $this->propertyId, 'receptionist_id' => $this->adminId, 'room_id' => $roomId,
            'status' => $status, 'payment_status' => $payment,
            'payment_method' => $payment === 'paid' ? 'cash' : null,
            'total' => $total, 'total_diners' => 1,
            'created' => $this->at($minutes), 'modified' => $this->at($minutes),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function feed(int $page = 1): array
    {
        $this->callAs($this->adminToken, 'GET', "/api/operations/activity?page=$page");
        $this->assertResponseOk();

        return $this->responseJson();
    }

    public function testItemsCarryTheirFieldsNewestFirst(): void
    {
        $in = $this->movement(1, 'in', 5, 'restock');
        $order = $this->order(2, 350.5, 'charge_to_room', 'served', $this->roomId);
        $out = $this->movement(3, 'out', 2, null);
        $walkUp = $this->order(4, 120, 'paid', 'cancelled', null);
        $this->movement(5, 'in', 1, 'not ours', $this->otherPropertyId);

        $feed = $this->feed();
        $user = $this->getTableLocator()->get('Users')->get($this->adminId);

        $this->assertSame([
            [
                'type' => 'order', 'id' => "order-$walkUp", 'at' => $this->at(4)->format('c'),
                'actor' => $user->name, 'order_id' => $walkUp, 'total' => 120.0, 'room' => null,
                'payment_status' => 'paid', 'status' => 'cancelled',
            ],
            [
                'type' => 'stock', 'id' => "stock-$out", 'at' => $this->at(3)->format('c'),
                'actor' => $this->deskName, 'direction' => 'out', 'quantity' => 2.0, 'item' => 'Bath towel',
                'unit' => 'pc', 'reason' => null,
            ],
            [
                'type' => 'order', 'id' => "order-$order", 'at' => $this->at(2)->format('c'),
                'actor' => $user->name, 'order_id' => $order, 'total' => 350.5, 'room' => 'F-101',
                'payment_status' => 'charge_to_room', 'status' => 'served',
            ],
            [
                'type' => 'stock', 'id' => "stock-$in", 'at' => $this->at(1)->format('c'),
                'actor' => $this->deskName, 'direction' => 'in', 'quantity' => 5.0, 'item' => 'Bath towel',
                'unit' => 'pc', 'reason' => 'restock',
            ],
        ], $this->normalize($feed['activity']), 'only this property, newest first, every field');
        $this->assertSame(1, $feed['page']);
        $this->assertFalse($feed['has_more']);
    }

    /**
     * An order shows its status now, not when it was placed: cancelling it
     * later changes the existing item rather than adding one.
     */
    public function testAnOrderShowsItsCurrentStatus(): void
    {
        $id = $this->order(1, 80, 'paid', 'open', null);
        $this->getTableLocator()->get('FoodOrders')->updateAll(['status' => 'cancelled'], ['id' => $id]);

        $items = $this->feed()['activity'];
        $this->assertCount(1, $items);
        $this->assertSame('cancelled', $items[0]['status']);
    }

    public function testPagesHold25AndSayWhetherMoreExist(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $i % 2 ? $this->movement($i, 'out', 1, null) : $this->order($i, 10, 'unpaid', 'open', null);
        }

        $first = $this->feed(1);
        $this->assertCount(25, $first['activity']);
        $this->assertTrue($first['has_more']);
        $this->assertSame($this->at(30)->format('c'), $this->normalize($first['activity'])[0]['at']);

        $second = $this->feed(2);
        $this->assertCount(5, $second['activity']);
        $this->assertFalse($second['has_more']);
        $this->assertSame($this->at(1)->format('c'), $this->normalize($second['activity'])[4]['at']);

        $this->callAs($this->adminToken, 'GET', '/api/operations/activity?page=41');
        $this->assertResponseCode(400);
    }

    public function testTheOperationsCardShowsTheLatestEight(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->movement($i, 'in', 1, null);
        }
        $this->callAs($this->adminToken, 'GET', '/api/operations/today');
        $this->assertResponseOk();
        $activity = $this->normalize($this->responseJson()['operations']['activity']);

        $this->assertCount(8, $activity);
        $this->assertSame($this->at(10)->format('c'), $activity[0]['at']);
        $this->assertSame($this->at(3)->format('c'), $activity[7]['at']);
    }

    /**
     * Times as ISO 8601 in UTC and numbers as numbers, so the comparison is
     * about content rather than JSON formatting.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function normalize(array $items): array
    {
        return array_map(function (array $item): array {
            $item['at'] = (new DateTime($item['at']))->setTimezone('UTC')->format('c');
            foreach (['total', 'quantity'] as $key) {
                if (isset($item[$key])) {
                    $item[$key] = (float)$item[$key];
                }
            }

            return $item;
        }, $items);
    }
}
