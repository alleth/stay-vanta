<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Event\ActivityBackfill;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * The feed read from activity_index (step 5, part 3) must show exactly what
 * the earlier two-table merge showed, page by page: same lines, same fields,
 * same values, same order — including ties, renamed items, cancelled sales,
 * and a mix of backfilled history and sales recorded live.
 *
 * referenceFeed() is the pre-part-3 implementation, kept here as the oracle.
 */
class FeedEquivalenceApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private int $otherId;
    private string $adminToken;
    private string $deskToken;
    private int $deskId;
    private int $adminId;
    private int $menuId;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Feed Equivalence Resort');
        $this->otherId = $this->createProperty('Feed Equivalence Other');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "eq-admin-$tag@example.test");
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', "eq-desk-$tag@example.test");
        $this->adminId = $this->userIdFor($this->adminToken);
        $this->deskId = $this->userIdFor($this->deskToken);
        $this->categoryId = $this->insertRow('InventoryCategories', [
            'property_id' => $this->propertyId, 'name' => 'Stores', 'kind' => 'other',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->menuId)) {
            $this->getTableLocator()->get('Properties')->getConnection()
                ->delete('food_menu_item_ingredients', ['food_menu_item_id' => $this->menuId]);
        }
        $this->cleanupScenario();
        parent::tearDown();
    }

    private function at(int $minutes): DateTime
    {
        return new DateTime('2026-09-01 00:00:00 +' . $minutes . ' minutes');
    }

    private function item(string $name, float $qty, int $propertyId): int
    {
        return $this->insertRow('InventoryItems', [
            'property_id' => $propertyId, 'inventory_category_id' => $this->categoryId,
            'name' => $name, 'unit' => 'pc', 'quantity' => $qty, 'tracking_type' => 'consumable',
        ]);
    }

    /**
     * History, sales recorded live, ties, a rename and cancellations.
     */
    private function arrange(): void
    {
        $towel = $this->item('Towel', 500, $this->propertyId);
        $soap = $this->item('Soap', 500, $this->propertyId);
        $alien = $this->item('Not ours', 50, $this->otherId);
        $roomId = $this->insertRow('Rooms', [
            'property_id' => $this->propertyId, 'room_number' => 'Q-1', 'room_type' => 'Twin', 'status' => 'occupied',
        ]);

        for ($i = 1; $i <= 40; $i++) {
            // Every third minute a movement and a sale share the instant.
            $this->insertRow('StockMovements', [
                'property_id' => $this->propertyId, 'inventory_item_id' => $i % 2 ? $towel : $soap,
                'receptionist_id' => $i % 3 ? $this->deskId : $this->adminId,
                'direction' => $i % 4 ? 'out' : 'in', 'quantity' => $i, 'reason' => $i % 5 ? 'consumed' : null,
                'created' => $this->at($i),
            ]);
            if ($i % 3 === 0) {
                $this->insertRow('FoodOrders', [
                    'property_id' => $this->propertyId, 'receptionist_id' => $this->deskId,
                    'room_id' => $i % 2 ? $roomId : null, 'status' => $i % 6 ? 'served' : 'cancelled',
                    'payment_status' => $i % 2 ? 'charge_to_room' : 'paid',
                    'payment_method' => $i % 2 ? null : 'cash', 'total' => $i * 10.25, 'total_diners' => 1,
                    'created' => $this->at($i), 'modified' => $this->at($i),
                ]);
            }
            // Two movements in the same minute (tie between movements).
            if ($i % 10 === 0) {
                $this->insertRow('StockMovements', [
                    'property_id' => $this->propertyId, 'inventory_item_id' => $soap, 'receptionist_id' => $this->deskId,
                    'direction' => 'in', 'quantity' => 1, 'reason' => 'restock', 'created' => $this->at($i),
                ]);
            }
        }
        $this->insertRow('StockMovements', [
            'property_id' => $this->otherId, 'inventory_item_id' => $alien, 'receptionist_id' => $this->deskId,
            'direction' => 'out', 'quantity' => 1, 'created' => $this->at(15),
        ]);

        // Sales recorded live (now, frozen): their stock lines tie with them.
        $this->menuId = $this->insertRow('FoodMenuItems', [
            'property_id' => $this->propertyId, 'name' => 'Towel set', 'price' => 99, 'type' => 'linen',
            'inventory_item_id' => $towel, 'is_available' => true,
        ]);
        foreach ([1, 2] as $qty) {
            $this->callAs($this->deskToken, 'POST', '/api/food-orders', [
                'items' => [['food_menu_item_id' => $this->menuId, 'quantity' => $qty]],
                'payment_status' => 'paid', 'payment_method' => 'cash',
            ]);
            $this->assertResponseCode(201);
        }
        // History at the same frozen instant, inserted after the live sales:
        // a newer order id whose placed event is backfilled later.
        $this->insertRow('FoodOrders', [
            'property_id' => $this->propertyId, 'receptionist_id' => $this->adminId, 'status' => 'open',
            'payment_status' => 'unpaid', 'total' => 5, 'total_diners' => 1,
            'created' => DateTime::now(), 'modified' => DateTime::now(),
        ]);

        // Later changes the feed shows as they are now.
        $this->getTableLocator()->get('InventoryItems')->updateAll(['name' => 'Bath towel'], ['id' => $towel]);
        $this->getTableLocator()->get('FoodOrders')->updateAll(
            ['status' => 'cancelled'],
            ['property_id' => $this->propertyId, 'total' => 30.75],
        );

        /** @var \Cake\Database\Connection $connection */
        $connection = $this->getTableLocator()->get('Properties')->getConnection();
        (new ActivityBackfill($connection))->run();
    }

    /**
     * The feed as the two-table merge built it before part 3.
     *
     * @return list<array<string, mixed>>
     */
    private function referenceFeed(int $limit): array
    {
        $events = [];
        $movements = $this->getTableLocator()->get('StockMovements')->find()
            ->contain(['InventoryItems' => ['fields' => ['id', 'name', 'unit']], 'Receptionist' => ['fields' => ['id', 'name']]])
            ->where(['StockMovements.property_id' => $this->propertyId])
            ->orderBy(['StockMovements.created' => 'DESC', 'StockMovements.id' => 'DESC'])
            ->limit($limit)->all();
        foreach ($movements as $m) {
            $events[] = [
                'type' => 'stock', 'id' => 'stock-' . $m->id, 'at' => $m->created, 'actor' => $m->receptionist?->name,
                'direction' => $m->direction, 'quantity' => (float)$m->quantity, 'item' => $m->inventory_item?->name,
                'unit' => $m->inventory_item?->unit, 'reason' => $m->reason,
            ];
        }
        $orders = $this->getTableLocator()->get('FoodOrders')->find()
            ->contain(['Rooms' => ['fields' => ['id', 'room_number']], 'Receptionist' => ['fields' => ['id', 'name']]])
            ->where(['FoodOrders.property_id' => $this->propertyId])
            ->orderBy(['FoodOrders.created' => 'DESC', 'FoodOrders.id' => 'DESC'])
            ->limit($limit)->all();
        foreach ($orders as $o) {
            $events[] = [
                'type' => 'order', 'id' => 'order-' . $o->id, 'at' => $o->created, 'actor' => $o->receptionist?->name,
                'order_id' => (int)$o->id, 'total' => round((float)$o->total, 2), 'room' => $o->room?->room_number,
                'payment_status' => $o->payment_status, 'status' => $o->status,
            ];
        }
        usort($events, fn($a, $b) => (string)$b['at']?->format('c') <=> (string)$a['at']?->format('c'));

        return json_decode((string)json_encode(array_slice($events, 0, $limit)), true);
    }

    public function testEveryPageMatchesTheEarlierFeed(): void
    {
        $this->arrange();
        $reference = $this->referenceFeed(1000);
        $this->assertGreaterThan(50, count($reference), 'enough lines for three pages');

        $all = [];
        for ($page = 1; $page <= 3; $page++) {
            $this->callAs($this->adminToken, 'GET', "/api/operations/activity?page=$page");
            $this->assertResponseOk();
            $body = $this->responseJson();
            $this->assertSame(
                array_slice($reference, ($page - 1) * 25, 25),
                $body['activity'],
                "page $page",
            );
            $this->assertSame(count($reference) > $page * 25, $body['has_more'], "has_more on page $page");
            $all = array_merge($all, $body['activity']);
        }
        $this->assertSame($reference, $all, 'nothing missing, nothing twice');
    }

    public function testTheOperationsCardMatchesToo(): void
    {
        $this->arrange();
        $this->callAs($this->adminToken, 'GET', '/api/operations/today');
        $this->assertResponseOk();

        $this->assertSame($this->referenceFeed(8), $this->responseJson()['operations']['activity']);
    }
}
