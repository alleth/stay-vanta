<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Auth\Permissions;
use App\Event\MembershipImport;
use App\Model\Table\UsersTable;
use Cake\Database\Connection;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Build step 10b, part 1 (approved 2026-10-06): Permissions Phase 2. Access
 * comes from the person's membership (user × property × role) and the
 * role's grants in data, not from `users.role`; every membership is granted
 * or imported with a record; the import is idempotent and invents nothing.
 */
class MembershipsApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private string $adminToken;
    private string $deskToken;
    private string $deskEmail;
    private Connection $connection;

    /**
     * Accounts made outside makeUser(), removed in tearDown.
     *
     * @var list<int>
     */
    private array $looseUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Membership Inn');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "member-admin-$tag@example.test");
        $this->deskEmail = "member-desk-$tag@example.test";
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', $this->deskEmail);
        /** @var \Cake\Database\Connection $connection */
        $connection = $this->getTableLocator()->get('AccessEvents')->getConnection();
        $this->connection = $connection;
    }

    protected function tearDown(): void
    {
        if ($this->looseUserIds) {
            $this->connection->delete('access_events', ['subject_user_id IN' => $this->looseUserIds]);
            $this->getTableLocator()->get('PropertyMemberships')->deleteAll(['user_id IN' => $this->looseUserIds]);
            $this->getTableLocator()->get('Users')->deleteAll(['id IN' => $this->looseUserIds]);
        }
        $this->cleanupScenario();
        parent::tearDown();
    }

    /**
     * @return list<\Cake\Datasource\EntityInterface>
     */
    private function eventsOf(int $userId, string $type): array
    {
        return $this->getTableLocator()->get('AccessEvents')->find()
            ->where(['subject_user_id' => $userId, 'event_type' => $type])
            ->orderBy(['id' => 'ASC'])->all()->toList();
    }

    /**
     * An account inserted directly, as one made before step 10b: no membership.
     */
    private function legacyAccount(?int $propertyId, string $role, string $created): int
    {
        $users = $this->getTableLocator()->get('Users');
        $user = $users->saveOrFail($users->newEntity([
            'property_id' => $propertyId,
            'name' => 'Legacy ' . $role,
            'email' => 'member-legacy-' . uniqid() . '@example.test',
            'password' => 'secret123',
            'role' => $role,
            'is_active' => true,
        ]));
        $users->updateAll(['created' => new DateTime($created)], ['id' => $user->id]);
        $this->looseUserIds[] = (int)$user->id;

        return (int)$user->id;
    }

    public function testThePresetsHoldExactlyTheirCatalogGrants(): void
    {
        $roles = $this->getTableLocator()->get('Roles');
        foreach (['admin', 'receptionist'] as $code) {
            $held = $this->getTableLocator()->get('RolePermissions')->find()
                ->select(['permission'])
                ->where(['role_id' => $roles->idFor($code)])
                ->disableHydration()->all()->extract('permission')->toList();
            sort($held);
            $expected = Permissions::ROLE_GRANTS[$code];
            sort($expected);
            $this->assertSame($expected, $held, "$code's grants in data");
        }
    }

    public function testCreatingStaffGrantsAMembershipAndRecordsWhoGrantedIt(): void
    {
        $email = 'member-new-' . uniqid() . '@example.test';
        $this->callAs($this->adminToken, 'POST', '/api/users', [
            'name' => 'New Desk', 'email' => $email, 'password' => 'secret123', 'role' => 'receptionist',
        ]);
        $this->assertResponseCode(201);
        $userId = (int)$this->responseJson()['user']['id'];

        $membership = $this->getTableLocator()->get('PropertyMemberships')->find('active')
            ->contain(['Roles'])->where(['user_id' => $userId])->firstOrFail();
        $this->assertSame($this->propertyId, (int)$membership->property_id);
        $this->assertSame('receptionist', $membership->role->code);

        [$event] = $this->eventsOf($userId, 'membership_granted');
        $this->assertSame($this->userIdFor($this->adminToken), (int)$event->actor_id);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame((int)$membership->id, (int)$event->membership_id);
        $this->assertSame((int)$membership->role_id, (int)$event->role_id);
        $this->assertSame($this->propertyId, (int)$event->property_id);
        $this->assertSame(
            $this->eventsOf($userId, 'account_created')[0]->correlation_id,
            $event->correlation_id,
            'one request, one correlation',
        );
    }

    public function testAccessComesFromTheMembershipNotTheRoleColumn(): void
    {
        $deskId = $this->userIdFor($this->deskToken);
        $this->getTableLocator()->get('Users')->updateAll(['role' => 'admin'], ['id' => $deskId]);

        $this->callAs($this->deskToken, 'GET', '/api/auth/me');
        $this->assertResponseOk();
        $user = $this->responseJson()['user'];
        $this->assertSame('receptionist', $user['role']);
        $this->assertSame($this->propertyId, (int)$user['property_id']);
        $this->assertFalse($user['platform']);
        $this->assertNotContains(Permissions::OPERATIONS_STAFF_VIEW, $user['permissions']);

        $this->callAs($this->deskToken, 'GET', '/api/operations/activity');
        $this->assertResponseCode(403);
    }

    public function testSigningInIsRefusedOnceTheMembershipHasEnded(): void
    {
        $deskId = $this->userIdFor($this->deskToken);
        $this->getTableLocator()->get('PropertyMemberships')
            ->updateAll(['ended_at' => new DateTime()], ['user_id' => $deskId]);

        $this->callAs(null, 'POST', '/api/auth/login', ['email' => $this->deskEmail, 'password' => 'secret123']);
        $this->assertResponseCode(401);
        $this->assertSame('Invalid credentials.', $this->responseJson()['error']);

        [$event] = $this->eventsOf($deskId, 'sign_in_refused');
        $this->assertEquals(['because' => 'no_membership'], $event->changes);
        $this->assertSame($deskId, (int)$event->actor_id, 'the right password proves who tried');
        $this->assertSame([], $this->eventsOf($deskId, 'signed_in'));
    }

    /**
     * G8 (L4) removed the one-release fallback: an account that never had a
     * membership gets nothing from its users.role / users.property_id.
     */
    public function testAnAccountThatNeverHadAMembershipHasNoAccess(): void
    {
        $userId = $this->legacyAccount($this->propertyId, 'receptionist', '2026-01-15 03:00:00');
        [$token, $digest] = UsersTable::issueToken();
        $this->getTableLocator()->get('Users')->updateAll(
            ['api_token' => $digest, 'token_expires' => new DateTime('+1 day')],
            ['id' => $userId],
        );

        $this->callAs($token, 'GET', '/api/reservations');
        $this->assertContains($this->_response->getStatusCode(), [401, 403]);
        $this->callAs($token, 'GET', '/api/auth/me');
        $permissions = $this->_response->getStatusCode() === 200
            ? $this->responseJson()['user']['permissions']
            : [];
        $this->assertSame([], $permissions, 'no role-based permissions without a membership');
    }

    public function testTheImportGivesEachAccountItsMembershipOnceAndInventsNothing(): void
    {
        $userId = $this->legacyAccount($this->propertyId, 'admin', '2026-02-01 08:30:00');
        $import = new MembershipImport($this->connection);

        $first = $import->run($this->propertyId);
        $this->assertSame(1, $first['memberships']);

        $membership = $this->getTableLocator()->get('PropertyMemberships')->find()
            ->contain(['Roles'])->where(['user_id' => $userId])->firstOrFail();
        $this->assertSame('admin', $membership->role->code);
        $this->assertSame($this->propertyId, (int)$membership->property_id);
        $this->assertNull($membership->ended_at);
        $this->assertSame(
            '2026-02-01 08:30:00',
            $membership->started_at->format('Y-m-d H:i:s'),
            'started when the account was made: a recorded fact',
        );

        [$event] = $this->eventsOf($userId, 'membership_imported');
        $this->assertNull($event->actor_id, 'nobody is known to have granted it');
        $this->assertNull($event->actor_role);
        $this->assertNull($event->reason);
        $this->assertSame('import', $event->source);
        $this->assertSame('import-users-' . $userId, $event->correlation_id);
        $this->assertSame((int)$membership->id, (int)$event->membership_id);

        $second = $import->run($this->propertyId);
        $this->assertSame(0, $second['memberships'], 'a second run adds nothing');
        $this->assertCount(1, $this->eventsOf($userId, 'membership_imported'));

        $check = $import->check($this->propertyId)[$this->propertyId];
        $this->assertTrue($check['complete']);
        $this->assertSame(0, $check['without_membership']);
    }

    public function testTheImportReportsAnAccountWhosePropertyDoesNotExist(): void
    {
        $missing = 999000 + random_int(0, 999);
        $userId = $this->legacyAccount($missing, 'receptionist', '2026-03-01 00:00:00');

        $result = (new MembershipImport($this->connection))->run($missing);

        $this->assertSame(['memberships' => 0, 'platform' => 0, 'skipped' => 1], $result);
        $this->assertFalse($this->getTableLocator()->get('PropertyMemberships')->exists(['user_id' => $userId]));
        $this->assertSame([], $this->eventsOf($userId, 'membership_imported'));
    }

    public function testOnlyThePlatformOwnerChangesARoleWithAReasonAndItEndsTheSession(): void
    {
        $deskId = $this->userIdFor($this->deskToken);
        $ownerToken = $this->makeUser(null, 'owner', 'member-owner-' . uniqid() . '@example.test');

        $this->callAs($this->adminToken, 'PATCH', "/api/users/$deskId", ['role' => 'admin', 'reason' => 'Promotion']);
        $this->assertResponseCode(403, 'a Manager never grants the Manager role');

        $this->callAs($ownerToken, 'PATCH', "/api/users/$deskId", ['role' => 'admin']);
        $this->assertResponseCode(400, 'a role change needs a reason');
        $this->assertSame([], $this->eventsOf($deskId, 'membership_role_changed'));

        $this->callAs($ownerToken, 'PATCH', "/api/users/$deskId", ['role' => 'admin', 'reason' => 'Promoted to Manager']);
        $this->assertResponseOk();

        $membership = $this->getTableLocator()->get('PropertyMemberships')->find('active')
            ->contain(['Roles'])->where(['user_id' => $deskId])->firstOrFail();
        $this->assertSame('admin', $membership->role->code);
        $this->assertSame('admin', $this->getTableLocator()->get('Users')->get($deskId)->role, 'still written');

        [$event] = $this->eventsOf($deskId, 'membership_role_changed');
        $this->assertSame($this->userIdFor($ownerToken), (int)$event->actor_id);
        $this->assertSame('owner', $event->actor_role);
        $this->assertSame('Promoted to Manager', $event->reason);
        $this->assertEquals(['role' => ['before' => 'receptionist', 'after' => 'admin']], $event->changes);
        $this->assertSame((int)$membership->id, (int)$event->membership_id);
        [$ended] = $this->eventsOf($deskId, 'session_ended');
        $this->assertEquals(['because' => 'membership_role_changed'], $ended->changes);

        $this->callAs($this->deskToken, 'GET', '/api/auth/me');
        $this->assertResponseCode(401, 'a new role means signing in again (A8)');

        $this->callAs($ownerToken, 'PATCH', "/api/users/$deskId", ['role' => 'admin', 'reason' => 'Again']);
        $this->assertResponseCode(400, 'nothing to change');
        $this->assertCount(1, $this->eventsOf($deskId, 'membership_role_changed'));
    }

    public function testThePlatformOwnerIsReadFromThePlatformFlag(): void
    {
        $ownerToken = $this->makeUser(null, 'owner', 'member-owner-' . uniqid() . '@example.test');

        $this->callAs($ownerToken, 'GET', '/api/auth/me');
        $this->assertResponseOk();
        $user = $this->responseJson()['user'];
        $this->assertTrue($user['platform']);
        $this->assertNull($user['property_id']);
        $this->assertContains(Permissions::PLATFORM_DASHBOARD_VIEW, $user['permissions']);
    }
}
