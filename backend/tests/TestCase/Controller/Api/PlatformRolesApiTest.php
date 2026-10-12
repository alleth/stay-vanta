<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Auth\Permissions;
use App\Auth\RoleDefinitions;
use App\Model\Table\RoleEventsTable;
use Cake\Database\Connection;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Permissions Phase 3b (approved 2026-10-12 as E3b-D1–D5): the Platform
 * Owner's role catalog. Custom roles are created blank or by copy (the copy
 * keeps its template), edited with a version check, reviewed when the
 * catalog grows, archived only when unheld; built-ins stay locked; every
 * change is a role_events row with the Platform Owner as actor, a reason and
 * the request id; nobody can be given a custom role until 3c.
 */
class PlatformRolesApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private Connection $connection;
    private int $propertyId;
    private string $ownerToken;
    private string $adminToken;
    private string $deskToken;
    private int $adminRoleId;
    private int $deskRoleId;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var \Cake\Database\Connection $connection */
        $connection = $this->getTableLocator()->get('Roles')->getConnection();
        $this->connection = $connection;
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Catalog Inn');
        $this->ownerToken = $this->makeUser(null, 'owner', "cat-owner-$tag@example.test");
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "cat-admin-$tag@example.test");
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', "cat-desk-$tag@example.test");
        $roles = $this->getTableLocator()->get('Roles');
        $this->adminRoleId = (int)$roles->idFor('admin');
        $this->deskRoleId = (int)$roles->idFor('receptionist');
    }

    protected function tearDown(): void
    {
        // Custom roles are platform data: remove the ones this test made, and
        // put the built-ins' grants back (a review test re-grants one).
        $this->getTableLocator()->get('PropertyMemberships')->updateAll(
            ['role_id' => $this->deskRoleId],
            ['property_id' => $this->propertyId, 'role_id NOT IN' => [$this->adminRoleId, $this->deskRoleId]],
        );
        $custom = $this->connection->execute("SELECT id FROM roles WHERE kind = 'custom'")->fetchAll('assoc');
        foreach (array_column($custom, 'id') as $id) {
            $this->connection->delete('role_events', ['role_id' => $id]);
            $this->connection->delete('role_permissions', ['role_id' => $id]);
            $this->connection->delete('roles', ['id' => $id]);
        }
        (new RoleDefinitions($this->connection))->grant('Restored after PlatformRolesApiTest', 'PlatformRolesApiTest');
        $this->cleanupScenario();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed> The role as answered.
     */
    private function create(array $data): array
    {
        $this->callAs($this->ownerToken, 'POST', '/api/platform/roles', $data + ['reason' => 'Test role']);
        $this->assertResponseCode(201, (string)$this->_response->getBody());

        return $this->responseJson()['role'];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(int $id): array
    {
        $this->callAs($this->ownerToken, 'GET', "/api/platform/roles/$id");
        $this->assertResponseOk();

        return $this->responseJson()['role'];
    }

    /**
     * @return list<\Cake\Datasource\EntityInterface>
     */
    private function events(int $roleId): array
    {
        return $this->getTableLocator()->get('RoleEvents')->find()
            ->where(['role_id' => $roleId])->orderBy(['id' => 'ASC'])->all()->toList();
    }

    /**
     * @return list<string> The permissions the detail marks with `$mark`.
     */
    private function marked(array $role, string $mark): array
    {
        return array_column(array_filter($role['permissions'], fn($p) => $p['mark'] === $mark), 'permission');
    }

    public function testACopyKeepsItsTemplateAndRecordsWhoAndWhy(): void
    {
        $role = $this->create(['name' => 'Operations Manager', 'copy_from' => $this->adminRoleId, 'reason' => 'Ops lead']);
        $this->assertSame('custom', $role['kind']);
        $this->assertSame('Manager', $role['template']['source']['name']);
        $this->assertCount(45, $role['template']['permissions']);
        $this->assertCount(45, $this->marked($role, 'template'), 'every held permission came from the template');
        $this->assertSame(['added' => [], 'removed' => []], $role['since_creation']);
        $this->assertSame(['total' => 0, 'properties' => []], $role['memberships']);

        $events = $this->events($role['id']);
        $this->assertSame(RoleEventsTable::ROLE_COPIED, $events[0]->event_type);
        $this->assertCount(46, $events, 'the copy and one grant per permission');
        $this->assertSame([$events[0]->correlation_id], array_values(array_unique(array_map(fn($e) => $e->correlation_id, $events))));
        $this->assertSame($this->userIdFor($this->ownerToken), (int)$events[0]->actor_id);
        $this->assertSame(['web', 'Ops lead'], [$events[0]->source, $events[0]->reason]);
        $this->assertSame(0, $events[0]->snapshot['active_memberships']);

        // Not assignable until 3c.
        $this->callAs($this->ownerToken, 'GET', '/api/users?property_id=' . $this->propertyId);
        $this->assertNotContains('Operations Manager', array_column($this->responseJson()['assignable_roles'], 'name'));
        $deskId = $this->userIdFor($this->deskToken);
        $this->callAs($this->ownerToken, 'PATCH', "/api/users/$deskId", ['role' => $role['code'], 'reason' => 'x']);
        $this->assertResponseCode(400);
    }

    public function testABlankRoleAndTheRulesOnCreating(): void
    {
        $role = $this->create([
            'name' => 'Night Auditor', 'description' => 'Closes the day',
            'permissions' => [Permissions::FINANCE_INVOICE_VIEW, Permissions::FRONT_DESK_RESERVATION_VIEW],
        ]);
        $this->assertNull($role['template']);
        $this->assertCount(2, $this->marked($role, 'initial'));
        $this->assertSame(RoleEventsTable::ROLE_CREATED, $this->events($role['id'])[0]->event_type);

        foreach (
            [
                'no permissions' => ['name' => 'Empty', 'permissions' => []],
                'a platform permission' => ['name' => 'Sneaky', 'permissions' => [Permissions::PLATFORM_DASHBOARD_VIEW]],
                'an unknown permission' => ['name' => 'Odd', 'permissions' => ['nope.nope.nope']],
                'a taken name, any case' => ['name' => 'night auditor', 'permissions' => [Permissions::FINANCE_INVOICE_VIEW]],
                'no name' => ['name' => ' ', 'permissions' => [Permissions::FINANCE_INVOICE_VIEW]],
            ] as $case => $data
        ) {
            $this->callAs($this->ownerToken, 'POST', '/api/platform/roles', $data + ['reason' => 'x']);
            $this->assertResponseCode(400, $case);
        }
        $this->callAs($this->ownerToken, 'POST', '/api/platform/roles', [
            'name' => 'No Reason', 'permissions' => [Permissions::FINANCE_INVOICE_VIEW],
        ]);
        $this->assertResponseCode(400, 'a reason is required');
        $this->callAs($this->adminToken, 'POST', '/api/platform/roles', [
            'name' => 'By Manager', 'permissions' => [Permissions::FINANCE_INVOICE_VIEW], 'reason' => 'x',
        ]);
        $this->assertResponseCode(403, 'only the Platform Owner');
        $this->assertSame(1, $this->getTableLocator()->get('Roles')->find()->where(['kind' => 'custom'])->count());
    }

    public function testAnEditIsPreviewedRecordedAndRefusedWhenStale(): void
    {
        $role = $this->create(['name' => 'Desk Lead', 'copy_from' => $this->deskRoleId]);
        $version = $role['version'];
        $held = array_column(array_filter($role['permissions'], fn($p) => $p['held']), 'permission');
        $wanted = array_values(array_diff($held, [Permissions::INVENTORY_ITEM_VIEW]));
        $wanted[] = Permissions::FINANCE_ANALYTICS_VIEW;

        $this->callAs($this->ownerToken, 'POST', "/api/platform/roles/{$role['id']}/preview", [
            'permissions' => $wanted, 'version' => $version,
        ]);
        $preview = $this->responseJson()['preview'];
        $this->assertSame([[Permissions::FINANCE_ANALYTICS_VIEW], [Permissions::INVENTORY_ITEM_VIEW]], [$preview['added'], $preview['removed']]);
        $this->assertSame(['inventory'], $preview['modules_lost']);
        $this->assertFalse($preview['stale']);
        $this->assertCount(16, $this->events($role['id']), 'a preview writes nothing');

        $this->callAs($this->ownerToken, 'PATCH', "/api/platform/roles/{$role['id']}", [
            'permissions' => $wanted, 'version' => $version, 'description' => 'Runs the desk', 'reason' => 'More reporting',
        ]);
        $this->assertResponseOk((string)$this->_response->getBody());
        $after = $this->responseJson()['role'];
        $this->assertSame([Permissions::FINANCE_ANALYTICS_VIEW], $after['since_creation']['added']);
        $this->assertSame([Permissions::INVENTORY_ITEM_VIEW], $after['since_creation']['removed']);
        $this->assertSame([Permissions::FINANCE_ANALYTICS_VIEW], $this->marked($after, 'added'));
        $this->assertSame([Permissions::INVENTORY_ITEM_VIEW], $this->marked($after, 'removed'));
        $types = array_map(fn($e) => [$e->event_type, $e->permission, $e->reason], array_slice($this->events($role['id']), 16));
        $this->assertEqualsCanonicalizing([
            [RoleEventsTable::ROLE_DESCRIPTION_CHANGED, null, 'More reporting'],
            [RoleEventsTable::PERMISSION_GRANTED, Permissions::FINANCE_ANALYTICS_VIEW, 'More reporting'],
            [RoleEventsTable::PERMISSION_REVOKED, Permissions::INVENTORY_ITEM_VIEW, 'More reporting'],
        ], $types);

        // The version the first save saw is stale now: refused, nothing recorded.
        $before = count($this->events($role['id']));
        $this->callAs($this->ownerToken, 'PATCH', "/api/platform/roles/{$role['id']}", [
            'name' => 'Desk Lead 2', 'version' => $version, 'reason' => 'Overwrite',
        ]);
        $this->assertResponseCode(409);
        $this->assertStringContainsString('changed since you opened it', $this->responseJson()['message']);
        $this->assertSame($before, count($this->events($role['id'])));
        $this->callAs($this->ownerToken, 'POST', "/api/platform/roles/{$role['id']}/preview", [
            'permissions' => $wanted, 'version' => $version,
        ]);
        $this->assertTrue($this->responseJson()['preview']['stale']);

        $this->assertTrue((new RoleDefinitions($this->connection))->check()['complete'], 'custom history replays');
    }

    public function testBuiltInRolesAreLocked(): void
    {
        $version = $this->detail($this->adminRoleId)['version'];
        $this->callAs($this->ownerToken, 'PATCH', "/api/platform/roles/{$this->adminRoleId}", [
            'name' => 'Boss', 'version' => $version, 'reason' => 'x',
        ]);
        $this->assertResponseCode(400);
        $this->assertStringContainsString('Copy this role', $this->responseJson()['message']);
        $this->callAs($this->ownerToken, 'POST', "/api/platform/roles/{$this->adminRoleId}/archive", ['reason' => 'x']);
        $this->assertResponseCode(400);
        $this->assertSame('Manager', $this->detail($this->adminRoleId)['name']);
    }

    public function testARoleIsArchivedOnlyWhenNobodyHoldsIt(): void
    {
        $role = $this->create(['name' => 'Seasonal Desk', 'copy_from' => $this->deskRoleId]);
        $deskId = $this->userIdFor($this->deskToken);
        // Stand-in for 3c: a person holding the role.
        $this->getTableLocator()->get('PropertyMemberships')->updateAll(['role_id' => $role['id']], ['user_id' => $deskId]);
        $held = $this->detail($role['id'])['memberships'];
        $this->assertSame(1, $held['total']);
        $this->assertSame('Catalog Inn', $held['properties'][0]['name']);

        $this->callAs($this->ownerToken, 'POST', "/api/platform/roles/{$role['id']}/archive", ['reason' => 'Season over']);
        $this->assertResponseCode(400);
        $this->assertStringContainsString('Reassign the 1 person', $this->responseJson()['message']);

        $this->getTableLocator()->get('PropertyMemberships')->updateAll(['role_id' => $this->deskRoleId], ['user_id' => $deskId]);
        $this->callAs($this->ownerToken, 'POST', "/api/platform/roles/{$role['id']}/archive", ['reason' => 'Season over']);
        $this->assertResponseOk();
        $this->callAs($this->ownerToken, 'PATCH', "/api/platform/roles/{$role['id']}", [
            'name' => 'Renamed', 'version' => $this->detail($role['id'])['version'], 'reason' => 'x',
        ]);
        $this->assertResponseCode(400, 'archived roles are not edited');
        $this->callAs($this->adminToken, 'GET', '/api/roles');
        $this->assertNotContains('Seasonal Desk', array_column($this->responseJson()['roles'], 'name'));

        $this->callAs($this->ownerToken, 'POST', "/api/platform/roles/{$role['id']}/restore", ['reason' => 'Season again']);
        $this->assertResponseOk();
        $this->callAs($this->adminToken, 'GET', '/api/roles');
        $listed = array_column($this->responseJson()['roles'], null, 'name');
        $this->assertSame('custom', $listed['Seasonal Desk']['kind'], 'Managers see custom roles read-only');
        $types = array_map(fn($e) => $e->event_type, $this->events($role['id']));
        $this->assertSame([RoleEventsTable::ROLE_ARCHIVED, RoleEventsTable::ROLE_RESTORED], array_slice($types, -2));
    }

    public function testANewCatalogPermissionWaitsForAnExplicitDecision(): void
    {
        $role = $this->create(['name' => 'Front Office', 'copy_from' => $this->deskRoleId]);
        $definitions = new RoleDefinitions($this->connection);
        // As if a release added a permission to the Manager after this copy.
        $definitions->revoke('admin', [Permissions::FINANCE_ANALYTICS_VIEW], 'Test setup', 'Test');
        $definitions->grant('Test: a release grants it to Manager', 'Test');

        $review = $this->detail($role['id'])['review'];
        $this->assertSame([Permissions::FINANCE_ANALYTICS_VIEW], array_column($review, 'permission'));
        $this->assertSame(['Manager'], $review[0]['granted_to']);
        $this->assertNotContains(Permissions::FINANCE_ANALYTICS_VIEW, $this->marked($this->detail($role['id']), 'template'), 'never added by itself');

        $this->callAs($this->ownerToken, 'POST', "/api/platform/roles/{$role['id']}/review", [
            'permission' => Permissions::FINANCE_ANALYTICS_VIEW, 'decision' => 'decline', 'reason' => 'Not for the front office',
        ]);
        $this->assertResponseOk();
        $this->assertSame([], $this->responseJson()['role']['review'], 'a decision clears the flag');
        $last = array_slice($this->events($role['id']), -1)[0];
        $this->assertSame([RoleEventsTable::PERMISSION_DECLINED, 'Not for the front office'], [$last->event_type, $last->reason]);

        // A later release grants it again: it waits again; this time it is added.
        $definitions->revoke('admin', [Permissions::FINANCE_ANALYTICS_VIEW], 'Test setup', 'Test');
        $definitions->grant('Test: granted again', 'Test');
        $this->callAs($this->ownerToken, 'POST', "/api/platform/roles/{$role['id']}/review", [
            'permission' => Permissions::FINANCE_ANALYTICS_VIEW, 'decision' => 'add', 'reason' => 'Now needed',
        ]);
        $this->assertResponseOk();
        $this->assertSame([Permissions::FINANCE_ANALYTICS_VIEW], $this->responseJson()['role']['since_creation']['added']);
        $this->callAs($this->ownerToken, 'POST', "/api/platform/roles/{$role['id']}/review", [
            'permission' => Permissions::FINANCE_ANALYTICS_VIEW, 'decision' => 'add', 'reason' => 'Twice',
        ]);
        $this->assertResponseCode(400, 'nothing waiting any more');
    }

    public function testTheListShowsEveryRoleWithWhoHoldsIt(): void
    {
        $this->create(['name' => 'Listed Role', 'copy_from' => $this->adminRoleId]);
        $this->callAs($this->ownerToken, 'GET', '/api/platform/roles');
        $this->assertResponseOk();
        $roles = array_column($this->responseJson()['roles'], null, 'name');
        $this->assertSame('system', $roles['Manager']['kind']);
        $this->assertGreaterThanOrEqual(1, $roles['Manager']['memberships']['total']);
        $this->assertSame(['custom', 'Manager', 45], [
            $roles['Listed Role']['kind'], $roles['Listed Role']['copied_from']['name'], $roles['Listed Role']['permission_count'],
        ]);
        $this->assertArrayNotHasKey('Platform Owner', $roles, 'the history-only platform entry is not a property role');

        $this->callAs($this->ownerToken, 'GET', '/api/platform/role-history?role_id=' . $roles['Listed Role']['id']);
        $events = $this->responseJson()['events'];
        $this->assertCount(46, array_filter($events, fn($e) => $e['role_name'] === 'Listed Role'));
        $this->assertCount(46, $events, 'filtered to one role');
        $this->assertNotNull($events[0]['actor'], 'who made it');
    }
}
