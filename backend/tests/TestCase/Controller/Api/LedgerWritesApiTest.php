<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Stock and POS write through the event foundation (build step 5, part 2):
 * every movement and sale event carries who did it, their role, the reason
 * where one applies, and the request's X-Request-Id as its correlation id, in
 * the same transaction as the change, with one activity_index row each.
 */
class LedgerWritesApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private string $adminToken;
    private string $deskToken;
    private int $adminId;
    private int $deskId;
    private int $categoryId;
    private int $riceId;
    private int $eggId;
    private int $menuId;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Ledger Writes Resort');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "lw-admin-$tag@example.test");
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', "lw-desk-$tag@example.test");
        $this->adminId = $this->userIdFor($this->adminToken);
        $this->deskId = $this->userIdFor($this->deskToken);

        $this->categoryId = $this->insertRow('InventoryCategories', [
            'property_id' => $this->propertyId, 'name' => 'Pantry', 'kind' => 'food_stock',
        ]);
        $item = fn(string $name, float $qty) => $this->insertRow('InventoryItems', [
            'property_id' => $this->propertyId, 'inventory_category_id' => $this->categoryId,
            'name' => $name, 'unit' => 'pc', 'quantity' => $qty, 'tracking_type' => 'consumable',
        ]);
        $this->riceId = $item('Rice pack', 10);
        $this->eggId = $item('Egg', 20);
        $this->menuId = $this->insertRow('FoodMenuItems', [
            'property_id' => $this->propertyId, 'name' => 'Silog', 'price' => 150, 'type' => 'food',
            'inventory_item_id' => $this->riceId, 'is_available' => true,
        ]);
        $this->insertRow('FoodMenuItemIngredients', [
            'food_menu_item_id' => $this->menuId, 'inventory_item_id' => $this->eggId, 'quantity' => 2,
        ]);
    }

    protected function tearDown(): void
    {
        $connection = $this->getTableLocator()->get('Properties')->getConnection();
        $connection->delete('food_menu_item_ingredients', ['food_menu_item_id' => $this->menuId]);
        $this->cleanupScenario();
        parent::tearDown();
    }

    private function requestId(): string
    {
        return $this->_response->getHeaderLine('X-Request-Id');
    }

    private function placeSale(int $quantity = 1): array
    {
        $this->callAs($this->deskToken, 'POST', '/api/food-orders', [
            'items' => [['food_menu_item_id' => $this->menuId, 'quantity' => $quantity]],
            'payment_status' => 'paid', 'payment_method' => 'cash', 'total_diners' => 1,
        ]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());

        return [(int)$this->responseJson()['order']['id'], $this->requestId()];
    }

    /**
     * @return list<\Cake\Datasource\EntityInterface>
     */
    private function action(string $correlationId): array
    {
        /** @var \App\Model\Table\ActivityIndexTable $index */
        $index = $this->getTableLocator()->get('ActivityIndex');

        return $index->forCorrelation($this->propertyId, $correlationId);
    }

    private function quantity(int $itemId): float
    {
        return (float)$this->getTableLocator()->get('InventoryItems')->get($itemId)->quantity;
    }

    public function testASaleIsOneTraceableAction(): void
    {
        [$orderId, $requestId] = $this->placeSale(2);

        $events = $this->getTableLocator()->get('FoodOrderEvents')->find()
            ->where(['food_order_id' => $orderId])->all()->toList();
        $this->assertCount(1, $events);
        $placed = $events[0];
        $this->assertSame('placed', $placed->event_type);
        $this->assertSame($this->deskId, (int)$placed->actor_id);
        $this->assertSame('receptionist', $placed->actor_role);
        $this->assertSame('web', $placed->source);
        $this->assertSame($requestId, $placed->correlation_id, 'the event carries the request\'s X-Request-Id');
        $this->assertSame(300.0, (float)$placed->amount);

        $movements = $this->getTableLocator()->get('StockMovements')->find()
            ->where(['reference_type' => 'food_order', 'reference_id' => $orderId])
            ->orderBy(['id' => 'ASC'])->all()->toList();
        $this->assertCount(2, $movements, 'the linked item and the recipe ingredient');
        foreach ($movements as $movement) {
            $this->assertSame($requestId, $movement->correlation_id);
            $this->assertSame($this->deskId, (int)$movement->receptionist_id);
            $this->assertSame('receptionist', $movement->actor_role);
            $this->assertSame('web', $movement->source);
            $this->assertSame('food_order', $movement->reason, 'the movement keeps its own reason');
            $this->assertNotNull($movement->occurred_at);
        }
        $this->assertSame(8.0, $this->quantity($this->riceId));
        $this->assertSame(16.0, $this->quantity($this->eggId));

        $action = $this->action($requestId);
        $this->assertSame(
            ['moved_out', 'moved_out', 'placed'],
            array_map(fn($row) => $row->event_type, $action),
            'the whole sale, every ledger, in order, nothing twice',
        );
        $this->assertSame(['stock_movements', 'stock_movements', 'food_order_events'], array_map(fn($row) => $row->event_table, $action));
    }

    public function testEachRequestIsItsOwnAction(): void
    {
        [$orderId, $placeId] = $this->placeSale();
        $this->callAs($this->deskToken, 'POST', "/api/food-orders/$orderId/serve");
        $this->assertResponseOk();
        $serveId = $this->requestId();

        $this->assertNotSame($placeId, $serveId);
        $this->assertSame(['served'], array_map(fn($row) => $row->event_type, $this->action($serveId)));
        $served = $this->getTableLocator()->get('FoodOrderEvents')->find()
            ->where(['food_order_id' => $orderId, 'event_type' => 'served'])->firstOrFail();
        $this->assertSame(['open', 'served'], $served->changes['status']);
    }

    public function testServingTwiceRecordsOnce(): void
    {
        [$orderId] = $this->placeSale();
        $this->callAs($this->deskToken, 'POST', "/api/food-orders/$orderId/serve");
        $this->assertResponseOk();
        $this->callAs($this->deskToken, 'POST', "/api/food-orders/$orderId/serve");
        $this->assertResponseCode(400);

        $this->assertSame(1, $this->getTableLocator()->get('FoodOrderEvents')->find()
            ->where(['food_order_id' => $orderId, 'event_type' => 'served'])->count());
    }

    public function testCancellingAPaidSaleRecordsWhoWhyAndRestocks(): void
    {
        [$orderId] = $this->placeSale();
        $this->callAs($this->deskToken, 'POST', "/api/food-orders/$orderId/serve");
        $this->callAs($this->adminToken, 'POST', "/api/food-orders/$orderId/cancel", ['reason' => 'Charged the wrong table']);
        $this->assertResponseOk();
        $cancelId = $this->requestId();

        $cancel = $this->getTableLocator()->get('FoodOrderEvents')->find()
            ->where(['food_order_id' => $orderId, 'event_type' => 'cancelled_after_payment'])->firstOrFail();
        $this->assertSame($this->adminId, (int)$cancel->actor_id);
        $this->assertSame('admin', $cancel->actor_role);
        $this->assertSame('Charged the wrong table', $cancel->reason);
        $this->assertSame(['served', 'cancelled'], $cancel->changes['status']);

        $this->assertSame(
            ['moved_in', 'moved_in', 'cancelled_after_payment'],
            array_map(fn($row) => $row->event_type, $this->action($cancelId)),
        );
        $this->assertSame(10.0, $this->quantity($this->riceId));
        $this->assertSame(20.0, $this->quantity($this->eggId));
    }

    /**
     * G8 closed the grace window: a paid, served sale is cancelled only with
     * a reason, and a refusal changes nothing (no event, no restock).
     */
    public function testAPaidSaleIsNotCancelledWithoutAReason(): void
    {
        [$orderId] = $this->placeSale();
        $this->callAs($this->deskToken, 'POST', "/api/food-orders/$orderId/serve");
        $this->callAs($this->adminToken, 'POST', "/api/food-orders/$orderId/cancel");
        $this->assertResponseCode(400);
        $this->assertResponseContains('A reason is required');

        $this->assertSame('served', $this->getTableLocator()->get('FoodOrders')->get($orderId)->status);
        $this->assertSame(['placed', 'served'], $this->getTableLocator()->get('FoodOrderEvents')->find()
            ->where(['food_order_id' => $orderId])->orderBy(['id' => 'ASC'])->all()->extract('event_type')->toList());
        $this->assertSame(9.0, $this->quantity($this->riceId), 'nothing restocked');
    }

    public function testAnOrdinaryCancelIsCancelledAndHappensOnce(): void
    {
        [$orderId] = $this->placeSale();
        $this->callAs($this->deskToken, 'POST', "/api/food-orders/$orderId/cancel");
        $this->assertResponseOk();
        $this->callAs($this->deskToken, 'POST', "/api/food-orders/$orderId/cancel");
        $this->assertResponseCode(400);

        $types = $this->getTableLocator()->get('FoodOrderEvents')->find()
            ->where(['food_order_id' => $orderId])->orderBy(['id' => 'ASC'])->all()->extract('event_type')->toList();
        $this->assertSame(['placed', 'cancelled'], $types);
        $this->assertSame(10.0, $this->quantity($this->riceId), 'restocked once, not twice');
    }

    public function testAFailedSaleLeavesNoTrace(): void
    {
        // 3 servings: the rice (3 of 10) is taken first, then 6 eggs are
        // needed with 5 on hand, so the sale fails part-way through.
        $this->getTableLocator()->get('InventoryItems')->updateAll(['quantity' => 5], ['id' => $this->eggId]);
        $orders = $this->getTableLocator()->get('FoodOrders')->find()->where(['property_id' => $this->propertyId])->count();
        $this->callAs($this->deskToken, 'POST', '/api/food-orders', [
            'items' => [['food_menu_item_id' => $this->menuId, 'quantity' => 3]],
            'payment_status' => 'paid', 'payment_method' => 'cash',
        ]);
        $this->assertResponseCode(400);

        $this->assertSame($orders, $this->getTableLocator()->get('FoodOrders')->find()->where(['property_id' => $this->propertyId])->count());
        $this->assertSame([], $this->action($this->requestId()));
        $this->assertSame(10.0, $this->quantity($this->riceId), 'the rice taken before the eggs ran out is back');
        $this->assertSame(5.0, $this->quantity($this->eggId));
        $this->assertSame(0, $this->getTableLocator()->get('StockMovements')->find()->where(['property_id' => $this->propertyId])->count());
    }

    public function testAManualMoveRecordsTheManagerAndTheirReason(): void
    {
        $this->callAs($this->adminToken, 'POST', '/api/stock-movements', [
            'inventory_item_id' => $this->riceId, 'direction' => 'in', 'quantity' => 5, 'reason' => 'restock',
        ]);
        $this->assertResponseCode(201);
        $requestId = $this->requestId();

        $movement = $this->getTableLocator()->get('StockMovements')->get((int)$this->responseJson()['movement']['id']);
        $this->assertSame($this->adminId, (int)$movement->receptionist_id);
        $this->assertSame('admin', $movement->actor_role);
        $this->assertSame('restock', $movement->reason);
        $this->assertSame($requestId, $movement->correlation_id);
        $this->assertEquals(['item' => 'Rice pack', 'unit' => 'pc', 'quantity_after' => 15], $movement->snapshot);
        $this->assertSame(15.0, (float)$this->responseJson()['item']['quantity'], 'the response shows the new quantity');

        $index = $this->action($requestId);
        $this->assertCount(1, $index);
        $this->assertSame('moved_in', $index[0]->event_type);
        $this->assertSame('inventory_item', $index[0]->subject_type);
        $this->assertSame($this->riceId, (int)$index[0]->subject_id);
    }

    public function testANewItemAndItsOpeningStockAreOneAction(): void
    {
        $this->callAs($this->adminToken, 'POST', '/api/inventory-items', [
            'inventory_category_id' => $this->categoryId, 'name' => 'Coffee sachet', 'unit' => 'pc', 'quantity' => 40,
        ]);
        $this->assertResponseOk();
        $itemId = (int)$this->responseJson()['item']['id'];

        $this->assertSame(40.0, $this->quantity($itemId));
        // One request, two facts (inventory follow-up, 2026-10-06): the
        // item's creation (config_changes) and its opening stock movement.
        $action = $this->action($this->requestId());
        $this->assertSame(['config_changes', 'stock_movements'], array_map(fn($a) => $a->event_table, $action));
        foreach ($action as $line) {
            $this->assertSame($itemId, (int)$line->subject_id);
        }
        $movement = $this->getTableLocator()->get('StockMovements')->get((int)$action[1]->event_id);
        $this->assertSame('opening_balance', $movement->reason);
    }

    public function testTheFeedShowsEachSaleAndMovementOnce(): void
    {
        $this->placeSale();
        $this->callAs($this->adminToken, 'GET', '/api/operations/activity');
        $this->assertResponseOk();

        $types = array_count_values(array_map(fn($item) => $item['type'], $this->responseJson()['activity']));
        $this->assertEquals(['stock' => 2, 'order' => 1], $types);
    }
}
