<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Event\AccessBackfill;
use Cake\Database\Connection;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Build step 10, part 1 (approved 2026-10-05): access accountability. Every
 * sign-in, failure, lockout, refusal and sign-out, and every account change,
 * is one access event with who (or nobody, for an unproven attempt), whose
 * account, and why when it's sensitive. Deactivating and setting a password
 * end the session at once (A8), so reactivation never revives an old
 * session (F1). A repeated request is refused and records nothing.
 */
class AccessEventsApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private string $adminToken;
    private string $deskToken;
    private string $deskEmail;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Access Inn');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "access-admin-$tag@example.test");
        $this->deskEmail = "access-desk-$tag@example.test";
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', $this->deskEmail);
        /** @var \Cake\Database\Connection $connection */
        $connection = $this->getTableLocator()->get('AccessEvents')->getConnection();
        $this->connection = $connection;
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    /**
     * @return list<\Cake\Datasource\EntityInterface>
     */
    private function eventsOf(int $userId): array
    {
        return $this->getTableLocator()->get('AccessEvents')->find()
            ->where(['subject_user_id' => $userId])->orderBy(['id' => 'ASC'])->all()->toList();
    }

    private function signIn(string $email, string $password): void
    {
        $this->callAs(null, 'POST', '/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    public function testASignInIsRecordedWithItsDeviceAndEndsTheOtherSession(): void
    {
        $deskId = $this->userIdFor($this->deskToken);
        $this->signIn($this->deskEmail, 'secret123');
        $this->assertResponseOk();
        $token = $this->responseJson()['token'];

        [$event] = $this->eventsOf($deskId);
        $this->assertSame('signed_in', $event->event_type);
        $this->assertSame($deskId, (int)$event->actor_id, 'the person signing in is the actor');
        $this->assertSame('receptionist', $event->actor_role);
        $this->assertSame($this->propertyId, (int)$event->property_id);
        $this->assertSame('property', $event->scope);
        $this->assertEquals(['ended_other_session' => true], $event->changes, 'the fixture token was still open');
        $this->assertSame(0, $this->getTableLocator()->get('ActivityIndex')->find()
            ->where(['event_table' => 'access_events', 'event_id' => $event->id])->count(), 'sign-ins never enter the feed');

        $this->callAs($token, 'POST', '/api/auth/logout');
        $this->assertResponseOk();
        $this->assertSame('signed_out', $this->eventsOf($deskId)[1]->event_type);
    }

    public function testFailuresAreRecordedWithoutAnActorAndTheFifthLocks(): void
    {
        $deskId = $this->userIdFor($this->deskToken);
        for ($i = 0; $i < 5; $i++) {
            $this->signIn($this->deskEmail, 'wrong-password');
            $this->assertResponseCode(401);
        }
        $types = array_map(fn($e) => $e->event_type, $this->eventsOf($deskId));
        $this->assertSame(array_merge(array_fill(0, 5, 'sign_in_failed'), ['sign_in_locked']), $types);
        $failed = $this->eventsOf($deskId)[0];
        $this->assertNull($failed->actor_id, 'nobody was proven');
        $this->assertSame('web', $failed->source);

        $this->signIn($this->deskEmail, 'secret123');
        $this->assertResponseCode(429);
        $this->assertCount(6, $this->eventsOf($deskId), 'a locked address answers without recording again');

        // An address with no account: nothing to attach it to, nothing stored.
        $before = $this->getTableLocator()->get('AccessEvents')->find()->count();
        $this->signIn('nobody-' . uniqid() . '@example.test', 'whatever1');
        $this->assertResponseCode(401);
        $this->assertSame($before, $this->getTableLocator()->get('AccessEvents')->find()->count());
    }

    public function testTheRightPasswordForAnInactiveAccountIsRefusedAndRecorded(): void
    {
        $email = 'access-off-' . uniqid() . '@example.test';
        $offId = $this->userIdFor($this->makeUser($this->propertyId, 'receptionist', $email, false));
        $this->signIn($email, 'secret123');
        $this->assertResponseCode(401);
        $this->assertSame('Invalid credentials.', $this->responseJson()['error'], 'no hint that the account exists');
        [$event] = $this->eventsOf($offId);
        $this->assertSame('sign_in_refused', $event->event_type);
        $this->assertSame($offId, (int)$event->actor_id);
    }

    public function testCreatingAnAccountIsRecordedAndJoinsActivity(): void
    {
        $email = 'access-new-' . uniqid() . '@example.test';
        $this->callAs($this->adminToken, 'POST', '/api/users', [
            'name' => 'New Desk', 'email' => $email, 'password' => 'secret123', 'role' => 'receptionist',
        ]);
        $this->assertResponseCode(201);
        $newId = (int)$this->responseJson()['user']['id'];

        [$event] = $this->eventsOf($newId);
        $this->assertSame('account_created', $event->event_type);
        $this->assertSame($this->userIdFor($this->adminToken), (int)$event->actor_id);
        $this->assertSame('New Desk', $event->changes['after']['name']);
        $this->assertStringNotContainsString($email, json_encode([$event->changes, $event->snapshot]), 'no email in the ledger');

        $this->callAs($this->adminToken, 'GET', '/api/operations/activity');
        $lines = array_values(array_filter($this->responseJson()['activity'], fn($l) => $l['type'] === 'access'));
        $this->assertSame('account_created', $lines[0]['event']);
        $this->assertSame('New Desk', $lines[0]['person']);
    }

    public function testDeactivatingNeedsAReasonEndsTheSessionAndReactivatingRevivesNothing(): void
    {
        $deskId = $this->userIdFor($this->deskToken);

        $this->callAs($this->adminToken, 'PATCH', "/api/users/$deskId", ['is_active' => false]);
        $this->assertResponseCode(400);
        $this->assertTrue((bool)$this->getTableLocator()->get('Users')->get($deskId)->is_active, 'refused: nothing changed');
        $this->assertSame([], $this->eventsOf($deskId), 'and nothing recorded');

        $this->callAs($this->adminToken, 'PATCH', "/api/users/$deskId", ['is_active' => false, 'reason' => 'Left the hotel']);
        $this->assertResponseOk();
        $this->assertSame(['account_deactivated', 'session_ended'], array_map(fn($e) => $e->event_type, $this->eventsOf($deskId)));
        $this->assertSame('Left the hotel', $this->eventsOf($deskId)[0]->reason);

        // The same request again: nothing to change, nothing recorded.
        $this->callAs($this->adminToken, 'PATCH', "/api/users/$deskId", ['is_active' => false, 'reason' => 'Left the hotel']);
        $this->assertResponseCode(400);
        $this->assertCount(2, $this->eventsOf($deskId));

        // F1: switching it back on doesn't bring the old session back.
        $this->callAs($this->adminToken, 'PATCH', "/api/users/$deskId", ['is_active' => true, 'reason' => 'Rehired']);
        $this->assertResponseOk();
        $this->callAs($this->deskToken, 'GET', '/api/auth/me');
        $this->assertResponseCode(401, 'the old token died with the deactivation');
        $this->assertSame('account_reactivated', $this->eventsOf($deskId)[2]->event_type);
    }

    public function testPasswordsResetWithAReasonAndChangedWithTheCurrentOne(): void
    {
        $deskId = $this->userIdFor($this->deskToken);
        $adminId = $this->userIdFor($this->adminToken);

        $this->callAs($this->adminToken, 'POST', "/api/users/$deskId/reset-password", ['password' => 'newsecret1']);
        $this->assertResponseCode(400);
        $this->assertSame([], $this->eventsOf($deskId));
        $this->callAs($this->adminToken, 'POST', "/api/users/$deskId/reset-password", [
            'password' => 'newsecret1', 'reason' => 'Forgot it',
        ]);
        $this->assertResponseOk();
        $this->assertSame(['password_reset', 'session_ended'], array_map(fn($e) => $e->event_type, $this->eventsOf($deskId)));

        // Your own: prove it's you.
        $this->callAs($this->adminToken, 'POST', "/api/users/$adminId/reset-password", ['password' => 'mynewpass1']);
        $this->assertResponseCode(400);
        $this->callAs($this->adminToken, 'POST', "/api/users/$adminId/reset-password", [
            'password' => 'mynewpass1', 'current_password' => 'secret123',
        ]);
        $this->assertResponseOk();
        $this->assertSame(['password_changed', 'session_ended'], array_map(fn($e) => $e->event_type, $this->eventsOf($adminId)));
    }

    public function testEachPersonSeesTheirOwnAndAManagerSeesTheirStaff(): void
    {
        $deskId = $this->userIdFor($this->deskToken);
        $this->signIn($this->deskEmail, 'wrong-password');
        $this->callAs($this->adminToken, 'POST', "/api/users/$deskId/reset-password", [
            'password' => 'newsecret1', 'reason' => 'Locked out',
        ]);
        $this->signIn($this->deskEmail, 'newsecret1');
        $deskSession = $this->responseJson()['token'];

        $this->callAs($deskSession, 'GET', '/api/auth/sign-ins');
        $this->assertResponseOk();
        $mine = $this->responseJson()['events'];
        $this->assertSame(['signed_in', 'session_ended', 'password_reset', 'sign_in_failed'], array_column($mine, 'event'));
        $this->assertFalse($mine[3]['proven'], 'the failed attempt proved nobody');
        $this->assertStringContainsString('access-admin', (string)$mine[2]['actor'], 'who reset it');
        $this->assertSame('Locked out', $mine[2]['reason']);

        $this->callAs($this->adminToken, 'GET', "/api/users/$deskId/access-history");
        $this->assertResponseOk();
        $this->assertCount(4, $this->responseJson()['events']);
        $this->callAs($deskSession, 'GET', '/api/users/' . $this->userIdFor($this->adminToken) . '/access-history');
        $this->assertResponseCode(403);
    }

    public function testThePlatformOwnersSignInIsAPlatformEvent(): void
    {
        $email = 'access-owner-' . uniqid() . '@example.test';
        $ownerId = $this->userIdFor($this->makeUser(null, 'owner', $email));
        $this->signIn($email, 'secret123');
        $this->assertResponseOk();
        [$event] = $this->eventsOf($ownerId);
        $this->assertNull($event->property_id);
        $this->assertSame('platform', $event->scope);
    }

    public function testTheImportRecordsWhenAccountsWereMadeAndNothingElse(): void
    {
        $deskId = $this->userIdFor($this->deskToken);
        $this->getTableLocator()->get('Users')->updateAll(['created' => '2026-02-01 03:00:00'], ['id' => $deskId]);
        $backfill = new AccessBackfill($this->connection);

        $added = $backfill->run($this->propertyId);
        $this->assertSame(2, $added['account_created'], 'the Manager and the desk');
        $this->assertSame(0, $backfill->run($this->propertyId)['account_created'], 'a second run adds nothing');

        [$event] = $this->eventsOf($deskId);
        $this->assertSame('account_created', $event->event_type);
        $this->assertNull($event->actor_id, 'who made it was never recorded');
        $this->assertNull($event->reason);
        $this->assertSame('import', $event->source);
        $this->assertSame("import-users-$deskId", $event->correlation_id);
        $this->assertSame('2026-02-01 03:00:00', $event->occurred_at->format('Y-m-d H:i:s'), 'dated when it was made');
        $this->assertTrue($event->snapshot['active_at_import']);

        $check = $backfill->check($this->propertyId)[$this->propertyId];
        $this->assertTrue($check['complete']);
        $this->assertSame(2, $check['imported']);
    }
}
