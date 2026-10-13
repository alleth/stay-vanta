<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Auth\LegacyUserColumns;
use App\Auth\RoleDefinitions;
use App\Event\AccessBackfill;
use App\Event\MembershipImport;
use App\Model\Table\AccessEventsTable;
use Cake\Cache\Cache;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use LogicException;

/**
 * Phase 3d-1 (approved 2026-10-13 as 3D-D1–D6): nothing reads users.role or
 * users.property_id any more. A person's property is their membership's for
 * the access ledger, for who a Manager may open, for the staff list and for
 * the Operations Staff card. Each test makes the column say the wrong hotel,
 * which can't happen today, to prove it isn't read.
 */
class LegacyUserColumnsApiTest extends TestCase
{
    use ApiScenarioTrait;
    use ConsoleIntegrationTestTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $innId;
    private int $resortId;
    private string $innAdmin;
    private string $resortAdmin;
    private string $deskToken;
    private int $deskId;
    private string $deskEmail;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->innId = $this->createProperty('Legacy Inn');
        $this->resortId = $this->createProperty('Legacy Resort');
        $this->innAdmin = $this->makeUser($this->innId, 'admin', "lg-inn-admin-$tag@example.test");
        $this->resortAdmin = $this->makeUser($this->resortId, 'admin', "lg-resort-admin-$tag@example.test");
        $this->deskEmail = "lg-desk-$tag@example.test";
        $this->deskToken = $this->makeUser($this->innId, 'receptionist', $this->deskEmail);
        $this->deskId = $this->userIdFor($this->deskToken);
        // The column now says the resort; the membership says the inn.
        $this->getTableLocator()->get('Users')->getConnection()
            ->update('users', ['property_id' => $this->resortId], ['id' => $this->deskId]);
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    /**
     * @return list<\Cake\Datasource\EntityInterface>
     */
    private function accessEvents(int $userId, string $type): array
    {
        return $this->getTableLocator()->get('AccessEvents')->find()
            ->where(['subject_user_id' => $userId, 'event_type' => $type])->orderBy(['id' => 'ASC'])->all()->toList();
    }

    public function testAccessEventsTakeTheMembershipsProperty(): void
    {
        $this->callAs(null, 'POST', '/api/auth/login', ['email' => $this->deskEmail, 'password' => 'secret123']);
        $this->assertResponseOk();
        $this->callAs(null, 'POST', '/api/auth/login', ['email' => $this->deskEmail, 'password' => 'wrong-one']);
        $this->callAs($this->innAdmin, 'PATCH', "/api/users/{$this->deskId}", ['name' => 'Renamed Desk']);
        $this->assertResponseOk((string)$this->_response->getBody());

        foreach ([AccessEventsTable::SIGNED_IN, AccessEventsTable::SIGN_IN_FAILED, AccessEventsTable::ACCOUNT_RENAMED] as $type) {
            [$event] = $this->accessEvents($this->deskId, $type);
            $this->assertSame($this->innId, (int)$event->property_id, "$type: the membership's hotel, not the column's");
            $this->assertSame(AccessEventsTable::SCOPE_PROPERTY, $event->scope);
        }
    }

    public function testAnAccountMadeThroughTheApiIsRecordedAtItsProperty(): void
    {
        $email = 'lg-new-' . uniqid() . '@example.test';
        $this->callAs($this->innAdmin, 'POST', '/api/users', [
            'name' => 'New Desk', 'email' => $email, 'password' => 'secret123', 'role' => 'receptionist',
        ]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $id = (int)$this->responseJson()['user']['id'];
        [$created] = $this->accessEvents($id, AccessEventsTable::ACCOUNT_CREATED);
        [$granted] = $this->accessEvents($id, AccessEventsTable::MEMBERSHIP_GRANTED);
        $this->assertSame([$this->innId, $this->innId], [(int)$created->property_id, (int)$granted->property_id]);
        $this->assertSame($this->innId, $created->changes['after']['property_id']);
    }

    public function testAPersonWithNoMembershipIsRecordedAtThePlatform(): void
    {
        // Possible only for accounts from before memberships (the staging orphans).
        $this->getTableLocator()->get('Users')->getConnection()
            ->delete('property_memberships', ['user_id' => $this->deskId]);
        $this->callAs(null, 'POST', '/api/auth/login', ['email' => $this->deskEmail, 'password' => 'wrong-one']);
        [$event] = $this->accessEvents($this->deskId, AccessEventsTable::SIGN_IN_FAILED);
        $this->assertNull($event->property_id);
        $this->assertSame(AccessEventsTable::SCOPE_PLATFORM, $event->scope);
        // Cleaned by person: it has no property.
        $this->getTableLocator()->get('Users')->getConnection()->delete('access_events', ['id' => $event->id]);
    }

    public function testWhoAManagerSeesAndOpensFollowsTheMembership(): void
    {
        $this->callAs($this->innAdmin, 'GET', '/api/users');
        $this->assertContains($this->deskId, array_column($this->responseJson()['users'], 'id'));
        $this->callAs($this->innAdmin, 'GET', "/api/users/{$this->deskId}/access-history");
        $this->assertResponseOk();

        // The column's hotel gets nothing: not listed, not found.
        $this->callAs($this->resortAdmin, 'GET', '/api/users');
        $this->assertNotContains($this->deskId, array_column($this->responseJson()['users'], 'id'));
        $this->callAs($this->resortAdmin, 'PATCH', "/api/users/{$this->deskId}", ['is_active' => false, 'reason' => 'x']);
        $this->assertResponseCode(404);
        $this->assertTrue((bool)$this->getTableLocator()->get('Users')->get($this->deskId)->is_active);

        // The Operations Staff card too.
        $this->callAs($this->innAdmin, 'GET', '/api/operations/today');
        $this->assertContains($this->deskId, array_column($this->responseJson()['operations']['staff']['members'], 'id'));
        $this->callAs($this->resortAdmin, 'GET', '/api/operations/today');
        $this->assertNotContains($this->deskId, array_column($this->responseJson()['operations']['staff']['members'], 'id'));
    }

    public function testTheStartUpCheckReportsTheDisagreement(): void
    {
        $legacy = (new RoleDefinitions($this->getTableLocator()->get('Users')->getConnection()))->check()['legacy'];
        $this->assertGreaterThanOrEqual(1, $legacy['property'], 'the column disagrees with the membership');
        $this->assertSame(0, $legacy['platform']);
        $this->assertGreaterThanOrEqual(1, $legacy['empty'], 'test accounts are made without the columns (3d-2)');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $legacy['fingerprint']['checksum']);
    }

