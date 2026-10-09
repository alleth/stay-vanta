<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Who may call what: the role rules every endpoint enforces on the server.
 *
 * The screens hide what a role can't use, but that is only convenience; these
 * tests pin the backend checks that actually protect the data. They assert the
 * refusal (401/403) precisely, and for an allowed caller only that the request
 * wasn't refused, so validation details elsewhere don't make them brittle.
 *
 * Roles: owner = Platform Owner, admin = Manager, receptionist = Front Desk Staff.
 */
class AccessControlApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private string $ownerToken;
    private string $adminToken;
    private string $receptionistToken;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Access Control Resort');
        $this->ownerToken = $this->makeUser(null, 'owner', "acl-owner-$tag@example.test");
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "acl-admin-$tag@example.test");
        $this->receptionistToken = $this->makeUser($this->propertyId, 'receptionist', "acl-desk-$tag@example.test");
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    private function tokenFor(string $role): string
    {
        return match ($role) {
            'owner' => $this->ownerToken,
            'admin' => $this->adminToken,
            'receptionist' => $this->receptionistToken,
        };
    }

    private function assertRefused(int $code, string $role, string $method, string $url): void
    {
        $this->assertResponseCode($code, sprintf('%s %s should be refused for %s', $method, $url, $role));
    }

    private function assertNotRefused(string $role, string $method, string $url): void
    {
        $status = $this->_response->getStatusCode();
        $this->assertNotContains(
            $status,
            [401, 403],
            sprintf('%s %s should be allowed for %s, got %d: %s', $method, $url, $role, $status, $this->_response->getBody()),
        );
    }

    // ---------------------------------------------------------------- sign-in

    public function testEveryModuleRequiresAToken(): void
    {
        $urls = [
            '/api/auth/me', '/api/rooms', '/api/reservations', '/api/guests', '/api/inventory-items',
            '/api/food-orders', '/api/invoices', '/api/users', '/api/operations/today',
            '/api/finance/collections',
        ];
        foreach ($urls as $url) {
            $this->callAs(null, 'GET', $url);
            $this->assertRefused(401, 'anonymous', 'GET', $url);
        }
    }

    public function testAnUnknownTokenIsRefused(): void
    {
        $this->callAs('not-a-real-token', 'GET', '/api/auth/me');
        $this->assertResponseCode(401);
    }

    public function testADeactivatedUserIsSignedOut(): void
    {
        $token = $this->makeUser($this->propertyId, 'receptionist', 'acl-gone-' . uniqid() . '@example.test', false);
        $this->callAs($token, 'GET', '/api/auth/me');
        $this->assertResponseCode(401);
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $users = $this->getTableLocator()->get('Users');
        $user = $users->get($this->userIdFor($this->receptionistToken));
        $user->set('token_expires', new DateTime('-1 minute'));
        $users->saveOrFail($user);

        $this->callAs($this->receptionistToken, 'GET', '/api/auth/me');
        $this->assertResponseCode(401);
    }

    // ------------------------------------------------- refused by role (403)

    /**
     * @return array<string, array{0: list<string>, 1: string, 2: string, 3?: array<string, mixed>}>
     */
    public static function refusedProvider(): array
    {
        return [
            // Platform-owner only.
            'create a property' => [['admin', 'receptionist'], 'POST', '/api/properties', ['name' => 'X']],
            'platform dashboard' => [['admin', 'receptionist'], 'GET', '/api/platform/dashboard'],
            // Manager only (money detail and staff activity).
            'finance summary' => [['owner', 'receptionist'], 'GET', '/api/finance/summary'],
            'seasonality' => [['owner', 'receptionist'], 'GET', '/api/finance/seasonality'],
            'staff activity feed' => [['owner', 'receptionist'], 'GET', '/api/operations/activity'],
            // Staff on a property only; the platform owner has no property operations view.
            'operations' => [['owner'], 'GET', '/api/operations/today'],
            // Front Desk Staff may see one day only.
            'monthly collection' => [['receptionist'], 'GET', '/api/finance/collections?month=1&year=2026'],
            'date-range collection' => [
                ['receptionist'], 'GET', '/api/finance/collections?from=2026-01-01&to=2026-01-31',
            ],
            // Configuration and stock control are for managers.
            'add a room' => [['receptionist'], 'POST', '/api/rooms', ['room_number' => 'R-1']],
            'delete a room' => [['receptionist'], 'DELETE', '/api/rooms/999999'],
            'add a room rate' => [['receptionist'], 'POST', '/api/room-rates', ['base_rate' => 1000]],
            'add a promo rate' => [['receptionist'], 'POST', '/api/promo-rates', ['source' => 'agoda', 'multiplier' => 1]],
            'add an extra charge' => [['receptionist'], 'POST', '/api/extra-charges', ['name' => 'Bed', 'amount' => 100]],
            'add an inventory category' => [['receptionist'], 'POST', '/api/inventory-categories', ['name' => 'X']],
            'add an inventory item' => [['receptionist'], 'POST', '/api/inventory-items', ['name' => 'X']],
            'move stock manually' => [
                ['receptionist'], 'POST', '/api/stock-movements',
                ['inventory_item_id' => 1, 'direction' => 'in', 'quantity' => 1],
            ],
            'add a receipt booklet' => [['receptionist'], 'POST', '/api/receipt-series', ['type' => 'invoice']],
            'add a menu item' => [['receptionist'], 'POST', '/api/food-menu-items', ['name' => 'X', 'price' => 1]],
            // Staff management.
            'list staff' => [['receptionist'], 'GET', '/api/users'],
            'create staff' => [['receptionist'], 'POST', '/api/users', ['role' => 'receptionist']],
            // Deleting a reservation is a manager correction.
            'delete a reservation' => [['receptionist'], 'DELETE', '/api/reservations/999999'],
        ];
    }

    #[DataProvider('refusedProvider')]
    public function testRoleIsRefused(array $roles, string $method, string $url, array $body = []): void
    {
        foreach ($roles as $role) {
            $this->callAs($this->tokenFor($role), $method, $url, $body);
            $this->assertRefused(403, $role, $method, $url);
        }
    }

    // ----------------------------------------------- allowed for the right role

    public function testManagerCanUseManagerOnlyReports(): void
    {
        $urls = [
            '/api/finance/summary', '/api/finance/seasonality', '/api/operations/activity',
        ];
        foreach ($urls as $url) {
            $this->callAs($this->adminToken, 'GET', $url);
            $this->assertResponseOk("GET $url for admin");
        }
    }

    public function testPlatformOwnerCanUsePlatformDashboard(): void
    {
        foreach (['/api/platform/dashboard'] as $url) {
            $this->callAs($this->ownerToken, 'GET', $url);
            $this->assertResponseOk("GET $url for owner");
        }
    }

    public function testFrontDeskStaffGetOperationsWithoutStaffData(): void
    {
        foreach (['/api/operations/today'] as $url) {
            $this->callAs($this->receptionistToken, 'GET', $url);
            $this->assertResponseOk();
            $operations = $this->responseJson()['operations'];
            $this->assertNull($operations['staff'], "$url: Front Desk Staff must not receive the staff list");
            $this->assertNull($operations['activity'], "$url: Front Desk Staff must not receive the activity feed");

            $this->callAs($this->adminToken, 'GET', $url);
            $this->assertResponseOk();
            $this->assertIsArray($this->responseJson()['operations']['staff']);
        }
    }

    public function testFrontDeskStaffCanSeeOneDaysCollection(): void
    {
        foreach (['/api/finance/collections'] as $url) {
            $this->callAs($this->receptionistToken, 'GET', $url);
            $this->assertResponseOk("GET $url for receptionist");
            $this->callAs($this->adminToken, 'GET', $url . '?month=1&year=2026');
            $this->assertResponseOk("GET $url?month for admin");
        }
    }

    public function testRefusalMessagesUseTheRoleDisplayNames(): void
    {
        $this->callAs($this->receptionistToken, 'POST', '/api/rooms', ['room_number' => 'R-1']);
        $this->assertResponseCode(403);
        $message = (string)$this->responseJson()['message'];
        $this->assertStringContainsString('Manager', $message);
        $this->assertStringNotContainsString('admin', $message);
        $this->assertStringNotContainsString('owner', $message);
    }

    public function testFrontDeskStaffCanDoFrontDeskWork(): void
    {
        foreach (['/api/rooms', '/api/reservations', '/api/guests', '/api/invoices', '/api/food-orders'] as $url) {
            $this->callAs($this->receptionistToken, 'GET', $url);
            $this->assertNotRefused('receptionist', 'GET', $url);
        }
    }

    // ------------------------------------------------------- staff management

    public function testManagerMayOnlyCreateFrontDeskStaff(): void
    {
        foreach (['admin', 'owner'] as $role) {
            $this->callAs($this->adminToken, 'POST', '/api/users', [
                'name' => 'Escalation', 'email' => 'acl-esc-' . uniqid() . '@example.test',
                'password' => 'secret123', 'role' => $role,
            ]);
            $this->assertRefused(403, 'admin creating ' . $role, 'POST', '/api/users');
        }

        $this->callAs($this->adminToken, 'POST', '/api/users', [
            'name' => 'New Desk', 'email' => 'acl-new-' . uniqid() . '@example.test',
            'password' => 'secret123', 'role' => 'receptionist',
        ]);
        $this->assertResponseCode(201);
        $this->assertSame($this->propertyId, (int)$this->responseJson()['user']['property_id']);
    }

    public function testManagerCannotManageThePlatformOwner(): void
    {
        $ownerId = $this->userIdFor($this->ownerToken);

        $this->callAs($this->adminToken, 'PATCH', "/api/users/$ownerId", ['is_active' => false]);
        $this->assertResponseCode(404);
        $this->callAs($this->adminToken, 'POST', "/api/users/$ownerId/reset-password", ['password' => 'hijacked1']);
        $this->assertResponseCode(404);

        $owner = $this->getTableLocator()->get('Users')->get($ownerId);
        $this->assertTrue((bool)$owner->is_active);
    }

    public function testManagerCannotResetAnotherManagersPassword(): void
    {
        $otherAdmin = $this->makeUser($this->propertyId, 'admin', 'acl-admin2-' . uniqid() . '@example.test');
        $otherId = $this->userIdFor($otherAdmin);

        $this->callAs($this->adminToken, 'POST', "/api/users/$otherId/reset-password", ['password' => 'hijacked1']);
        $this->assertResponseCode(403);
    }

    public function testResettingAPasswordSignsThatUserOut(): void
    {
        $deskId = $this->userIdFor($this->receptionistToken);
        $this->callAs($this->adminToken, 'POST', "/api/users/$deskId/reset-password", [
            'password' => 'newsecret1', 'reason' => 'Forgot it',
        ]);
        $this->assertResponseOk();

        $this->callAs($this->receptionistToken, 'GET', '/api/auth/me');
        $this->assertResponseCode(401);
    }
}
