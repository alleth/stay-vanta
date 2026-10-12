<?php

declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Auth\Permissions;
use Cake\Database\Connection;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Permissions Phase 3a (approved 2026-10-12 as PH-D1–PH-D7): who may manage
 * whom is decided by permissions (App\Auth\StaffHierarchy), never by role
 * codes. A Manager manages roles below their own (a strict subset of their
 * permissions), never a peer; role names and counts come from data, so a role
 * the code doesn't know is handled like any other.
 */
class StaffHierarchyApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private string $adminToken;
    private string $peerToken;
    private string $deskToken;
    private string $ownerToken;
    private Connection $connection;
    /** @var list<int> Stand-in roles inserted by a test (scaffolding, removed after). */
    private array $roleIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        /** @var \Cake\Database\Connection $connection */
        $connection = $this->getTableLocator()->get('Roles')->getConnection();
        $this->connection = $connection;
        $this->propertyId = $this->createProperty('Hierarchy Inn');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "h-admin-$tag@example.test");
        $this->peerToken = $this->makeUser($this->propertyId, 'admin', "h-peer-$tag@example.test");
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', "h-desk-$tag@example.test");
        $this->ownerToken = $this->makeUser(null, 'owner', "h-owner-$tag@example.test");
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        foreach ($this->roleIds as $id) {
            $this->connection->delete('role_permissions', ['role_id' => $id]);
            $this->connection->delete('roles', ['id' => $id]);
        }
        parent::tearDown();
    }

    /**
     * A role the code doesn't know, as Phase 3b will let the Platform Owner
     * create: inserted directly (test scaffolding), with the given grants.
     *
     * @param list<string> $permissions
     */
    private function standInRole(string $name, array $permissions): int
    {
        $this->connection->insert('roles', ['code' => 'test-' . uniqid(), 'name' => $name, 'scope' => 'property']);
        $id = (int)$this->connection->getDriver()->lastInsertId('roles');
        foreach ($permissions as $permission) {
            $this->connection->insert('role_permissions', ['role_id' => $id, 'permission' => $permission]);
        }
        $this->roleIds[] = $id;

        return $id;
    }

    private function giveRole(string $token, int $roleId): void
    {
        $this->getTableLocator()->get('PropertyMemberships')
            ->updateAll(['role_id' => $roleId], ['user_id' => $this->userIdFor($token)]);
    }

    /**
     * @return array<int, array<string, mixed>> The staff list as the caller sees it, by user id.
     */
    private function staffAs(string $token): array
    {
        $this->callAs($token, 'GET', '/api/users');
        $this->assertResponseOk();

        return array_column($this->responseJson()['users'], null, 'id');
    }

    public function testAManagerManagesFrontDeskStaffButNeverAPeer(): void
    {
        $peerId = $this->userIdFor($this->peerToken);
        $deskId = $this->userIdFor($this->deskToken);

        $staff = $this->staffAs($this->adminToken);
        $this->assertTrue($staff[$deskId]['manageable']);
        $this->assertFalse($staff[$peerId]['manageable'], 'the same permissions: a peer');
        $this->assertSame('Front Desk Staff', $staff[$deskId]['role_name']);
        $this->assertSame(
            [['receptionist', 'Front Desk Staff', 'system']],
            array_map(fn($r) => [$r['code'], $r['name'], $r['kind']], $this->responseJson()['assignable_roles']),
        );

        // Before Phase 3a a Manager could switch a fellow Manager off through the API.
        $this->callAs($this->adminToken, 'PATCH', "/api/users/$peerId", ['is_active' => false, 'reason' => 'x']);
        $this->assertResponseCode(403);
        $this->assertTrue((bool)$this->getTableLocator()->get('Users')->get($peerId)->is_active);
        $this->callAs($this->adminToken, 'PATCH', "/api/users/$peerId", ['name' => 'Renamed']);
        $this->assertResponseCode(403);
        $this->callAs($this->adminToken, 'GET', "/api/users/$peerId/access-history");
        $this->assertResponseCode(403);
        $this->callAs($this->adminToken, 'POST', "/api/users/$peerId/reset-password", ['password' => 'hijacked1']);
        $this->assertResponseCode(403);

        // What stays allowed: the staff below, and your own account.
        $this->callAs($this->adminToken, 'PATCH', "/api/users/$deskId", ['is_active' => false, 'reason' => 'Left']);
        $this->assertResponseOk();
        $this->callAs($this->adminToken, 'GET', "/api/users/$deskId/access-history");
        $this->assertResponseOk();
        $adminId = $this->userIdFor($this->adminToken);
        $this->callAs($this->adminToken, 'PATCH', "/api/users/$adminId", ['name' => 'My New Name']);
        $this->assertResponseOk();
        $this->callAs($this->adminToken, 'GET', "/api/users/$adminId/access-history");
        $this->assertResponseOk();
    }

    public function testThePlatformOwnerManagesEveryoneAndChangesRolesByData(): void
    {
        $peerId = $this->userIdFor($this->peerToken);
        $staff = $this->staffAs($this->ownerToken);
        $this->assertTrue($staff[$peerId]['manageable']);
        $this->assertSame(['admin', 'receptionist'], array_column($this->responseJson()['assignable_roles'], 'code'));

        $this->callAs($this->ownerToken, 'PATCH', "/api/users/$peerId", ['role' => 'not-a-role', 'reason' => 'x']);
        $this->assertResponseCode(400);
        $this->callAs($this->ownerToken, 'PATCH', "/api/users/$peerId", ['role' => 'receptionist', 'reason' => 'Moved']);
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->assertSame('receptionist', $this->staffAs($this->ownerToken)[$peerId]['role']);
    }

    public function testARoleTheCodeDoesNotKnowIsJudgedByItsPermissions(): void
    {
        $deskId = $this->userIdFor($this->deskToken);
        $peerId = $this->userIdFor($this->peerToken);
        // Below the Manager: a subset of Front Desk's permissions.
        $auditor = $this->standInRole('Night Auditor', [
            Permissions::FINANCE_INVOICE_VIEW, Permissions::FRONT_DESK_RESERVATION_VIEW,
        ]);
        $this->giveRole($this->deskToken, $auditor);
        // Level with the Manager: every Manager permission under another name.
        $deputy = $this->standInRole('Deputy Manager', Permissions::ROLE_GRANTS['admin']);
        $this->giveRole($this->peerToken, $deputy);

        $staff = $this->staffAs($this->adminToken);
        $this->assertSame('Night Auditor', $staff[$deskId]['role_name'], 'the name comes from the role');
        $this->assertTrue($staff[$deskId]['manageable'], 'below the Manager');
        $this->assertSame('Deputy Manager', $staff[$peerId]['role_name']);
        $this->assertFalse($staff[$peerId]['manageable'], 'level with the Manager: a peer, whatever its name');
        $this->assertContains('Night Auditor', array_column($this->responseJson()['assignable_roles'], 'name'));
        $this->assertNotContains('Deputy Manager', array_column($this->responseJson()['assignable_roles'], 'name'));

        $this->callAs($this->adminToken, 'GET', '/api/operations/today');
        $members = array_column($this->responseJson()['operations']['staff']['members'], null, 'id');
        $this->assertSame(['Night Auditor', false], [$members[$deskId]['role_name'], $members[$deskId]['manages_staff']]);
        $this->assertSame(['Deputy Manager', true], [$members[$peerId]['role_name'], $members[$peerId]['manages_staff']]);
    }

    public function testRoleNamesComeFromTheRoleAtSignIn(): void
    {
        $this->callAs($this->adminToken, 'GET', '/api/auth/me');
        $this->assertSame(['admin', 'Manager'], [
            $this->responseJson()['user']['role'], $this->responseJson()['user']['role_name'],
        ]);
    }
}