    /**
     * Phase 3d-2 (3D2-D1, D2): nothing writes the columns, and the API shows
     * the same role and property as before, from the membership.
     */
    public function testNewAccountsAndRoleChangesLeaveTheColumnsAlone(): void
    {
        $users = $this->getTableLocator()->get('Users');
        $this->callAs($this->innAdmin, 'POST', '/api/users', [
            'name' => 'Quiet Desk', 'email' => 'lg-quiet-' . uniqid() . '@example.test', 'password' => 'secret123',
            'role' => 'receptionist',
        ]);
        $this->assertResponseCode(201);
        $shown = $this->responseJson()['user'];
        $this->assertSame(['receptionist', 'Front Desk Staff', $this->innId], [$shown['role'], $shown['role_name'], $shown['property_id']]);
        $made = $users->get($shown['id']);
        $this->assertSame([null, null], [$made->role, $made->property_id], 'nothing written to the columns');

        $this->callAs($this->innAdmin, 'GET', '/api/users');
        $row = array_column($this->responseJson()['users'], null, 'id')[$shown['id']];
        $this->assertSame([$this->innId, 'receptionist'], [$row['property_id'], $row['role']], 'GET /users still shows them');

        // A role change moves the membership and leaves the stored values as they were.
        $owner = $this->makeUser(null, 'owner', 'lg-owner-' . uniqid() . '@example.test');
        $this->callAs($owner, 'PATCH', "/api/users/{$this->deskId}", ['role' => 'admin', 'reason' => 'Promoted']);
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->assertSame('admin', $this->responseJson()['user']['role']);
        $desk = $users->get($this->deskId);
        $this->assertSame([null, $this->resortId], [$desk->role, $desk->property_id], 'existing values untouched');

        // Even a request that names them can't set them.
        $this->callAs($this->innAdmin, 'PATCH', "/api/users/{$shown['id']}", ['name' => 'Quiet', 'role' => null, 'property_id' => $this->resortId]);
        $this->assertNull($users->get($shown['id'])->property_id);
    }

    /**
     * 3D2-D3: the one-time imports skip accounts made without the columns
     * (they already have their membership and account_created) and add
     * nothing on a re-run.
     */
    public function testTheImportsLeaveAccountsWithoutTheColumnsAlone(): void
    {
        $connection = $this->getTableLocator()->get('Users')->getConnection();
        $users = $this->getTableLocator()->get('Users');
        // Even an account with empty columns and no recorded creation (which
        // the app can't make) is never given a guessed scope or role.
        $bare = $users->saveOrFail($users->newEntity([
            'name' => 'Bare', 'email' => 'lg-bare-' . uniqid() . '@example.test', 'password' => 'secret123', 'is_active' => true,
        ]));
        $this->scenarioUserIds[] = (int)$bare->id;

        (new MembershipImport($connection))->run();
        $result = (new AccessBackfill($connection))->run();
        $this->assertGreaterThanOrEqual(1, $result['skipped']);
        $this->assertSame([], $this->accessEvents((int)$bare->id, AccessEventsTable::ACCOUNT_CREATED));
        $this->assertSame(0, $this->getTableLocator()->get('PropertyMemberships')->find()->where(['user_id' => $bare->id])->count());

        // A re-run adds nothing for the accounts the app made.
        $before = $this->getTableLocator()->get('AccessEvents')->find()->where(['subject_user_id' => $this->deskId])->count();
        (new MembershipImport($connection))->run();
        (new AccessBackfill($connection))->run();
        $this->assertSame($before, $this->getTableLocator()->get('AccessEvents')->find()->where(['subject_user_id' => $this->deskId])->count());
    }

