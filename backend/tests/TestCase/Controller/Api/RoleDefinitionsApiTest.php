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
use InvalidArgumentException;
use LogicException;

/**
 * Role-definition history (final review G7, approved 2026-10-12 as
 * R-D1–R-D6): grants and revocations go through RoleDefinitions only, each
 * recorded with the reason, the migration, the release commit and the
 * memberships it affects, and no actor; history before G7 is imported
 * without inventing anything; drift is reported, never fixed; Managers and
 * the Platform Owner read the history.
 */
class RoleDefinitionsApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private Connection $connection;
    private RoleDefinitions $definitions;
    private int $propertyId;
    private string $adminToken;
    private string $deskToken;
    private string $ownerToken;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var \Cake\Database\Connection $connection */
        $connection = $this->getTableLocator()->get('Roles')->getConnection();
        $this->connection = $connection;
        $this->definitions = new RoleDefinitions($connection);
        $this->definitions->import();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Roles Inn');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "roles-admin-$tag@example.test");
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', "roles-desk-$tag@example.test");
        $this->ownerToken = $this->makeUser(null, 'owner', "roles-owner-$tag@example.test");
    }

    protected function tearDown(): void
    {
        putenv('RAILWAY_GIT_COMMIT_SHA');
        // Other suites rely on the presets' grants: put back anything a test removed.
        $this->definitions->grant('Restored after RoleDefinitionsApiTest', 'RoleDefinitionsApiTest');
        $this->cleanupScenario();
        parent::tearDown();
    }

    /**
     * @return list<\Cake\Datasource\EntityInterface>
     */
    private function eventsSince(int $id): array
    {
        return $this->getTableLocator()->get('RoleEvents')->find()
            ->where(['id >' => $id])->orderBy(['id' => 'ASC'])->all()->toList();
    }

    private function lastEventId(): int
    {
        return (int)($this->getTableLocator()->get('RoleEvents')->find()
            ->select(['m' => 'MAX(id)'])->disableHydration()->first()['m'] ?? 0);
    }

    public function testTheImportInventsNothingAndRunsOnce(): void
    {
        $this->connection->delete('role_events', []);
        $this->connection->delete('roles', ['code' => RoleDefinitions::PLATFORM_CODE]);

        $result = $this->definitions->import();
        $grantRows = $this->getTableLocator()->get('RolePermissions')->find()->count();
        $this->assertSame(2, $result['roles']);
        $this->assertSame($grantRows, $result['grants'], 'one event per grant row');
        $this->assertSame(count(Permissions::ROLE_GRANTS['owner']), $result['platform']);
        $this->assertSame(['roles' => 0, 'grants' => 0, 'platform' => 0], $this->definitions->import(), 'once only');

        $events = $this->getTableLocator()->get('RoleEvents')->find()->all()->toList();
        foreach ($events as $e) {
            $this->assertSame('import', $e->source);
            $this->assertNull($e->actor_id, 'who approved it was never recorded');
            $this->assertNull($e->reason, 'nor why');
            $this->assertNull($e->property_id);
        }
        $grant = $this->getTableLocator()->get('RolePermissions')->find()->orderBy(['id' => 'ASC'])->firstOrFail();
        $imported = $this->getTableLocator()->get('RoleEvents')->find()
            ->where(['correlation_id' => 'import-role_permissions-' . $grant->id])->firstOrFail();
        $this->assertSame(RoleEventsTable::PERMISSION_IMPORTED, $imported->event_type);
        $this->assertSame($grant->permission, $imported->permission);
        $this->assertSame(
            $grant->created->format('Y-m-d H:i:s'),
            $imported->occurred_at->format('Y-m-d H:i:s'),
            'dated by the time the database recorded the grant',
        );
        $this->assertTrue($this->definitions->check()['complete']);
    }

    public function testARevocationAndAGrantAreRecordedWithReasonReleaseAndImpact(): void
    {
        putenv('RAILWAY_GIT_COMMIT_SHA=0123456789abcdef0123456789abcdef01234567');
        $before = $this->lastEventId();
        $this->callAs($this->adminToken, 'GET', '/api/finance/summary');
        $this->assertResponseOk();

        $this->assertSame(1, $this->definitions->revoke(
            'admin',
            [Permissions::FINANCE_ANALYTICS_VIEW, 'not.a.held.permission'],
            'Approved 2026-10-12 as T1: analytics for owners only',
            'RevokeAnalytics',
        ));
        $this->callAs($this->adminToken, 'GET', '/api/finance/summary');
        $this->assertResponseCode(403, 'the next request no longer holds it');

        [$revoked] = $this->eventsSince($before);
        $this->assertSame(RoleEventsTable::PERMISSION_REVOKED, $revoked->event_type);
        $this->assertSame(Permissions::FINANCE_ANALYTICS_VIEW, $revoked->permission);
        $this->assertSame('Approved 2026-10-12 as T1: analytics for owners only', $revoked->reason);
        $this->assertSame('migration-RevokeAnalytics', $revoked->correlation_id);
        $this->assertSame('system', $revoked->source);
        $this->assertNull($revoked->actor_id, 'a deploy: no person acted in the app');
        $this->assertSame('0123456789abcdef0123456789abcdef01234567', $revoked->snapshot['commit']);
        $this->assertGreaterThanOrEqual(1, $revoked->snapshot['active_memberships']);

        $drift = $this->definitions->check();
        $this->assertFalse($drift['complete']);
        $this->assertContains("admin: code grants finance.analytics.view, the data doesn't", $drift['warnings']);

        $this->assertSame(['roles' => 0, 'grants' => 1], $this->definitions->grant('Restore T1', 'GrantAnalytics'));
        $this->callAs($this->adminToken, 'GET', '/api/finance/summary');
        $this->assertResponseOk();
        $granted = $this->eventsSince($before)[1];
        $this->assertSame([RoleEventsTable::PERMISSION_GRANTED, 'Restore T1'], [$granted->event_type, $granted->reason]);
        $this->assertTrue($this->definitions->check()['complete'], 'data, code and history agree again');
        $this->assertSame(['roles' => 0, 'grants' => 0], $this->definitions->grant('Again', 'GrantAnalytics'));
    }

    public function testAChangeWithoutAReasonIsRefused(): void
    {
        $before = $this->lastEventId();
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->definitions->revoke('admin', [Permissions::FINANCE_ANALYTICS_VIEW], '  ', 'NoReason');
        } finally {
            $this->assertSame([], $this->eventsSince($before));
            $this->callAs($this->adminToken, 'GET', '/api/finance/summary');
            $this->assertResponseOk();
        }
    }

    public function testDriftIsReportedAndNeverFixed(): void
    {
        $deskRole = $this->getTableLocator()->get('Roles')->idFor('receptionist');
        $this->connection->insert('role_permissions', [
            'role_id' => $deskRole, 'permission' => Permissions::FINANCE_ANALYTICS_VIEW,
        ]);
        try {
            $check = $this->definitions->check();
            $this->assertFalse($check['complete']);
            $this->assertContains(
                "receptionist: the data grants finance.analytics.view, the code doesn't",
                $check['warnings'],
            );
            $this->assertContains("receptionist: the history doesn't replay to the grants held", $check['warnings']);
            $this->definitions->grant('A routine grant run', 'Drift');
            $this->assertTrue(
                $this->getTableLocator()->get('RolePermissions')->exists([
                    'role_id' => $deskRole, 'permission' => Permissions::FINANCE_ANALYTICS_VIEW,
                ]),
                'drift is never removed automatically',
            );
        } finally {
            $this->connection->delete('role_permissions', [
                'role_id' => $deskRole, 'permission' => Permissions::FINANCE_ANALYTICS_VIEW,
            ]);
        }
    }

    public function testDirectWritesAreRefused(): void
    {
        $roles = $this->getTableLocator()->get('Roles');
        $grants = $this->getTableLocator()->get('RolePermissions');
        $role = $roles->find()->where(['code' => 'admin'])->firstOrFail();
        $grant = $grants->find()->where(['role_id' => $role->id])->firstOrFail();
        $refused = 0;
        foreach (
            [
                fn() => $roles->save($role->set('name', 'Boss')),
                fn() => $grants->delete($grant),
                fn() => $grants->deleteAll(['role_id' => $role->id]),
                fn() => $roles->updateAll(['name' => 'Boss'], ['id' => $role->id]),
                fn() => $this->getTableLocator()->get('RoleEvents')->updateAll(['reason' => 'x'], []),
            ] as $attempt
        ) {
            try {
                $attempt();
            } catch (LogicException) {
                $refused++;
            }
        }
        $this->assertSame(5, $refused);
        $this->assertTrue($grants->exists(['id' => $grant->id]));
    }

    public function testTheOwnersCodeGrantsAreObservedOnce(): void
    {
        $platformId = $this->getTableLocator()->get('Roles')->idFor(RoleDefinitions::PLATFORM_CODE);
        $this->assertNotNull($platformId, 'the history-only platform role');
        // As if the code had just added the enforcement permission: its recorded grant isn't there yet.
        $this->connection->delete('role_events', [
            'role_id' => $platformId, 'permission' => Permissions::PLATFORM_ENFORCEMENT_MANAGE,
        ]);
        $before = $this->lastEventId();

        $this->assertSame(1, $this->definitions->observePlatform());
        $this->assertSame(0, $this->definitions->observePlatform(), 'recorded once');
        [$observed] = $this->eventsSince($before);
        $this->assertSame(
            [RoleEventsTable::PERMISSION_GRANTED, Permissions::PLATFORM_ENFORCEMENT_MANAGE, 'system'],
            [$observed->event_type, $observed->permission, $observed->source],
        );
        $this->assertStringStartsWith('Platform Owner grants changed in code', (string)$observed->reason);
        $this->assertStringStartsWith('release-', $observed->correlation_id);
        $this->assertTrue($this->definitions->check()['complete']);
    }

    public function testManagersAndThePlatformOwnerReadTheHistory(): void
    {
        $this->callAs($this->adminToken, 'GET', '/api/roles/history');
        $this->assertResponseOk();
        $events = $this->responseJson()['events'];
        $this->assertNotEmpty($events);
        $this->assertArrayHasKey('active_memberships', $events[0]);

        $this->callAs($this->ownerToken, 'GET', '/api/platform/role-history');
        $this->assertResponseOk();
        $this->assertContains('platform', array_column($this->responseJson()['events'], 'role'));

        $this->callAs($this->deskToken, 'GET', '/api/roles/history');
        $this->assertResponseCode(403, 'Front Desk Staff do not see role history');
        $this->callAs($this->adminToken, 'GET', '/api/platform/role-history');
        $this->assertResponseCode(403);

        $this->callAs($this->adminToken, 'GET', '/api/roles');
        $this->assertSame(['admin', 'receptionist'], array_column($this->responseJson()['roles'], 'code'));
    }
}
