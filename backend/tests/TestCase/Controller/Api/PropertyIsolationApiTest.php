<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Model\BusinessTime;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * One hotel can never see or change another hotel's data.
 *
 * Two properties: "ours" (A), whose Manager and Front Desk Staff make every
 * request, and "theirs" (B), which holds one of everything. Each test checks
 * that A's staff get nothing of B's back and that B's rows are unchanged
 * afterwards. A leak here is the most serious bug a multi-hotel product can
 * have, so these assert the exact refusal where the code defines one.
 */
class PropertyIsolationApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $ours;
    private int $theirs;
    private string $adminToken;
    private string $receptionistToken;
    private string $ownerToken;

    /**
     * @var array<string, int> B's rows by kind.
     */
    private array $b = [];

    private const B_ROOM = 'THEIRS-101';
    private const B_GUEST = 'Bea Theirs-Only';
    private const B_ITEM = 'Theirs-Only Stock';
    private const B_CATEGORY = 'Theirs-Only Category';

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->ours = $this->createProperty('Our Resort');
        $this->theirs = $this->createProperty('Their Resort');

        $this->adminToken = $this->makeUser($this->ours, 'admin', "iso-admin-$tag@example.test");
        $this->receptionistToken = $this->makeUser($this->ours, 'receptionist', "iso-desk-$tag@example.test");
        $this->ownerToken = $this->makeUser(null, 'owner', "iso-owner-$tag@example.test");
        $theirAdmin = $this->makeUser($this->theirs, 'admin', "iso-their-admin-$tag@example.test");
        $theirDesk = $this->makeUser($this->theirs, 'receptionist', "iso-their-desk-$tag@example.test");
        $this->b['admin'] = $this->userIdFor($theirAdmin);
        $this->b['receptionist'] = $this->userIdFor($theirDesk);

        $p = $this->theirs;
        $this->b['room'] = $this->insertRow('Rooms', [
            'property_id' => $p, 'room_number' => self::B_ROOM, 'room_type' => 'Deluxe', 'status' => 'available',
        ]);
        $this->b['guest'] = $this->insertRow('Guests', [
            'property_id' => $p, 'full_name' => self::B_GUEST, 'guest_type' => 'local',
        ]);
        $this->b['reservation'] = $this->insertRow('Reservations', [
            'property_id' => $p, 'room_id' => $this->b['room'], 'guest_id' => $this->b['guest'],
            'receptionist_id' => $this->b['admin'], 'status' => 'booked', 'source' => 'walk_in',
            'payment_status' => 'unpaid', 'total_guests' => 1,
            'check_in' => BusinessTime::today()->addDays(5)->format('Y-m-d'),
            'check_out' => BusinessTime::today()->addDays(7)->format('Y-m-d'),
        ]);
        $this->b['category'] = $this->insertRow('InventoryCategories', [
            'property_id' => $p, 'name' => self::B_CATEGORY, 'kind' => 'food_stock',
        ]);
        $this->b['item'] = $this->insertRow('InventoryItems', [
            'property_id' => $p, 'inventory_category_id' => $this->b['category'], 'name' => self::B_ITEM,
            'tracking_type' => 'consumable', 'unit' => 'pcs', 'quantity' => 10, 'reorder_level' => 0,
        ]);
        $this->b['invoice'] = $this->insertRow('Invoices', [
            'property_id' => $p, 'guest_id' => $this->b['guest'], 'total' => 500, 'status' => 'open',
        ]);
        $this->b['order'] = $this->insertRow('FoodOrders', [
            'property_id' => $p, 'receptionist_id' => $this->b['admin'], 'status' => 'open',
            'payment_status' => 'paid', 'payment_method' => 'cash', 'total' => 100, 'total_diners' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        // Option groups hang off menu items, which cleanupScenario() removes by property.
        $locator = $this->getTableLocator();
        $menuIds = $locator->get('FoodMenuItems')->find()->select(['id'])
            ->where(['property_id IN' => [$this->ours, $this->theirs]])
            ->all()->extract('id')->toList();
        if ($menuIds) {
            $groupIds = $locator->get('FoodMenuItemOptionGroups')->find()->select(['id'])
                ->where(['food_menu_item_id IN' => $menuIds])->all()->extract('id')->toList();
            if ($groupIds) {
                $locator->get('FoodMenuItemOptions')->deleteAll(['food_menu_item_option_group_id IN' => $groupIds]);
                $locator->get('FoodMenuItemOptionGroups')->deleteAll(['id IN' => $groupIds]);
            }
        }
        $this->cleanupScenario();
        parent::tearDown();
    }

    /**
     * Every `property_id` anywhere in the JSON response.
     *
     * @return list<int>
     */
    private function propertyIdsInResponse(): array
    {
        $found = [];
        $walk = function ($node) use (&$walk, &$found): void {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                if ($key === 'property_id' && $value !== null) {
                    $found[] = (int)$value;
                }
                $walk($value);
            }
        };
        $walk($this->responseJson());

        return $found;
    }

    private function assertNothingOfTheirs(string $url): void
    {
        $body = (string)$this->_response->getBody();
        $this->assertNotContains($this->theirs, $this->propertyIdsInResponse(), "$url returned another property's rows");
        foreach ([self::B_ROOM, self::B_GUEST, self::B_ITEM] as $marker) {
            $this->assertStringNotContainsString($marker, $body, "$url leaked \"$marker\"");
        }
    }

    private function row(string $table, int $id): EntityInterface
    {
        return $this->getTableLocator()->get($table)->get($id);
    }

    // ------------------------------------------------------------ reading

    public function testListsOnlyContainOurProperty(): void
    {
        $urls = [
            '/api/rooms', '/api/guests', '/api/reservations', '/api/inventory-items', '/api/invoices',
            '/api/food-orders', '/api/users', '/api/stock-movements',
        ];
        foreach ([$this->adminToken, $this->receptionistToken] as $token) {
            foreach ($urls as $url) {
                if ($url === '/api/users' && $token === $this->receptionistToken) {
                    continue; // Staff lists are manager-only (covered in AccessControlApiTest).
                }
                $this->callAs($token, 'GET', $url);
                $this->assertResponseOk("GET $url");
                $this->assertNothingOfTheirs($url);
            }
            // Guest search and matching send the guest's name in the body (G5, P6).
            foreach (['/api/guests/search' => ['q' => self::B_GUEST], '/api/guests/match' => ['full_name' => self::B_GUEST]] as $url => $body) {
                $this->callAs($token, 'POST', $url, $body);
                $this->assertResponseOk("POST $url");
                $this->assertNothingOfTheirs($url);
            }
        }
    }

    public function testAPropertyIdParameterCannotWidenStaffScope(): void
    {
        foreach (['/api/rooms', '/api/guests', '/api/invoices', '/api/reservations'] as $url) {
            $this->callAs($this->adminToken, 'GET', $url . '?property_id=' . $this->theirs);
            $this->assertResponseOk("GET $url?property_id=theirs");
            $this->assertNothingOfTheirs("$url?property_id=theirs");
        }
    }

    public function testReportsOnlyCoverOurProperty(): void
    {
        foreach (['/api/operations/today'] as $url) {
            $this->callAs($this->adminToken, 'GET', $url . '?property_id=' . $this->theirs);
            $this->assertResponseOk();
            $this->assertNothingOfTheirs($url);
            $this->assertSame(0, $this->responseJson()['operations']['rooms']['total']);
        }

        foreach (['/api/finance/collections'] as $url) {
            $this->callAs($this->adminToken, 'GET', $url . '?property_id=' . $this->theirs);
            $this->assertResponseOk();
            $this->assertEquals(0, $this->responseJson()['collection']['outstanding']['total'], $url);
        }

        $this->callAs($this->adminToken, 'GET', '/api/finance/summary?property_id=' . $this->theirs);
        $this->assertResponseOk();
        $this->assertEquals(0, $this->responseJson()['summary']['outstanding']['total']);
    }

    public function testCannotOpenAnotherPropertysRecords(): void
    {
        $urls = [
            '/api/guests/' . $this->b['guest'],
            '/api/inventory-items/' . $this->b['item'],
            '/api/food-orders/' . $this->b['order'],
            '/api/invoices/' . $this->b['invoice'],
            '/api/reservations/' . $this->b['reservation'] . '/history',
            '/api/reservations/' . $this->b['reservation'] . '/price',
        ];
        foreach ($urls as $url) {
            $this->callAs($this->adminToken, 'GET', $url);
            $this->assertResponseCode(404, "GET $url must not find another property's record");
            $this->assertNothingOfTheirs($url);
        }
    }

    // ------------------------------------------------------------ changing

    public function testCannotChangeAnotherPropertysRecords(): void
    {
        $b = $this->b;
        $attempts = [
            ['PATCH', '/api/rooms/' . $b['room'], ['status' => 'maintenance']],
            ['DELETE', '/api/rooms/' . $b['room'], []],
            ['PATCH', '/api/guests/' . $b['guest'], ['full_name' => 'Renamed']],
            ['PATCH', '/api/reservations/' . $b['reservation'], ['total_guests' => 2]],
            ['POST', '/api/reservations/' . $b['reservation'] . '/cancel', []],
            ['POST', '/api/reservations/' . $b['reservation'] . '/post-room-charge', []],
            ['DELETE', '/api/reservations/' . $b['reservation'], ['reason' => 'Not ours']],
            ['PATCH', '/api/inventory-items/' . $b['item'], ['name' => 'Renamed']],
            ['DELETE', '/api/inventory-items/' . $b['item'], []],
            ['POST', '/api/invoices/' . $b['invoice'] . '/settle', []],
            ['POST', '/api/food-orders/' . $b['order'] . '/cancel', []],
            ['POST', '/api/food-orders/' . $b['order'] . '/serve', []],
            ['POST', '/api/invoices/' . $b['invoice'] . '/refund', ['amount' => 1, 'method' => 'cash', 'reason' => 'Not ours']],
            ['POST', '/api/food-orders/' . $b['order'] . '/refund', ['method' => 'cash', 'reason' => 'Not ours']],
            ['PATCH', '/api/users/' . $b['admin'], ['is_active' => false]],
            ['POST', '/api/users/' . $b['receptionist'] . '/reset-password', ['password' => 'hijacked1']],
            // Step 10: another hotel's staff sign-ins.
            ['GET', '/api/users/' . $b['receptionist'] . '/access-history', []],
        ];
        foreach ($attempts as [$method, $url, $body]) {
            $this->callAs($this->adminToken, $method, $url, $body);
            $this->assertResponseCode(404, "$method $url must not reach another property's record");
        }

        $this->assertSame('available', $this->row('Rooms', $b['room'])->status);
        $this->assertSame(self::B_GUEST, $this->row('Guests', $b['guest'])->full_name);
        $reservation = $this->row('Reservations', $b['reservation']);
        $this->assertSame('booked', $reservation->status);
        $this->assertSame('unpaid', $reservation->payment_status);
        $this->assertSame(1, (int)$reservation->total_guests);
        $item = $this->row('InventoryItems', $b['item']);
        $this->assertSame(self::B_ITEM, $item->name);
        $this->assertNull($item->deleted_at);
        $this->assertSame('open', $this->row('Invoices', $b['invoice'])->status);
        $this->assertSame('open', $this->row('FoodOrders', $b['order'])->status);
        $this->assertTrue((bool)$this->row('Users', $b['admin'])->is_active);
        $this->assertNotNull($this->row('Users', $b['receptionist'])->api_token, 'their token must survive');
    }

    public function testCannotMoveAnotherPropertysStock(): void
    {
        $this->callAs($this->adminToken, 'POST', '/api/stock-movements', [
            'inventory_item_id' => $this->b['item'], 'direction' => 'out', 'quantity' => 5,
        ]);
        $this->assertResponseCode(404);
        $this->assertEquals(10, (float)$this->row('InventoryItems', $this->b['item'])->quantity);
        $this->assertSame(0, $this->getTableLocator()->get('StockMovements')
            ->find()->where(['inventory_item_id' => $this->b['item']])->count());
    }

    public function testCannotBookAnotherPropertysRoomOrGuest(): void
    {
        $this->callAs($this->adminToken, 'POST', '/api/reservations', [
            'room_id' => $this->b['room'],
            'guest_id' => $this->b['guest'],
            'source' => 'walk_in',
            'check_out' => BusinessTime::today()->addDays(1)->format('Y-m-d'),
        ]);
        $this->assertContains($this->_response->getStatusCode(), [400, 404, 422], (string)$this->_response->getBody());
        $this->assertSame(0, $this->getTableLocator()->get('Reservations')->find()
            ->where(['property_id' => $this->ours])->count());
        $this->assertSame('available', $this->row('Rooms', $this->b['room'])->status);
    }

    public function testAnOrderCannotReferenceAnotherPropertysGuestRoomOrStay(): void
    {
        $this->callAs($this->adminToken, 'POST', '/api/food-orders', [
            'items' => [['description' => 'Bottled water', 'price' => 20, 'quantity' => 1]],
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'guest_id' => $this->b['guest'],
            'room_id' => $this->b['room'],
            'reservation_id' => $this->b['reservation'],
        ]);
        $this->assertContains($this->_response->getStatusCode(), [400, 404, 422], (string)$this->_response->getBody());
        $leaked = $this->getTableLocator()->get('FoodOrders')->find()
            ->where(['property_id' => $this->ours])
            ->where(['OR' => [
                'guest_id' => $this->b['guest'],
                'room_id' => $this->b['room'],
                'reservation_id' => $this->b['reservation'],
            ]])
            ->count();
        $this->assertSame(0, $leaked, 'an order at our property must not point at their guest, room or stay');
    }

    /**
     * SF-2026-005: a menu item's linked stock item came from the request with
     * no property check, so our Manager could link their stock to our menu.
     */
    public function testAMenuItemCannotLinkAnotherPropertysStock(): void
    {
        $this->callAs($this->adminToken, 'POST', '/api/food-menu-items', [
            'name' => 'Linked Water', 'price' => 20, 'type' => 'food', 'inventory_item_id' => $this->b['item'],
        ]);
        $this->assertResponseCode(400);
        $this->assertNothingOfTheirs('POST /api/food-menu-items');

        $ownId = $this->insertRow('FoodMenuItems', [
            'property_id' => $this->ours, 'name' => 'Our Water', 'price' => 20, 'type' => 'food', 'is_available' => true,
        ]);
        $this->callAs($this->adminToken, 'PATCH', "/api/food-menu-items/$ownId", [
            'name' => 'Our Water', 'price' => 20, 'inventory_item_id' => $this->b['item'],
        ]);
        $this->assertResponseCode(400);
        $this->assertNothingOfTheirs("PATCH /api/food-menu-items/$ownId");

        $linked = $this->getTableLocator()->get('FoodMenuItems')->find()
            ->where(['property_id' => $this->ours, 'inventory_item_id' => $this->b['item']])
            ->count();
        $this->assertSame(0, $linked, 'no menu item of ours may point at their stock');
    }

    /**
     * SF-2026-005: a link saved before the fix must neither show their stock
     * on our menu nor let our sales move it.
     */
    public function testALinkToAnotherPropertysStockNeitherShowsNorMovesIt(): void
    {
        $menuId = $this->insertRow('FoodMenuItems', [
            'property_id' => $this->ours, 'name' => 'Old Link', 'price' => 20, 'type' => 'food',
            'is_available' => true, 'inventory_item_id' => $this->b['item'],
        ]);

        $this->callAs($this->adminToken, 'GET', '/api/food-menu-items');
        $this->assertResponseOk();
        $this->assertNothingOfTheirs('GET /api/food-menu-items');

        $this->callAs($this->receptionistToken, 'POST', '/api/food-orders', [
            'items' => [['food_menu_item_id' => $menuId, 'quantity' => 2]],
            'payment_status' => 'paid',
            'payment_method' => 'cash',
        ]);
        $this->assertResponseCode(400);
        $this->assertSame(10.0, (float)$this->row('InventoryItems', $this->b['item'])->quantity, 'their stock is untouched');
        $this->assertSame(0, $this->getTableLocator()->get('StockMovements')->find()
            ->where(['inventory_item_id' => $this->b['item']])->count(), 'and their ledger has no movement of ours');
        $this->assertSame(0, $this->getTableLocator()->get('FoodOrders')->find()
            ->where(['property_id' => $this->ours])->count(), 'the refused sale left no order');
    }

    /**
     * SF-2026-006: a room rate's or promo rate's room came from the request
     * with no property check, so our Manager could point a rate at their room.
     */
    public function testARateCannotTargetAnotherPropertysRoom(): void
    {
        $theirRoom = $this->b['room'];

        $this->callAs($this->adminToken, 'POST', '/api/room-rates', ['base_rate' => 1500, 'room_id' => $theirRoom]);
        $this->assertResponseCode(400);
        $this->assertNothingOfTheirs('POST /api/room-rates');

        $ownRate = $this->insertRow('RoomRates', ['property_id' => $this->ours, 'base_rate' => 1500]);
        $this->callAs($this->adminToken, 'PATCH', "/api/room-rates/$ownRate", ['base_rate' => 1500, 'room_id' => $theirRoom]);
        $this->assertResponseCode(400);
        $this->assertNothingOfTheirs("PATCH /api/room-rates/$ownRate");

        $this->callAs($this->adminToken, 'POST', '/api/promo-rates', [
            'source_name' => 'Agoda', 'multiplier' => 1.2, 'room_id' => $theirRoom,
        ]);
        $this->assertResponseCode(400);
        $this->assertNothingOfTheirs('POST /api/promo-rates');

        $ownPromo = $this->insertRow('PromoRates', ['property_id' => $this->ours, 'source' => 'agoda', 'multiplier' => 1.2]);
        $this->callAs($this->adminToken, 'PATCH', "/api/promo-rates/$ownPromo", [
            'source_name' => 'Agoda', 'multiplier' => 1.2, 'room_id' => $theirRoom,
        ]);
        $this->assertResponseCode(400);
        $this->assertNothingOfTheirs("PATCH /api/promo-rates/$ownPromo");

        foreach (['RoomRates', 'PromoRates'] as $table) {
            $pointing = $this->getTableLocator()->get($table)->find()
                ->where(['property_id' => $this->ours, 'room_id' => $theirRoom])
                ->count();
            $this->assertSame(0, $pointing, "no $table row of ours may point at their room");
        }
    }

    /**
     * SF-2026-006: a category's parent and an item's category came from the
     * request with no property check.
     */
    public function testInventoryCannotFileUnderAnotherPropertysCategory(): void
    {
        $theirCategory = $this->b['category'];

        $this->callAs($this->adminToken, 'POST', '/api/inventory-categories', [
            'name' => 'Our Drinks', 'kind' => 'food_stock', 'parent_id' => $theirCategory,
        ]);
        $this->assertResponseCode(400);
        $this->assertStringNotContainsString(self::B_CATEGORY, (string)$this->_response->getBody());

        $this->callAs($this->adminToken, 'POST', '/api/inventory-items', [
            'name' => 'Our Soap', 'unit' => 'pcs', 'inventory_category_id' => $theirCategory,
        ]);
        $this->assertResponseCode(400);
        $this->assertStringNotContainsString(self::B_CATEGORY, (string)$this->_response->getBody());

        $ownCategory = $this->insertRow('InventoryCategories', ['property_id' => $this->ours, 'name' => 'Ours', 'kind' => 'other']);
        $ownItem = $this->insertRow('InventoryItems', [
            'property_id' => $this->ours, 'inventory_category_id' => $ownCategory, 'name' => 'Our Towel',
            'tracking_type' => 'consumable', 'unit' => 'pcs', 'quantity' => 0, 'reorder_level' => 0,
        ]);
        $this->callAs($this->adminToken, 'PATCH', "/api/inventory-items/$ownItem", [
            'name' => 'Our Towel', 'unit' => 'pcs', 'inventory_category_id' => $theirCategory,
        ]);
        $this->assertResponseCode(400);
        $this->assertSame($ownCategory, (int)$this->row('InventoryItems', $ownItem)->inventory_category_id, 'the item keeps our category');

        $this->assertSame(0, $this->getTableLocator()->get('InventoryCategories')->find()
            ->where(['property_id' => $this->ours, 'parent_id' => $theirCategory])->count(), 'no category of ours under theirs');
        $this->assertSame(0, $this->getTableLocator()->get('InventoryItems')->find()
            ->where(['property_id' => $this->ours, 'inventory_category_id' => $theirCategory])->count(), 'no item of ours in their category');
    }

    /**
     * SF-2026-006: links saved before the check show nothing of theirs: not
     * their room on our rates, not their category's name on our inventory.
     */
    public function testOldLinksToAnotherPropertysRoomsOrCategoriesShowNothingOfTheirs(): void
    {
        $this->insertRow('RoomRates', ['property_id' => $this->ours, 'base_rate' => 1500, 'room_id' => $this->b['room']]);
        $this->insertRow('PromoRates', [
            'property_id' => $this->ours, 'source' => 'agoda', 'multiplier' => 1.2, 'room_id' => $this->b['room'],
        ]);
        $this->insertRow('InventoryCategories', [
            'property_id' => $this->ours, 'name' => 'Old Child', 'kind' => 'other', 'parent_id' => $this->b['category'],
        ]);
        $this->insertRow('InventoryItems', [
            'property_id' => $this->ours, 'inventory_category_id' => $this->b['category'], 'name' => 'Old Soap',
            'tracking_type' => 'consumable', 'unit' => 'pcs', 'quantity' => 0, 'reorder_level' => 0,
        ]);

        foreach (['/api/room-rates', '/api/promo-rates', '/api/inventory-categories', '/api/inventory-items'] as $url) {
            $this->callAs($this->adminToken, 'GET', $url);
            $this->assertResponseOk();
            $this->assertNothingOfTheirs("GET $url");
            $this->assertStringNotContainsString(self::B_CATEGORY, (string)$this->_response->getBody(), "GET $url leaked their category");
        }
    }

    /**
     * SF-2026-006: deleting our category detached every sub-category by
     * parent_id alone, so a category of theirs filed under ours was changed
     * (and recorded) by our Manager, and their items in it blocked our delete.
     */
    public function testDeletingOurCategoryNeverTouchesAnotherPropertysCategories(): void
    {
        $ownCategory = $this->insertRow('InventoryCategories', ['property_id' => $this->ours, 'name' => 'Ours', 'kind' => 'other']);
        $theirChild = $this->insertRow('InventoryCategories', [
            'property_id' => $this->theirs, 'name' => 'Their Child', 'kind' => 'other', 'parent_id' => $ownCategory,
        ]);
        $theirItem = $this->insertRow('InventoryItems', [
            'property_id' => $this->theirs, 'inventory_category_id' => $ownCategory, 'name' => 'Their Soap',
            'tracking_type' => 'consumable', 'unit' => 'pcs', 'quantity' => 0, 'reorder_level' => 0,
        ]);
        $changes = fn() => $this->getTableLocator()->get('ConfigChanges')->find()
            ->where(['property_id' => $this->theirs, 'entity_type' => 'inventory_category', 'entity_id' => $theirChild])
            ->count();
        $before = $changes();

        $this->callAs($this->adminToken, 'DELETE', "/api/inventory-categories/$ownCategory", ['reason' => 'No longer used']);
        $this->assertResponseOk();

        // A deleted category is hidden from finds unless asked for (step 9).
        $ours = $this->getTableLocator()->get('InventoryCategories')
            ->find('all', withDeleted: true)->where(['id' => $ownCategory])->firstOrFail();
        $this->assertNotNull($ours->deleted_at, 'ours is deleted');
        $this->assertSame($ownCategory, (int)$this->row('InventoryCategories', $theirChild)->parent_id, 'their category is untouched');
        $this->assertSame($before, $changes(), 'and nothing of ours is recorded against it');
        $this->assertSame($ownCategory, (int)$this->row('InventoryItems', $theirItem)->inventory_category_id);
    }

    /**
     * SF-2026-001 lesson, code-only until now: a menu item's recipe and its
     * options' stock can't name their stock, on create or edit.
     */
    public function testARecipeOrAnOptionCannotUseAnotherPropertysStock(): void
    {
        $ownId = $this->insertRow('FoodMenuItems', [
            'property_id' => $this->ours, 'name' => 'Our Plate', 'price' => 50, 'type' => 'food', 'is_available' => true,
        ]);
        $bodies = [
            'a recipe' => ['ingredients' => [['inventory_item_id' => $this->b['item'], 'quantity' => 1]]],
            'an option' => ['option_groups' => [[
                'name' => 'Extras', 'kind' => 'addon',
                'options' => [['label' => 'Their stock', 'price_delta' => 10, 'inventory_item_id' => $this->b['item']]],
            ]]],
        ];
        foreach ($bodies as $what => $body) {
            $this->callAs($this->adminToken, 'POST', '/api/food-menu-items', ['name' => 'New Plate', 'price' => 50, 'type' => 'food'] + $body);
            $this->assertResponseCode(400, "POST with $what on their stock");
            $this->assertNothingOfTheirs("POST /api/food-menu-items ($what)");

            $this->callAs($this->adminToken, 'PATCH', "/api/food-menu-items/$ownId", ['name' => 'Our Plate', 'price' => 50] + $body);
            $this->assertResponseCode(400, "PATCH with $what on their stock");
            $this->assertNothingOfTheirs("PATCH /api/food-menu-items/$ownId ($what)");
        }

        $this->assertSame(0, $this->getTableLocator()->get('FoodMenuItemIngredients')->find()
            ->where(['inventory_item_id' => $this->b['item']])->count(), 'no recipe of ours uses their stock');
        $this->assertSame(0, $this->getTableLocator()->get('FoodMenuItemOptions')->find()
            ->where(['inventory_item_id' => $this->b['item']])->count(), 'no option of ours uses their stock');
        $this->assertSame(1, $this->getTableLocator()->get('FoodMenuItems')->find()
            ->where(['property_id' => $this->ours])->count(), 'the refused create saved nothing');
    }

    /**
     * Code-only until now: an order line can't sell their menu item or pick
     * one of their options on ours.
     */
    public function testAnOrderLineCannotUseAnotherPropertysMenuItemOrOption(): void
    {
        $theirMenu = $this->insertRow('FoodMenuItems', [
            'property_id' => $this->theirs, 'name' => 'Their Plate', 'price' => 80, 'type' => 'food', 'is_available' => true,
        ]);
        $theirGroup = $this->insertRow('FoodMenuItemOptionGroups', [
            'food_menu_item_id' => $theirMenu, 'name' => 'Extras', 'kind' => 'addon',
        ]);
        $theirOption = $this->insertRow('FoodMenuItemOptions', [
            'food_menu_item_option_group_id' => $theirGroup, 'label' => 'Their egg', 'price_delta' => 15,
            'inventory_item_id' => $this->b['item'],
        ]);
        $ourMenu = $this->insertRow('FoodMenuItems', [
            'property_id' => $this->ours, 'name' => 'Our Plate', 'price' => 50, 'type' => 'food', 'is_available' => true,
        ]);

        $lines = [
            'their menu item' => ['food_menu_item_id' => $theirMenu, 'quantity' => 1],
            'their option' => [
                'food_menu_item_id' => $ourMenu, 'quantity' => 1,
                'selected_options' => [['option_id' => $theirOption, 'quantity' => 1]],
            ],
        ];
        foreach ($lines as $what => $line) {
            $this->callAs($this->receptionistToken, 'POST', '/api/food-orders', [
                'items' => [$line], 'payment_status' => 'paid', 'payment_method' => 'cash',
            ]);
            $this->assertContains($this->_response->getStatusCode(), [400, 404], "an order line with $what");
            $this->assertNothingOfTheirs("POST /api/food-orders ($what)");
            $this->assertStringNotContainsString('Their', (string)$this->_response->getBody());
        }

        $this->assertSame(0, $this->getTableLocator()->get('FoodOrders')->find()
            ->where(['property_id' => $this->ours])->count(), 'the refused sales left no order');
        $this->assertSame(10.0, (float)$this->row('InventoryItems', $this->b['item'])->quantity, 'their stock is untouched');
        $this->assertSame(0, $this->getTableLocator()->get('StockMovements')->find()
            ->where(['inventory_item_id' => $this->b['item']])->count());
    }

    /**
     * Code-only until now: editing our booking can't move it into their room
     * (the reservation's build rule), and a booking can't pick their extra
     * charges. Their room is in service, so no service check speaks first.
     */
    public function testABookingCannotMoveIntoAnotherPropertysRoomOrPickTheirCharges(): void
    {
        $ourRoom = $this->insertRow('Rooms', [
            'property_id' => $this->ours, 'room_number' => 'OURS-201', 'room_type' => 'Deluxe', 'status' => 'available',
        ]);
        $ourStay = $this->insertRow('Reservations', [
            'property_id' => $this->ours, 'room_id' => $ourRoom, 'receptionist_id' => $this->userIdFor($this->adminToken),
            'status' => 'booked', 'source' => 'walk_in', 'payment_status' => 'unpaid', 'total_guests' => 1,
            'check_in' => BusinessTime::today()->addDays(10)->format('Y-m-d'),
            'check_out' => BusinessTime::today()->addDays(12)->format('Y-m-d'),
        ]);
        $theirCharge = $this->insertRow('ExtraCharges', [
            'property_id' => $this->theirs, 'name' => 'Their Cot', 'amount' => 300, 'is_active' => true,
        ]);

        $this->callAs($this->adminToken, 'PATCH', "/api/reservations/$ourStay", ['room_id' => $this->b['room']]);
        $this->assertContains($this->_response->getStatusCode(), [400, 404, 422], (string)$this->_response->getBody());
        $this->assertNothingOfTheirs("PATCH /api/reservations/$ourStay");
        $this->assertSame($ourRoom, (int)$this->row('Reservations', $ourStay)->room_id, 'our booking keeps our room');

        $this->callAs($this->adminToken, 'POST', '/api/reservations', [
            'room_id' => $ourRoom,
            'source' => 'walk_in',
            'check_out' => BusinessTime::today()->addDays(1)->format('Y-m-d'),
            'extra_charges' => [['extra_charge_id' => $theirCharge, 'quantity' => 1]],
        ]);
        $this->assertResponseCode(400);
        $this->assertStringNotContainsString('Their Cot', (string)$this->_response->getBody());
        $this->assertSame(0, $this->getTableLocator()->get('ReservationExtraCharges')->find()
            ->where(['extra_charge_id' => $theirCharge])->count(), 'no booking carries their charge');
        $this->assertSame(1, $this->getTableLocator()->get('Reservations')->find()
            ->where(['property_id' => $this->ours])->count(), 'the refused booking saved nothing');
        $this->assertSame('available', $this->row('Rooms', $this->b['room'])->status);
    }

    /**
     * Their rooms in every state a refusal could tell apart: free, under
     * maintenance, out of service, booked over our dates, plus an id that
     * exists nowhere. Their booking (setUp) holds `room` for days 5 to 7.
     *
     * @return array<string, int>
     */
    private function theirRoomsInEveryState(): array
    {
        $room = fn(string $number, string $service): int => $this->insertRow('Rooms', [
            'property_id' => $this->theirs, 'room_number' => $number, 'room_type' => 'Deluxe',
            'status' => 'available', 'service_status' => $service,
        ]);

        return [
            'free' => $room('THEIRS-FREE-7', 'in_service'),
            'under maintenance' => $room('THEIRS-MAINT-7', 'maintenance'),
            'out of service' => $room('THEIRS-OOS-7', 'out_of_service'),
            'booked on our dates' => $this->b['room'],
            'nowhere' => 999999999,
        ];
    }

    /**
     * Each request with each of their rooms: the refusals must be identical,
     * so none of them tells one of their rooms from another.
     *
     * @param array<string, int> $rooms
     * @param callable(int): void $send Sends the request naming one room.
     */
    private function assertOneRefusalForEveryRoom(string $what, array $rooms, callable $send): void
    {
        $answers = [];
        foreach ($rooms as $state => $roomId) {
            $send($roomId);
            $status = $this->_response->getStatusCode();
            $body = (string)$this->_response->getBody();
            $this->assertContains($status, [400, 404, 422], "$what, their room $state: $body");
            foreach (['THEIRS-', 'maintenance', 'out of service', 'already booked'] as $marker) {
                $this->assertStringNotContainsStringIgnoringCase($marker, $body, "$what, their room $state");
            }
            $answers[$state] = [$status, $body];
        }
        foreach ($answers as $state => $answer) {
            $this->assertSame($answers['nowhere'], $answer, "$what: their room $state answers like a room that doesn't exist");
        }
    }

    /**
     * SF-2026-007: naming their room on a new booking says only that it isn't
     * ours, never its number, its service state or whether it's booked.
     */
    public function testANewBookingSaysNothingAboutAnotherPropertysRoom(): void
    {
        $rooms = $this->theirRoomsInEveryState();
        $this->assertOneRefusalForEveryRoom('a walk-in', $rooms, function (int $roomId): void {
            $this->callAs($this->adminToken, 'POST', '/api/reservations', [
                'room_id' => $roomId,
                'source' => 'walk_in',
                'check_out' => BusinessTime::today()->addDays(6)->format('Y-m-d'),
            ]);
        });

        $this->assertSame(0, $this->getTableLocator()->get('Reservations')->find()
            ->where(['property_id' => $this->ours])->count(), 'nothing was booked');
        foreach ($rooms as $roomId) {
            if ($roomId !== 999999999) {
                $this->assertSame('available', $this->row('Rooms', $roomId)->status);
            }
        }
    }

    /**
     * SF-2026-007: moving our booking into their room says the same.
     */
    public function testMovingABookingSaysNothingAboutAnotherPropertysRoom(): void
    {
        $ourRoom = $this->insertRow('Rooms', [
            'property_id' => $this->ours, 'room_number' => 'OURS-301', 'room_type' => 'Deluxe', 'status' => 'available',
        ]);
        $ourStay = $this->insertRow('Reservations', [
            'property_id' => $this->ours, 'room_id' => $ourRoom, 'receptionist_id' => $this->userIdFor($this->adminToken),
            'status' => 'booked', 'source' => 'walk_in', 'payment_status' => 'unpaid', 'total_guests' => 1,
            'check_in' => BusinessTime::today()->addDays(5)->format('Y-m-d'),
            'check_out' => BusinessTime::today()->addDays(7)->format('Y-m-d'),
        ]);

        $this->assertOneRefusalForEveryRoom('moving our booking', $this->theirRoomsInEveryState(), function (int $roomId) use ($ourStay): void {
            $this->callAs($this->adminToken, 'PATCH', "/api/reservations/$ourStay", ['room_id' => $roomId]);
        });

        $this->assertSame($ourRoom, (int)$this->row('Reservations', $ourStay)->room_id, 'our booking keeps our room');
    }

    /**
     * Code-only until now: a `property_id` in the body never chooses where
     * staff's new rows go, or moves an existing one.
     */
    public function testAPropertyIdInTheBodyNeverPlacesOrMovesARow(): void
    {
        $this->callAs($this->adminToken, 'POST', '/api/rooms', [
            'room_number' => 'BODY-1', 'room_type' => 'Standard', 'property_id' => $this->theirs,
        ]);
        $this->assertResponseCode(201);
        $roomId = (int)$this->responseJson()['room']['id'];
        $this->assertSame($this->ours, (int)$this->row('Rooms', $roomId)->property_id);

        $this->callAs($this->receptionistToken, 'POST', '/api/guests', [
            'full_name' => 'Body Placed', 'guest_type' => 'local', 'property_id' => $this->theirs,
        ]);
        $this->assertResponseCode(201);
        $guestId = (int)$this->responseJson()['guest']['id'];
        $this->assertSame($this->ours, (int)$this->row('Guests', $guestId)->property_id);

        $this->callAs($this->receptionistToken, 'PATCH', "/api/guests/$guestId", [
            'address' => 'Somewhere', 'property_id' => $this->theirs,
        ]);
        $this->assertResponseOk();
        $this->assertSame($this->ours, (int)$this->row('Guests', $guestId)->property_id);
        $this->callAs($this->adminToken, 'PATCH', "/api/rooms/$roomId", [
            'room_number' => 'BODY-1', 'room_type' => 'Suite', 'property_id' => $this->theirs,
        ]);
        $this->assertResponseOk();
        $this->assertSame($this->ours, (int)$this->row('Rooms', $roomId)->property_id);

        foreach (['Rooms', 'Guests'] as $table) {
            $this->assertSame(0, $this->getTableLocator()->get($table)->find()
                ->where(['property_id' => $this->theirs, 'id IN' => [$roomId, $guestId]])->count(), "no $table row of ours moved to theirs");
        }
    }

    // ------------------------------------------------- the platform owner

    /**
     * Step 10b (F3 closed): the Platform Owner holds no hotel permission, so
     * naming a property gets them nothing. Through a support session they
     * read that one property, whatever property they name.
     */
    public function testPlatformOwnerReachesHotelDataOnlyThroughASupportSessionForThatProperty(): void
    {
        $this->callAs($this->ownerToken, 'GET', '/api/rooms?property_id=' . $this->theirs);
        $this->assertResponseCode(403);

        $this->callAs($this->ownerToken, 'POST', '/api/platform/support-sessions', [
            'property_id' => $this->theirs, 'reason' => 'Checking a room report',
        ]);
        $this->assertResponseCode(201);

        $this->callAs($this->ownerToken, 'GET', '/api/rooms?property_id=' . $this->ours);
        $this->assertResponseOk();
        $this->assertStringContainsString(self::B_ROOM, (string)$this->_response->getBody(), 'the session\'s property');
        $this->callAs($this->ownerToken, 'GET', '/api/rooms');
        $this->assertResponseOk();
        $this->assertStringContainsString(self::B_ROOM, (string)$this->_response->getBody());
    }
}
