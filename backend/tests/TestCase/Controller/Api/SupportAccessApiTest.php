<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Build step 10b, part 2 (A6, approved 2026-10-05, confirmed 2026-10-06):
 * the Platform Owner reaches a hotel's data only through a support session:
 * read-only, one property, with a reason, for 60 minutes, every request
 * recorded, visible to that property's Manager, who may end it. Expiry
 * writes no event: the end time was fixed when the session started.
 */
class SupportAccessApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private const ROOM = 'SUPPORT-101';

    private int $propertyId;
    private int $otherPropertyId;
    private string $ownerToken;
    private string $adminToken;
    private string $deskToken;
    private string $otherAdminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Support Inn');
        $this->otherPropertyId = $this->createProperty('Elsewhere Inn');
        $this->insertRow('Rooms', [
            'property_id' => $this->propertyId, 'room_number' => self::ROOM, 'room_type' => 'Deluxe',
            'status' => 'available',
        ]);
        $this->ownerToken = $this->makeUser(null, 'owner', "support-owner-$tag@example.test");
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "support-admin-$tag@example.test");
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', "support-desk-$tag@example.test");
        $this->otherAdminToken = $this->makeUser($this->otherPropertyId, 'admin', "support-other-$tag@example.test");
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    /**
     * @return list<\Cake\Datasource\EntityInterface>
     */
    private function events(string $type): array
    {
        return $this->getTableLocator()->get('AccessEvents')->find()
            ->where(['subject_user_id' => $this->userIdFor($this->ownerToken), 'event_type' => $type])
            ->orderBy(['id' => 'ASC'])->all()->toList();
    }

    private function start(?string $reason = 'Guest reports a wrong room status'): void
    {
        $body = ['property_id' => $this->propertyId];
        if ($reason !== null) {
            $body['reason'] = $reason;
        }
        $this->callAs($this->ownerToken, 'POST', '/api/platform/support-sessions', $body);
    }

    public function testWithoutASessionThePlatformOwnerHasNoHotelAccess(): void
    {
        $this->callAs($this->ownerToken, 'GET', '/api/rooms?property_id=' . $this->propertyId);
        $this->assertResponseCode(403);
        $this->callAs($this->ownerToken, 'GET', '/api/reservations?property_id=' . $this->propertyId);
        $this->assertResponseCode(403);
    }

    public function testStartingNeedsAReasonAndWithoutOneNothingOpensOrIsRecorded(): void
    {
        $this->start(null);
        $this->assertResponseCode(400);

        $this->assertSame(0, $this->getTableLocator()->get('SupportSessions')->find()
            ->where(['property_id' => $this->propertyId])->count());
        $this->assertSame([], $this->events('support_access_started'));
    }

    public function testASessionReadsOnePropertyReadOnlyAndRecordsEveryRequest(): void
    {
        $this->start();
        $this->assertResponseCode(201);
        $sessionId = (int)$this->responseJson()['session']['id'];

        [$started] = $this->events('support_access_started');
        $this->assertSame($this->userIdFor($this->ownerToken), (int)$started->actor_id);
        $this->assertSame('owner', $started->actor_role);
        $this->assertSame('Guest reports a wrong room status', $started->reason);
        $this->assertSame($this->propertyId, (int)$started->property_id);
        $this->assertSame('property', $started->scope, 'the event belongs to the hotel, so its Manager sees it');
        $this->assertSame($sessionId, (int)$started->support_session_id);
        $this->assertSame(1, $this->getTableLocator()->get('ActivityIndex')->find()
            ->where(['event_table' => 'access_events', 'event_id' => $started->id])->count());

        $this->callAs($this->ownerToken, 'GET', '/api/rooms?property_id=' . $this->otherPropertyId);
        $this->assertResponseOk();
        $this->assertStringContainsString(self::ROOM, (string)$this->_response->getBody(), 'only the session\'s property');

        $this->callAs($this->ownerToken, 'POST', '/api/rooms', ['room_number' => 'X-1', 'room_type' => 'Twin']);
        $this->assertResponseCode(403);
        $this->assertSame('Support access is read-only.', $this->responseJson()['message']);
        $this->assertFalse($this->getTableLocator()->get('Rooms')->exists(['room_number' => 'X-1']));

        $this->callAs($this->ownerToken, 'GET', '/api/auth/me');
        $me = $this->responseJson()['user'];
        $this->assertSame($this->propertyId, (int)$me['property_id']);
        $this->assertSame($sessionId, (int)$me['support']['id']);
        $this->assertNotContains('front_desk.reservation.manage', $me['permissions']);
        $this->assertContains('front_desk.reservation.view', $me['permissions']);

        $used = $this->events('support_access_used');
        $this->assertCount(3, $used, 'each request after the start, refused ones too');
        $this->assertSame(
            ['GET', 'POST', 'GET'],
            array_map(fn($e) => $e->changes['method'], $used),
        );
        $this->assertSame('/api/rooms', $used[1]->changes['path']);
        foreach ($used as $event) {
            $this->assertSame($sessionId, (int)$event->support_session_id);
            $this->assertSame($this->propertyId, (int)$event->property_id);
        }
    }

    public function testOnlyOneSessionIsOpenAtATime(): void
    {
        $this->start();
        $this->assertResponseCode(201);
        $this->start('A second look');
        $this->assertResponseCode(400);

        $this->assertCount(1, $this->events('support_access_started'));
        $this->assertSame(1, $this->getTableLocator()->get('SupportSessions')->find()
            ->where(['user_id' => $this->userIdFor($this->ownerToken)])->count());
    }

    public function testTheManagerSeesTheSessionAndMayEndItOnce(): void
    {
        $this->start();
        $sessionId = (int)$this->responseJson()['session']['id'];
        $this->callAs($this->ownerToken, 'GET', '/api/rooms');

        $this->callAs($this->adminToken, 'GET', '/api/auth/me');
        [$banner] = $this->responseJson()['user']['support_active'];
        $this->assertSame($sessionId, (int)$banner['id']);
        $this->assertSame('Guest reports a wrong room status', $banner['reason']);

        $this->callAs($this->adminToken, 'GET', '/api/support-sessions');
        $this->assertResponseOk();
        [$listed] = $this->responseJson()['sessions'];
        $this->assertSame('open', $listed['state']);
        $this->assertSame(1, $listed['requests']);

        $this->callAs($this->adminToken, 'GET', "/api/support-sessions/$sessionId");
        $this->assertResponseOk();
        $this->assertSame('/api/rooms', $this->responseJson()['requests'][0]['path']);

        $this->callAs($this->adminToken, 'POST', "/api/support-sessions/$sessionId/end", ['reason' => 'Done for today']);
        $this->assertResponseOk();
        $this->assertSame('ended', $this->responseJson()['session']['state']);

        [$ended] = $this->events('support_access_ended');
        $this->assertSame($this->userIdFor($this->adminToken), (int)$ended->actor_id);
        $this->assertSame('Done for today', $ended->reason);
        $this->assertSame(['ended_by' => 'manager'], $ended->changes);

        $this->callAs($this->ownerToken, 'GET', '/api/rooms');
        $this->assertResponseCode(403, 'back on the platform, with no hotel access');

        $this->callAs($this->adminToken, 'POST', "/api/support-sessions/$sessionId/end");
        $this->assertResponseCode(400);
        $this->assertCount(1, $this->events('support_access_ended'), 'a repeated end records nothing');
    }

    public function testThePlatformOwnerEndsTheirOwnSession(): void
    {
        $this->start();
        $sessionId = (int)$this->responseJson()['session']['id'];

        $this->callAs($this->ownerToken, 'POST', "/api/platform/support-sessions/$sessionId/end");
        $this->assertResponseOk();
        [$ended] = $this->events('support_access_ended');
        $this->assertSame(['ended_by' => 'platform_owner'], $ended->changes);

        $this->callAs($this->ownerToken, 'GET', '/api/auth/me');
        $this->assertNull($this->responseJson()['user']['support']);
        $this->assertNull($this->responseJson()['user']['property_id']);
    }

    public function testASessionEndsByItselfAfterItsHourWithNoInventedEvent(): void
    {
        $this->start();
        $sessionId = (int)$this->responseJson()['session']['id'];
        $session = $this->getTableLocator()->get('SupportSessions')->get($sessionId);
        $this->assertSame(
            60,
            (int)round(($session->expires_at->getTimestamp() - $session->started_at->getTimestamp()) / 60),
        );
        $this->getTableLocator()->get('SupportSessions')
            ->updateAll(['expires_at' => new DateTime('-1 minute')], ['id' => $sessionId]);

        $this->callAs($this->ownerToken, 'GET', '/api/rooms');
        $this->assertResponseCode(403);
        $this->assertSame([], $this->events('support_access_ended'));

        $this->callAs($this->adminToken, 'GET', '/api/support-sessions');
        $this->assertSame('expired', $this->responseJson()['sessions'][0]['state']);
    }

    public function testOnlyTheSessionsOwnManagerSeesOrEndsIt(): void
    {
        $this->start();
        $sessionId = (int)$this->responseJson()['session']['id'];

        $this->callAs($this->deskToken, 'GET', '/api/support-sessions');
        $this->assertResponseCode(403);
        $this->callAs($this->deskToken, 'POST', "/api/support-sessions/$sessionId/end");
        $this->assertResponseCode(403);

        $this->callAs($this->otherAdminToken, 'GET', '/api/support-sessions');
        $this->assertResponseOk();
        $this->assertSame([], $this->responseJson()['sessions']);
        $this->callAs($this->otherAdminToken, 'GET', "/api/support-sessions/$sessionId");
        $this->assertResponseCode(404);
        $this->callAs($this->otherAdminToken, 'POST', "/api/support-sessions/$sessionId/end");
        $this->assertResponseCode(404);
        $this->assertSame([], $this->events('support_access_ended'));
    }
}
