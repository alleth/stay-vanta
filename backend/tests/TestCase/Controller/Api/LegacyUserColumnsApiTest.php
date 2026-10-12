<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Auth\RoleDefinitions;
use App\Model\Table\AccessEventsTable;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

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
