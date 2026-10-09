<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Test\TestSuite\PermissionCatalog;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Routing\Router;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The access matrix: every API route, the permission it requires, and each
 * role's answer, all derived from the approved catalog (docs/PERMISSIONS.md).
 *
 * For every probe and every role: a role the catalog doesn't grant the
 * permission must get exactly 403; a role it does grant must get through
 * cleanly: not 401/403, and not a server error (a 500 hides whether the gate
 * works at all). Missing ids (404) and validation errors (400) are fine, so
 * the probes don't depend on data. A new route without a probe fails
 * testEveryApiRouteHasAProbe, so nothing ships unmapped.
 *
 * Data-dependent rules (cancelling a paid order, renaming a room, backdating,
 * correcting a stay) are scenario tests below or in their own suites.
 */
class PermissionMatrixApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private const ROLES = ['owner', 'admin', 'receptionist'];

    /**
     * Routes that need no permission: signing in, and what a signed-in user
     * always needs (who am I, sign out, their own sign-ins).
     */
    private const PUBLIC_ROUTES = [
        'POST /api/auth/login', 'GET /api/auth/me', 'POST /api/auth/logout', 'GET /api/auth/sign-ins',
    ];

    /**
     * [method, route template, permission, query string, body].
     * `{id}` becomes an id that doesn't exist, `{transition}` a real
     * transition, and `{property}` in a body the test property's id.
     */
    private const PROBES = [
        // Platform
        ['GET', '/api/platform/dashboard', 'platform.dashboard.view'],
        ['POST', '/api/properties', 'platform.property.manage'],
        ['PATCH', '/api/properties/{id}', 'platform.property.manage'],
        ['PUT', '/api/properties/{id}', 'platform.property.manage'],
        ['GET', '/api/properties', 'settings.property.view'],
        // Operations
        ['GET', '/api/operations/today', 'operations.today.view'],
        ['GET', '/api/operations/activity', 'operations.staff.view'],
        // Finance
        ['GET', '/api/finance/collections', 'finance.collections.view'],
        ['GET', '/api/finance/collections', 'finance.collections.view_range', 'month=1&year=2026'],
        ['GET', '/api/finance/collections', 'finance.collections.view_range', 'from=2026-01-01&to=2026-01-31'],
        ['GET', '/api/finance/summary', 'finance.analytics.view'],
        ['GET', '/api/finance/seasonality', 'finance.analytics.view'],
        ['GET', '/api/invoices', 'finance.invoice.view'],
        ['GET', '/api/invoices/{id}', 'finance.invoice.view'],
        ['GET', '/api/receipt-series', 'finance.invoice.view'],
        ['POST', '/api/invoices/{id}/settle', 'finance.invoice.settle'],
        ['POST', '/api/invoices/{id}/refund', 'finance.invoice.refund', '', ['reason' => 'Matrix probe', 'amount' => 1, 'method' => 'cash']],
        ['POST', '/api/invoices/{id}/lines/{lineId}/reverse', 'finance.invoice.reverse', '', ['reason' => 'Matrix probe']],
        ['POST', '/api/receipt-series', 'finance.receipt_series.manage'],
        ['PATCH', '/api/receipt-series/{id}', 'finance.receipt_series.manage'],
        ['PUT', '/api/receipt-series/{id}', 'finance.receipt_series.manage'],
        ['DELETE', '/api/receipt-series/{id}', 'finance.receipt_series.manage'],
        // Front Desk
        ['GET', '/api/reservations', 'front_desk.reservation.view'],
        ['GET', '/api/reservations/stats', 'front_desk.reservation.view'],
        ['POST', '/api/reservations', 'front_desk.reservation.manage'],
        ['GET', '/api/reservations/{id}/history', 'front_desk.reservation.view'],
        ['GET', '/api/reservations/{id}/price', 'front_desk.reservation.view'],
        ['GET', '/api/config-changes', 'settings.change_log.view'],
        ['GET', '/api/platform/property-changes', 'platform.property.manage'],
        ['PATCH', '/api/reservations/{id}', 'front_desk.reservation.manage'],
        ['PUT', '/api/reservations/{id}', 'front_desk.reservation.manage'],
        ['POST', '/api/reservations/{id}/{transition}', 'front_desk.reservation.manage'],
        ['POST', '/api/reservations/{id}/post-room-charge', 'front_desk.reservation.manage'],
        ['POST', '/api/reservations/{id}/reverse-room-charge', 'finance.invoice.reverse', '', ['reason' => 'Matrix probe']],
        ['DELETE', '/api/reservations/{id}', 'front_desk.reservation.delete'],
        // Guests
        ['GET', '/api/guests', 'guests.guest.view'],
        ['GET', '/api/guests/stats', 'guests.guest.view'],
        ['GET', '/api/guests/{id}', 'guests.guest.view'],
        ['POST', '/api/guests', 'guests.guest.manage'],
        ['PATCH', '/api/guests/{id}', 'guests.guest.manage'],
        ['PUT', '/api/guests/{id}', 'guests.guest.manage'],
        // POS
        ['GET', '/api/food-orders', 'pos.sale.view'],
        ['GET', '/api/food-orders/{id}', 'pos.sale.view'],
        ['GET', '/api/food-menu-items', 'pos.sale.view'],
        ['POST', '/api/food-orders', 'pos.sale.manage'],
        ['POST', '/api/food-orders/{id}/serve', 'pos.sale.manage'],
        ['POST', '/api/food-orders/{id}/cancel', 'pos.sale.manage'],
        ['POST', '/api/food-orders/{id}/refund', 'pos.sale.cancel_paid', '', ['reason' => 'Matrix probe', 'method' => 'cash']],
        ['POST', '/api/food-menu-items', 'pos.menu.manage'],
        ['PATCH', '/api/food-menu-items/{id}', 'pos.menu.manage'],
        ['PUT', '/api/food-menu-items/{id}', 'pos.menu.manage'],
        ['DELETE', '/api/food-menu-items/{id}', 'pos.menu.manage'],
        // Rooms
        ['GET', '/api/rooms', 'rooms.room.view'],
        ['PATCH', '/api/rooms/{id}', 'rooms.room.update_status', '', ['status' => 'maintenance']],
        ['PUT', '/api/rooms/{id}', 'rooms.room.update_status', '', ['status' => 'maintenance']],
        // Inventory
        ['GET', '/api/inventory-items', 'inventory.item.view'],
        ['GET', '/api/inventory-items/{id}', 'inventory.item.view'],
        ['GET', '/api/inventory-categories', 'inventory.item.view'],
        ['GET', '/api/stock-movements', 'inventory.item.view'],
        ['POST', '/api/inventory-items', 'inventory.item.manage'],
        ['PATCH', '/api/inventory-items/{id}', 'inventory.item.manage'],
        ['PUT', '/api/inventory-items/{id}', 'inventory.item.manage'],
        ['DELETE', '/api/inventory-items/{id}', 'inventory.item.manage'],
        ['POST', '/api/inventory-categories', 'inventory.category.manage'],
        ['DELETE', '/api/inventory-categories/{id}', 'inventory.category.manage'],
        ['POST', '/api/stock-movements', 'inventory.stock.adjust'],
        // Settings
        ['GET', '/api/room-rates', 'settings.configuration.view'],
        ['GET', '/api/promo-rates', 'settings.configuration.view'],
        ['GET', '/api/booking-sources', 'settings.configuration.view'],
        ['GET', '/api/extra-charges', 'settings.configuration.view'],
        ['POST', '/api/rooms', 'settings.room.manage'],
        ['DELETE', '/api/rooms/{id}', 'settings.room.manage'],
        ['POST', '/api/room-rates', 'settings.room_rate.manage'],
        ['PATCH', '/api/room-rates/{id}', 'settings.room_rate.manage'],
        ['PUT', '/api/room-rates/{id}', 'settings.room_rate.manage'],
        ['POST', '/api/promo-rates', 'settings.promo_rate.manage'],
        ['PATCH', '/api/promo-rates/{id}', 'settings.promo_rate.manage'],
        ['PUT', '/api/promo-rates/{id}', 'settings.promo_rate.manage'],
        ['DELETE', '/api/promo-rates/{id}', 'settings.promo_rate.manage'],
        ['POST', '/api/extra-charges', 'settings.extra_charge.manage'],
        ['PATCH', '/api/extra-charges/{id}', 'settings.extra_charge.manage'],
        ['PUT', '/api/extra-charges/{id}', 'settings.extra_charge.manage'],
        ['DELETE', '/api/extra-charges/{id}', 'settings.extra_charge.manage'],
        // Staff
        ['GET', '/api/users', 'staff.account.view'],
        ['POST', '/api/users', 'staff.account.manage', '', ['role' => 'receptionist', 'property_id' => '{property}']],
        ['PATCH', '/api/users/{id}', 'staff.account.manage'],
        ['PUT', '/api/users/{id}', 'staff.account.manage'],
        ['POST', '/api/users/{id}/reset-password', 'staff.account.manage'],
        ['GET', '/api/users/{id}/access-history', 'staff.access_history.view'],
        // Support access (step 10b): no reason in the probe, so a holder gets 400 and no session opens.
        ['POST', '/api/platform/support-sessions', 'platform.support_access.start', '', ['property_id' => '{property}']],
        ['POST', '/api/platform/support-sessions/{id}/end', 'platform.support_access.start'],
        ['GET', '/api/support-sessions', 'staff.access_history.view'],
        ['GET', '/api/support-sessions/{id}', 'staff.access_history.view'],
        ['POST', '/api/support-sessions/{id}/end', 'staff.account.manage'],
        ['GET', '/api/roles', 'settings.role.view'],
        // Data exports (step 10c): no range in the probe, so a holder gets 400 and nothing is recorded.
        ['GET', '/api/reservations/export', 'front_desk.reservation.export'],
        ['GET', '/api/guests/export', 'guests.guest.export'],
        ['GET', '/api/guests/{id}/history', 'guests.guest.view_history'],
        // Guest search and matching by POST body (G5, P6).
        ['POST', '/api/guests/match', 'guests.guest.view'],
        ['POST', '/api/guests/search', 'guests.guest.view'],
        // Room service (G4).
        ['POST', '/api/rooms/{id}/service', 'rooms.room.update_status', '', ['service_status' => 'maintenance', 'reason' => 'probe']],
        ['GET', '/api/rooms/{id}/history', 'rooms.room.view_history'],
        ['GET', '/api/invoices/export', 'finance.invoice.export'],
        ['GET', '/api/finance/collections/export', 'finance.collections.export'],
    ];

    private const MISSING_ID = '999999999';

    private int $propertyId;

    /**
     * @var array<string, string> Bearer token per role.
     */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Permission Matrix Resort');
        foreach (self::ROLES as $role) {
            $propertyId = $role === 'owner' ? null : $this->propertyId;
            $this->tokens[$role] = $this->makeUser($propertyId, $role, "matrix-$role-$tag@example.test");
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: array<string, mixed>}>
     */
    public static function probeProvider(): array
    {
        $cases = [];
        foreach (self::PROBES as $probe) {
            [$method, $template, $permission] = $probe;
            $query = $probe[3] ?? '';
            $label = "$method $template" . ($query !== '' ? "?$query" : '');
            $cases[$label] = [$method, $template, $permission, $query, $probe[4] ?? []];
        }

        return $cases;
    }

    /**
     * Build the URL for a probe. The Platform Owner isn't bound to a property,
     * so every probe names the test property; staff ignore the parameter.
     */
    private function urlFor(string $template, string $query): string
    {
        $path = strtr($template, ['{id}' => self::MISSING_ID, '{lineId}' => self::MISSING_ID, '{transition}' => 'check-in']);
        $params = 'property_id=' . $this->propertyId . ($query !== '' ? '&' . $query : '');

        return "$path?$params";
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function bodyFor(array $body): array
    {
        return array_map(fn($v) => $v === '{property}' ? $this->propertyId : $v, $body);
    }

    /**
     * The last response let an authorized caller through: no 401/403, and no
     * server error.
     */
    private function assertAllowed(string $context): void
    {
        $status = $this->_response->getStatusCode();
        $this->assertTrue(
            !in_array($status, [401, 403], true) && $status < 500,
            sprintf('%s: expected to get through, got %d: %s', $context, $status, $this->_response->getBody()),
        );
    }

    #[DataProvider('probeProvider')]
    public function testEachRoleGetsWhatTheCatalogGrants(
        string $method,
        string $template,
        string $permission,
        string $query,
        array $body,
    ): void {
        $url = $this->urlFor($template, $query);
        foreach (self::ROLES as $role) {
            $this->callAs($this->tokens[$role], $method, $url, $this->bodyFor($body));
            $status = $this->_response->getStatusCode();
            if (PermissionCatalog::grants($role, $permission)) {
                $this->assertAllowed(sprintf('%s %s: %s holds %s', $method, $url, $role, $permission));
            } else {
                $this->assertSame(403, $status, sprintf(
                    '%s %s: %s lacks %s and must get 403, got %d: %s',
                    $method,
                    $url,
                    $role,
                    $permission,
                    $status,
                    $this->_response->getBody(),
                ));
            }
        }
    }

    public function testEveryApiRouteHasAProbe(): void
    {
        // Any request loads the application's routes into the Router.
        $this->callAs($this->tokens['admin'], 'GET', '/api/auth/me');
        $this->assertResponseOk();

        $probed = [];
        foreach (self::PROBES as [$method, $template]) {
            $probed["$method $template"] = true;
        }
        $missing = [];
        $seen = 0;
        foreach (Router::getRouteCollection()->routes() as $route) {
            if (!str_starts_with($route->template, '/api/') || str_contains($route->template, '{controller}')) {
                continue;
            }
            foreach ((array)($route->defaults['_method'] ?? []) as $method) {
                $seen++;
                $key = strtoupper($method) . ' ' . $route->template;
                if (!isset($probed[$key]) && !in_array($key, self::PUBLIC_ROUTES, true)) {
                    $missing[] = $key;
                }
            }
        }

        $this->assertGreaterThan(count(self::PUBLIC_ROUTES), $seen, 'No API routes were loaded.');
        $this->assertSame([], $missing, 'Add these routes to PROBES (and their permission to docs/PERMISSIONS.md).');
    }

    public function testEveryProbeNamesACatalogPermission(): void
    {
        $catalog = PermissionCatalog::rows();
        $unknown = [];
        foreach (self::PROBES as [$method, $template, $permission]) {
            if (!isset($catalog[$permission])) {
                $unknown[] = "$method $template → $permission";
            }
        }
        $this->assertSame([], $unknown);
    }

    /**
     * Deny by default: a signed-in user whose role grants nothing (a role
     * with no grants, standing in for any future role before it's given
     * any) is refused by every route except the always-allowed ones.
     */
    public function testAUserWithNoPermissionsIsRefusedEverywhere(): void
    {
        $token = $this->makeUser($this->propertyId, 'receptionist', 'matrix-none-' . uniqid() . '@example.test');
        $roles = $this->getTableLocator()->get('Roles');
        $empty = $roles->saveOrFail($roles->newEntity(
            ['code' => 'test-none-' . uniqid(), 'name' => 'No grants', 'scope' => 'property'],
            ['accessibleFields' => ['*' => true]],
        ));
        $this->getTableLocator()->get('PropertyMemberships')
            ->updateAll(['role_id' => $empty->id], ['user_id' => $this->userIdFor($token)]);
        try {
            $this->assertRefusedEverywhere($token);
        } finally {
            $roles->delete($empty);
        }
    }

    /**
     * An account whose membership has ended holds nothing at all, whatever
     * its role column still says (step 10b).
     */
    public function testAUserWhoseMembershipEndedIsRefusedEverywhere(): void
    {
        $token = $this->makeUser($this->propertyId, 'admin', 'matrix-ended-' . uniqid() . '@example.test');
        $this->getTableLocator()->get('PropertyMemberships')
            ->updateAll(['ended_at' => new DateTime()], ['user_id' => $this->userIdFor($token)]);

        $this->assertRefusedEverywhere($token);
    }

    private function assertRefusedEverywhere(string $token): void
    {
        $this->callAs($token, 'GET', '/api/auth/me');
        $this->assertResponseOk();
        $this->assertSame([], $this->responseJson()['user']['permissions']);

        $allowed = [];
        foreach (self::probeProvider() as $label => [$method, $template, , $query, $body]) {
            $this->callAs($token, $method, $this->urlFor($template, $query), $this->bodyFor($body));
            if ($this->_response->getStatusCode() !== 403) {
                $allowed[] = sprintf('%s → %d', $label, $this->_response->getStatusCode());
            }
        }
        $this->assertSame([], $allowed, 'A user without permissions must get 403 here.');
    }

    public function testAuthMeListsWhatEachRoleHolds(): void
    {
        foreach (self::ROLES as $role) {
            $this->callAs($this->tokens[$role], 'GET', '/api/auth/me');
            $this->assertResponseOk();
            $expected = array_keys(array_filter(
                PermissionCatalog::rows(),
                fn($row) => in_array($role, $row['roles'], true),
            ));
            sort($expected);
            $this->assertSame($expected, $this->responseJson()['user']['permissions'], "permissions for $role");
        }
    }

    // ------------------------------------------- rules that depend on the data

    public function testOnlyCancelPaidHoldersCancelAServedPaidOrder(): void
    {
        foreach (self::ROLES as $role) {
            $orderId = $this->insertRow('FoodOrders', [
                'property_id' => $this->propertyId,
                'receptionist_id' => $this->userIdFor($this->tokens['admin']),
                'status' => 'served', 'payment_status' => 'paid', 'payment_method' => 'cash',
                'total' => 0, 'total_diners' => 1,
            ]);
            $this->callAs(
                $this->tokens[$role],
                'POST',
                "/api/food-orders/$orderId/cancel?property_id={$this->propertyId}",
            );
            $status = $this->_response->getStatusCode();
            if (PermissionCatalog::grants($role, 'pos.sale.cancel_paid')) {
                $this->assertAllowed("$role cancelling a paid, served order");
            } else {
                $this->assertSame(403, $status, "$role cancelling a paid, served order");
            }
        }
    }

    public function testOnlyRoomManagersRenameARoomButAnyoneHoldingStatusMayChangeIt(): void
    {
        foreach (self::ROLES as $i => $role) {
            $roomId = $this->insertRow('Rooms', [
                'property_id' => $this->propertyId, 'room_number' => "MX-$i", 'room_type' => 'Deluxe',
                'status' => 'available',
            ]);
            $url = "/api/rooms/$roomId?property_id={$this->propertyId}";

            // Service status (G4): POST /rooms/{id}/service; occupancy can't be set by hand.
            $this->callAs($this->tokens[$role], 'POST', "/api/rooms/$roomId/service?property_id={$this->propertyId}", [
                'service_status' => 'maintenance', 'reason' => 'Leaking tap',
            ]);
            if (!PermissionCatalog::grants($role, 'rooms.room.update_status')) {
                // The Platform Owner since step 10b: no hotel permissions at all.
                $this->assertResponseCode(403, "$role changing a room's service status");
                continue;
            }
            $this->assertResponseOk("$role putting a room under maintenance");

            $this->callAs($this->tokens[$role], 'PATCH', $url, ['room_number' => "MX-$i-renamed"]);
            if (PermissionCatalog::grants($role, 'settings.room.manage')) {
                $this->assertAllowed("$role renaming a room");
            } else {
                $this->assertResponseCode(403, "$role renaming a room");
            }
        }
    }
}
