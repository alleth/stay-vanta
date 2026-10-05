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
        $category = $this->insertRow('InventoryCategories', [
            'property_id' => $p, 'name' => 'Theirs', 'kind' => 'food_stock',
        ]);
        $this->b['item'] = $this->insertRow('InventoryItems', [
            'property_id' => $p, 'inventory_category_id' => $category, 'name' => self::B_ITEM,
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
            '/api/food-orders', '/api/users', '/api/stock-movements', '/api/guests/match?full_name=' . urlencode(self::B_GUEST),
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
        foreach (['/api/operations/today', '/api/reports/operations'] as $url) {
            $this->callAs($this->adminToken, 'GET', $url . '?property_id=' . $this->theirs);
            $this->assertResponseOk();
            $this->assertNothingOfTheirs($url);
            $this->assertSame(0, $this->responseJson()['operations']['rooms']['total']);
        }

        foreach (['/api/finance/collections', '/api/reports/daily-collection'] as $url) {
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
            ['POST', '/api/reservations/' . $b['reservation'] . '/payment', ['payment_status' => 'paid']],
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

    // ------------------------------------------------- the platform owner

    public function testPlatformOwnerReachesAPropertyOnlyWhenNamingIt(): void
    {
        $this->callAs($this->ownerToken, 'GET', '/api/rooms?property_id=' . $this->theirs);
        $this->assertResponseOk();
        $this->assertStringContainsString(self::B_ROOM, (string)$this->_response->getBody());

        $this->callAs($this->ownerToken, 'GET', '/api/rooms?property_id=' . $this->ours);
        $this->assertResponseOk();
        $this->assertStringNotContainsString(self::B_ROOM, (string)$this->_response->getBody());
    }
}
