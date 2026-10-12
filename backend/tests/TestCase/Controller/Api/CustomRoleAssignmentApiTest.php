<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Auth\Permissions;
use App\Auth\RoleDefinitions;
use App\Event\EventContext;
use App\Model\BusinessTime;
use App\Model\Table\AccessEventsTable;
use Cake\Database\Connection;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Permissions Phase 3c-2 (approved 2026-10-12 as C-D1–C-D7): custom roles
 * can be held. Only the Platform Owner gives one; a role change ends the
 * membership and starts a new one; users.role is empty for a custom-role
 * holder; every response carries the permissions version, and a role edit
 * refreshes screens without signing anyone out, while a role change, a
 * deactivation and a password reset still do.
 */
class CustomRoleAssignmentApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private Connection $connection;
    private int $propertyId;
    private string $ownerToken;
    private string $adminToken;
    private string $deskToken;
    private int $deskRoleId;
    private string $tag;
    /**
     * The custom role these tests give.
     *
     * @var array<string, mixed>
     */
    private array $auditor;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var \Cake\Database\Connection $connection */
        $connection = $this->getTableLocator()->get('Roles')->getConnection();
        $this->connection = $connection;
        $this->tag = uniqid();
        $this->propertyId = $this->createProperty('Assignment Inn');
        $this->ownerToken = $this->makeUser(null, 'owner', "as-owner-{$this->tag}@example.test");
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "as-admin-{$this->tag}@example.test");
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', "as-desk-{$this->tag}@example.test");
        $this->deskRoleId = (int)$this->getTableLocator()->get('Roles')->idFor('receptionist');
        $this->auditor = $this->customRole('Night Auditor', [
            Permissions::FRONT_DESK_RESERVATION_VIEW,
            Permissions::GUESTS_GUEST_VIEW,
            Permissions::GUESTS_GUEST_MANAGE,
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        $custom = $this->connection->execute("SELECT id FROM roles WHERE kind = 'custom'")->fetchAll('assoc');
        foreach (array_column($custom, 'id') as $id) {
            $this->connection->delete('role_events', ['role_id' => $id]);
            $this->connection->delete('role_permissions', ['role_id' => $id]);
            $this->connection->delete('roles', ['id' => $id]);
        }
        parent::tearDown();
    }

    /**
     * @param list<string> $permissions
     * @return array<string, mixed> The role's detail as the catalog answers it.
     */
    private function customRole(string $name, array $permissions): array
    {
        $context = new EventContext($this->userIdFor($this->ownerToken), 'owner', null, 'test-' . uniqid(), reason: 'Test role');
        $role = (new RoleDefinitions($this->connection))->createCustom($context, $name, 'For tests', null, $permissions);
        $this->callAs($this->ownerToken, 'GET', "/api/platform/roles/{$role->id}");

        return $this->responseJson()['role'];
    }

    /**
     * Create an account with a role as the Platform Owner, and sign it in.
     *
     * @return array{0: int, 1: string} The user id and a fresh token.
     */
    private function holder(int $roleId, string $who): array
    {
        $email = "as-$who-{$this->tag}@example.test";
        $this->callAs($this->ownerToken, 'POST', '/api/users?property_id=' . $this->propertyId, [
            'name' => ucfirst($who), 'email' => $email, 'password' => 'secret123', 'role_id' => $roleId,
            'property_id' => $this->propertyId,
        ]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $id = (int)$this->responseJson()['user']['id'];
        $this->callAs(null, 'POST', '/api/auth/login', ['email' => $email, 'password' => 'secret123']);
        $this->assertResponseOk();

        return [$id, (string)$this->responseJson()['token']];
    }

    /**
     * @return list<\Cake\Datasource\EntityInterface>
     */
    private function memberships(int $userId): array
    {
        return $this->getTableLocator()->get('PropertyMemberships')->find()
            ->where(['user_id' => $userId])->orderBy(['id' => 'ASC'])->all()->toList();
    }

    private function accessEventCount(int $userId): int
    {
        return $this->getTableLocator()->get('AccessEvents')->find()->where(['subject_user_id' => $userId])->count();
    }

    public function testARoleChangeEndsOneMembershipAndStartsAnother(): void
    {
        $deskId = $this->userIdFor($this->deskToken);
        $this->callAs($this->ownerToken, 'PATCH', "/api/users/$deskId", [
            'role_id' => $this->auditor['id'], 'reason' => 'Covers the night audit',
        ]);
        $this->assertResponseOk((string)$this->_response->getBody());

        [$old, $new] = $this->memberships($deskId);
        $this->assertSame($this->deskRoleId, (int)$old->role_id, 'the old membership keeps its role');
        $this->assertNotNull($old->ended_at);
        $this->assertNull($new->ended_at);
        $this->assertSame($this->auditor['id'], (int)$new->role_id);
        $this->assertEquals($old->ended_at, $new->started_at, 'no gap, no overlap');
        $this->assertNull($this->getTableLocator()->get('Users')->get($deskId)->role, 'C-D2: no built-in the person does not hold');

        $event = $this->getTableLocator()->get('AccessEvents')->find()
            ->where(['subject_user_id' => $deskId, 'event_type' => AccessEventsTable::MEMBERSHIP_ROLE_CHANGED])->firstOrFail();
        $this->assertSame('Covers the night audit', $event->reason);
        $this->assertSame((int)$new->id, (int)$event->membership_id);
        $this->assertEquals(['before' => (int)$old->id, 'after' => (int)$new->id], $event->changes['membership']);
        $this->assertEquals(['id' => $this->deskRoleId, 'code' => 'receptionist', 'name' => 'Front Desk Staff'], $event->changes['role']['before']);
        $this->assertEquals(
            ['id' => $this->auditor['id'], 'code' => $this->auditor['code'], 'name' => 'Night Auditor'],
            $event->changes['role']['after'],
        );
        $this->assertSame('Night Auditor', $event->snapshot['role_name']);
        $this->assertSame(
            1,
            $this->getTableLocator()->get('AccessEvents')->find()
                ->where(['subject_user_id' => $deskId, 'event_type' => AccessEventsTable::SESSION_ENDED])->count(),
            'a role change signs the person out',
        );
        $this->callAs($this->deskToken, 'GET', '/api/auth/me');
        $this->assertResponseCode(401);

        // The same request again changes nothing and records nothing.
        $before = $this->accessEventCount($deskId);
        $this->callAs($this->ownerToken, 'PATCH', "/api/users/$deskId", ['role_id' => $this->auditor['id'], 'reason' => 'again']);
        $this->assertResponseCode(400);
        $this->assertSame($before, $this->accessEventCount($deskId));
        $this->assertCount(2, $this->memberships($deskId));

        // Back to a built-in: a third membership, users.role written again.
        $this->callAs($this->ownerToken, 'PATCH', "/api/users/$deskId", ['role_id' => $this->deskRoleId, 'reason' => 'Audit moved']);
        $this->assertResponseOk();
        $this->assertCount(3, $this->memberships($deskId));
        $this->assertSame(1, $this->getTableLocator()->get('PropertyMemberships')->find('active')->where(['user_id' => $deskId])->count());
        $this->assertSame('receptionist', $this->getTableLocator()->get('Users')->get($deskId)->role);

        // The timeline, newest first, with who and why.
        // The Manager reads access history (the Platform Owner holds no staff.access_history.view).
        $this->callAs($this->adminToken, 'GET', "/api/users/$deskId/access-history");
        $held = $this->responseJson()['roles_held'];
        $this->assertSame(['Front Desk Staff', 'Night Auditor', 'Front Desk Staff'], array_column($held, 'role'));
        $this->assertSame('Audit moved', $held[0]['reason']);
        $this->assertSame('Covers the night audit', $held[1]['reason']);
        $this->assertNotNull($held[1]['given_by']);
        $this->assertNotNull($held[1]['ended_at']);
        $this->assertNull($held[0]['ended_at']);
    }

    public function testOnlyThePlatformOwnerGivesACustomRole(): void
    {
        $refusal = 'Only the Platform Owner can give a custom role.';
        $this->callAs($this->adminToken, 'POST', '/api/users', [
            'name' => 'Auditor', 'email' => "as-x-{$this->tag}@example.test", 'password' => 'secret123',
            'role_id' => $this->auditor['id'],
        ]);
        $this->assertResponseCode(403);
        $this->assertSame($refusal, $this->responseJson()['message']);
        $deskId = $this->userIdFor($this->deskToken);
        $this->callAs($this->adminToken, 'PATCH', "/api/users/$deskId", ['role_id' => $this->auditor['id'], 'reason' => 'x']);
        $this->assertResponseCode(403);

        // A Manager's picker never offers one; the Platform Owner's does, after the built-ins.
        $this->callAs($this->adminToken, 'GET', '/api/users');
        $this->assertSame(['system'], array_values(array_unique(array_column($this->responseJson()['assignable_roles'], 'kind'))));
        $this->callAs($this->ownerToken, 'GET', '/api/users?property_id=' . $this->propertyId);
        $roles = $this->responseJson()['assignable_roles'];
        $last = end($roles);
        $this->assertSame(['Night Auditor', 'custom', 3, 0], [$last['name'], $last['kind'], $last['permission_count'], $last['holders']]);
        $this->assertSame('system', $roles[0]['kind']);

        // The old way of naming a role (a code) still works for one release (C2-D1).
        $this->callAs($this->ownerToken, 'PATCH', "/api/users/$deskId", ['role' => $this->auditor['code'], 'reason' => 'By code']);
        $this->assertResponseOk();
    }

    public function testAnAccountCanStartWithACustomRole(): void
    {
        [$id] = $this->holder($this->auditor['id'], 'auditor');
        $this->assertNull($this->getTableLocator()->get('Users')->get($id)->role);
        $this->assertSame([$this->auditor['id']], array_map(fn($m) => (int)$m->role_id, $this->memberships($id)));
        $events = $this->getTableLocator()->get('AccessEvents')->find()
            ->where(['subject_user_id' => $id, 'event_type IN' => [AccessEventsTable::ACCOUNT_CREATED, AccessEventsTable::MEMBERSHIP_GRANTED]])
            ->orderBy(['id' => 'ASC'])->all()->toList();
        $this->assertSame('Night Auditor', $events[0]->changes['after']['role']['name']);
        $this->assertSame($this->auditor['id'], $events[1]->changes['after']['role']['id']);
    }

    public function testAHolderDoesExactlyWhatTheRoleGrantsAndIsRecordedByIt(): void
    {
        [$id, $token] = $this->holder($this->auditor['id'], 'auditor');
        $this->callAs($token, 'GET', '/api/auth/me');
        $me = $this->responseJson()['user'];
        $this->assertSame([$this->auditor['code'], 'Night Auditor'], [$me['role'], $me['role_name']]);
        $this->assertSame($me['permissions_version'], $this->_response->getHeaderLine('X-Permissions-Version'));

        $this->callAs($token, 'GET', '/api/reservations');
        $this->assertResponseOk();
        $this->callAs($token, 'GET', '/api/inventory-items');
        $this->assertResponseCode(403);
        $this->assertNotSame('', $this->_response->getHeaderLine('X-Permissions-Version'), 'refusals carry it too');

        // Its actions are recorded under its code, in full.
        $this->callAs($token, 'POST', '/api/guests', [
            'full_name' => 'Night Guest ' . $this->tag, 'guest_type' => 'local', 'email' => "ng-{$this->tag}@example.test",
        ]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $guestEvent = $this->getTableLocator()->get('GuestEvents')->find()->where(['actor_id' => $id])->firstOrFail();
        $this->assertSame($this->auditor['code'], $guestEvent->actor_role);
        $signIn = $this->getTableLocator()->get('AccessEvents')->find()
            ->where(['subject_user_id' => $id, 'event_type' => AccessEventsTable::SIGNED_IN])->firstOrFail();
        $this->assertSame($this->auditor['code'], $signIn->actor_role);

        // History screens get the role's name, not its code.
        // The Manager reads access history (the Platform Owner holds no staff.access_history.view).
        $this->callAs($this->adminToken, 'GET', "/api/users/$id/access-history");
        $this->assertSame('Night Auditor', $this->responseJson()['events'][0]['actor_role_name']);
    }

    public function testARoleEditRefreshesScreensWithoutSigningAnyoneOut(): void
    {
        [, $token] = $this->holder($this->auditor['id'], 'auditor');
        $this->callAs($token, 'GET', '/api/reservations');
        $this->assertResponseOk();
        $before = $this->_response->getHeaderLine('X-Permissions-Version');
        $this->callAs($token, 'GET', '/api/guests');
        $this->assertSame($before, $this->_response->getHeaderLine('X-Permissions-Version'), 'stable while nothing changes');

        $this->callAs($this->ownerToken, 'PATCH', "/api/platform/roles/{$this->auditor['id']}", [
            'permissions' => [Permissions::GUESTS_GUEST_VIEW, Permissions::GUESTS_GUEST_MANAGE],
            'version' => $this->auditor['version'], 'reason' => 'No reservations at night',
        ]);
        $this->assertResponseOk((string)$this->_response->getBody());
        $edited = $this->responseJson()['role'];

        $this->callAs($token, 'GET', '/api/reservations');
        $this->assertResponseCode(403, 'a removed permission is refused at once');
        $after = $this->_response->getHeaderLine('X-Permissions-Version');
        $this->assertNotSame($before, $after);
        $this->callAs($token, 'GET', '/api/guests');
        $this->assertResponseOk('still signed in, keeping what the role grants');

        // A rename changes no access, so no version.
        $this->callAs($this->ownerToken, 'PATCH', "/api/platform/roles/{$this->auditor['id']}", [
            'name' => 'Night Desk', 'version' => $edited['version'], 'reason' => 'Clearer name',
        ]);
        $this->assertResponseOk();
        $this->callAs($token, 'GET', '/api/guests');
        $this->assertSame($after, $this->_response->getHeaderLine('X-Permissions-Version'));
        $this->callAs($token, 'GET', '/api/auth/me');
        $this->assertSame('Night Desk', $this->responseJson()['user']['role_name']);
    }

    public function testDeactivationAndPasswordResetStillSignAHolderOut(): void
    {
        [$id, $token] = $this->holder($this->auditor['id'], 'auditor');
        $this->callAs($this->ownerToken, 'POST', "/api/users/$id/reset-password", ['password' => 'another123', 'reason' => 'Lost it']);
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->callAs($token, 'GET', '/api/auth/me');
        $this->assertResponseCode(401);

        $this->callAs(null, 'POST', '/api/auth/login', ['email' => "as-auditor-{$this->tag}@example.test", 'password' => 'another123']);
        $token = (string)$this->responseJson()['token'];
        $this->callAs($this->ownerToken, 'PATCH', "/api/users/$id", ['is_active' => false, 'reason' => 'Left']);
        $this->assertResponseOk();
        $this->callAs($token, 'GET', '/api/auth/me');
        $this->assertResponseCode(401);
    }

    public function testAHeldRoleIsArchivedOnlyAfterItsHoldersMove(): void
    {
        [$id] = $this->holder($this->auditor['id'], 'auditor');
        $this->callAs($this->ownerToken, 'POST', "/api/platform/roles/{$this->auditor['id']}/archive", ['reason' => 'Retired']);
        $this->assertResponseCode(400);
        $this->assertStringContainsString('Reassign the 1 person', $this->responseJson()['message']);

        $this->callAs($this->ownerToken, 'PATCH', "/api/users/$id", ['role_id' => $this->deskRoleId, 'reason' => 'Role retired']);
        $this->assertResponseOk();
        $this->callAs($this->ownerToken, 'POST', "/api/platform/roles/{$this->auditor['id']}/archive", ['reason' => 'Retired']);
        $this->assertResponseOk();

        // An archived role is no longer offered or accepted.
        $this->callAs($this->ownerToken, 'PATCH', "/api/users/$id", ['role_id' => $this->auditor['id'], 'reason' => 'x']);
        $this->assertResponseCode(400);
        $this->callAs($this->ownerToken, 'GET', '/api/users?property_id=' . $this->propertyId);
        $this->assertNotContains($this->auditor['id'], array_column($this->responseJson()['assignable_roles'], 'id'));
    }

    public function testSubscribersListsManagersByMembership(): void
    {
        $this->callAs($this->ownerToken, 'GET', '/api/properties');
        $property = array_values(array_filter($this->responseJson()['properties'], fn($p) => $p['id'] === $this->propertyId))[0];
        $this->assertSame([$this->userIdFor($this->adminToken)], array_column($property['users'], 'id'));

        // A custom role that manages staff counts; one that doesn't, doesn't.
        $lead = $this->customRole('Duty Lead', [Permissions::STAFF_ACCOUNT_VIEW, Permissions::STAFF_ACCOUNT_MANAGE]);
        $deskId = $this->userIdFor($this->deskToken);
        $this->callAs($this->ownerToken, 'PATCH', "/api/users/$deskId", ['role_id' => $lead['id'], 'reason' => 'Leads the shift']);
        $this->assertResponseOk();
        $this->callAs($this->ownerToken, 'GET', '/api/properties');
        $property = array_values(array_filter($this->responseJson()['properties'], fn($p) => $p['id'] === $this->propertyId))[0];
        $this->assertEqualsCanonicalizing([$this->userIdFor($this->adminToken), $deskId], array_column($property['users'], 'id'));
    }

    public function testReadOnlyNarrowsACustomRoleByPermission(): void
    {
        [, $token] = $this->holder($this->auditor['id'], 'auditor');
        $this->getTableLocator()->get('Properties')->updateAll(
            ['subscription_expires_at' => BusinessTime::today()->subDays(10)->format('Y-m-d')],
            ['id' => $this->propertyId],
        );
        $this->usePhase('read_only');
        $this->callAs($token, 'GET', '/api/auth/me');
        $this->assertEqualsCanonicalizing(
            [Permissions::FRONT_DESK_RESERVATION_VIEW, Permissions::GUESTS_GUEST_VIEW],
            $this->responseJson()['user']['permissions'],
        );
    }
}