    /**
     * 3D2-D3: after 3d-3 drops the columns, the imports log that and report
     * nothing instead of failing. Simulated by renaming the columns for the
     * length of the test (CI's database is throwaway).
     */
    public function testTheImportsAreANoOpOnceTheColumnsAreGone(): void
    {
        $connection = $this->getTableLocator()->get('Users')->getConnection();
        $connection->execute('ALTER TABLE users RENAME COLUMN role TO role_retired_test');
        $connection->execute('ALTER TABLE users RENAME COLUMN property_id TO property_id_retired_test');
        try {
            $this->forgetSchema();
            $this->assertFalse((new LegacyUserColumns($connection))->exist());
            $this->assertNull((new LegacyUserColumns($connection))->report());
            $this->assertSame(['memberships' => 0, 'platform' => 0, 'skipped' => 0], (new MembershipImport($connection))->run());
            $this->assertSame([], (new MembershipImport($connection))->check());
            $this->assertSame(['account_created' => 0, 'skipped' => 0], (new AccessBackfill($connection))->run());
            $this->assertSame([], (new AccessBackfill($connection))->check());
        } finally {
            $connection->execute('ALTER TABLE users RENAME COLUMN role_retired_test TO role');
            $connection->execute('ALTER TABLE users RENAME COLUMN property_id_retired_test TO property_id');
            $this->forgetSchema();
        }
        $this->assertTrue((new LegacyUserColumns($connection))->exist());
    }

    /**
     * 3D2-D4/D5: the archive table exists empty, refuses changes, and a copy
     * of the columns reproduces their fingerprint exactly.
     */
    public function testAnArchiveCopyReproducesTheFingerprint(): void
    {
        $connection = $this->getTableLocator()->get('Users')->getConnection();
        $legacy = new LegacyUserColumns($connection);
        $baseline = $legacy->fingerprint();
        $this->assertSame($baseline, $legacy->fingerprint(), 'stable while nothing changes');

        $archive = $this->getTableLocator()->get('LegacyUserColumns');
        $rows = $connection->execute('SELECT id, role, property_id FROM users ORDER BY id')->fetchAll('assoc');
        try {
            foreach ($rows as $r) {
                $archive->saveOrFail($archive->newEntity([
                    'user_id' => (int)$r['id'],
                    'role' => $r['role'],
                    'property_id' => $r['property_id'],
                    'row_checksum' => LegacyUserColumns::rowChecksum((int)$r['id'], $r['role'], $r['property_id']),
                    'archived_at' => new DateTime(),
                ], ['accessibleFields' => ['*' => true]]));
            }
            $this->assertSame($baseline, $legacy->fingerprint(LegacyUserColumns::ARCHIVE), 'the copy is complete');

            $first = $archive->find()->firstOrFail();
            $this->expectException(LogicException::class);
            $archive->updateAll(['role' => 'tampered'], ['id' => $first->id]);
        } finally {
            $connection->execute('DELETE FROM legacy_user_columns');
        }
    }

    public function testTheFingerprintChangesWhenAValueChanges(): void
    {
        $connection = $this->getTableLocator()->get('Users')->getConnection();
        $legacy = new LegacyUserColumns($connection);
        $before = $legacy->fingerprint();
        $connection->update('users', ['role' => 'receptionist'], ['id' => $this->deskId]);
        $after = $legacy->fingerprint();
        $this->assertNotSame($before['checksum'], $after['checksum']);
        $this->assertSame($before['with_role'] + 1, $after['with_role']);
    }

    private function forgetSchema(): void
    {
        Cache::clear('_cake_model_');
        $this->getTableLocator()->clear();
    }

    public function testCreateUserMakesAMembershipAndRecordsItsProperty(): void
    {
        $email = 'lg-cli-' . uniqid() . '@example.test';
        $this->exec(sprintf(
            'create_user --name "Console Desk" --email %s --password secret123 --role receptionist --property-id %d',
            $email,
            $this->innId,
        ));
        $this->assertExitSuccess();
        $user = $this->getTableLocator()->get('Users')->find()->where(['email' => $email])->firstOrFail();
        $membership = $this->getTableLocator()->get('PropertyMemberships')->find('active')
            ->contain(['Roles'])->where(['user_id' => $user->id])->firstOrFail();
        $this->assertSame([$this->innId, 'receptionist'], [(int)$membership->property_id, $membership->role->code]);
        [$created] = $this->accessEvents((int)$user->id, AccessEventsTable::ACCOUNT_CREATED);
        $this->assertSame($this->innId, (int)$created->property_id);

        $this->exec('create_user --name "Console Desk" --email lg-x@example.test --password secret123 --role admin');
        $this->assertExitError('a property role needs --property-id');
    }
}
