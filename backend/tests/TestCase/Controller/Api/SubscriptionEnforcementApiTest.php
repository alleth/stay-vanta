<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Model\BusinessTime;
use App\Model\Subscription;
use Cake\I18n\Date;
use Cake\ORM\Entity;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Build step 10b, part 3 (A7, B1–B5, approved 2026-10-06): a lapsed
 * subscription gives 7 days of grace, then 30 days read-only, then
 * suspension, enforced only as far as the rollout phase allows. Read-only
 * keeps viewing, checking guests out, billing started stays, settling,
 * refunds and account security; it refuses new work. Suspension refuses
 * sign-in, with a reason recorded. The Platform Owner and support sessions
 * are unaffected.
 */
class SubscriptionEnforcementApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private string $adminToken;
    private string $deskToken;
    private string $deskEmail;
    private string $adminEmail;
    private int $reservationId;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Lapsed Inn');
        $this->adminEmail = "lapsed-admin-$tag@example.test";
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', $this->adminEmail);
        $this->deskEmail = "lapsed-desk-$tag@example.test";
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', $this->deskEmail);
        $room = $this->insertRow('Rooms', [
            'property_id' => $this->propertyId, 'room_number' => 'L-1', 'room_type' => 'Deluxe',
            'status' => 'available',
        ]);
        $guest = $this->insertRow('Guests', [
            'property_id' => $this->propertyId, 'full_name' => 'Lapsed Guest', 'guest_type' => 'local',
        ]);
        $this->reservationId = $this->insertRow('Reservations', [
            'property_id' => $this->propertyId, 'room_id' => $room, 'guest_id' => $guest,
            'status' => 'booked', 'source' => 'walk_in', 'payment_status' => 'unpaid', 'total_guests' => 1,
            'check_in' => BusinessTime::today()->addDays(5)->format('Y-m-d'),
            'check_out' => BusinessTime::today()->addDays(7)->format('Y-m-d'),
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    /**
     * Paid through `$daysAgo` hotel days ago, in rollout phase `$mode`.
     */
    private function lapse(int $daysAgo, string $mode): void
    {
        $this->usePhase($mode);
        $this->getTableLocator()->get('Properties')->updateAll(
            ['subscription_expires_at' => BusinessTime::today()->subDays($daysAgo)->format('Y-m-d')],
            ['id' => $this->propertyId],
        );
    }

    public function testTheStagesFollowTheApprovedTimeline(): void
    {
        $property = new Entity(['id' => 0, 'subscription_status' => 'active',
            'subscription_expires_at' => new Date('2026-10-10')]);
        $expected = [
            '2026-10-10' => Subscription::ACTIVE,
            '2026-10-11' => Subscription::GRACE,
            '2026-10-17' => Subscription::GRACE,
            '2026-10-18' => Subscription::READ_ONLY,
            '2026-11-16' => Subscription::READ_ONLY,
            '2026-11-17' => Subscription::SUSPENDED,
        ];
        foreach ($expected as $day => $stage) {
            $this->assertSame($stage, Subscription::of($property, new Date($day), 'suspend')->stage, $day);
        }
        $readOnly = Subscription::of($property, new Date('2026-10-20'), 'report');
        $this->assertSame(Subscription::ACTIVE, $readOnly->enforced(), 'report enforces nothing');
        $this->assertSame(Subscription::GRACE, Subscription::of($property, new Date('2026-10-20'), 'grace')->enforced());
        $this->assertSame(
            Subscription::READ_ONLY,
            Subscription::of($property, new Date('2026-12-01'), 'read_only')->enforced(),
            'a suspended property stays read-only until suspension is switched on',
        );
        $this->assertSame('2026-10-18', $readOnly->readOnlyFrom()->format('Y-m-d'));
        $this->assertSame('2026-11-17', $readOnly->suspendedFrom()->format('Y-m-d'));
    }

    public function testReportModeWarnsButBlocksNothing(): void
    {
        $this->lapse(10, Subscription::MODE_REPORT);

        $this->callAs($this->adminToken, 'GET', '/api/auth/me');
        $subscription = $this->responseJson()['user']['subscription'];
        $this->assertSame('read_only', $subscription['stage']);
        $this->assertSame('active', $subscription['enforced']);

        $this->callAs($this->adminToken, 'POST', '/api/rooms', ['room_number' => 'L-2', 'room_type' => 'Twin']);
        $this->assertResponseCode(201);
    }

    public function testReadOnlyKeepsViewingWindDownAndSecurityButRefusesNewWork(): void
    {
        $this->lapse(10, Subscription::MODE_READ_ONLY);
        $deskId = $this->userIdFor($this->deskToken);

        $this->callAs($this->adminToken, 'GET', '/api/reservations');
        $this->assertResponseOk();

        $this->callAs($this->adminToken, 'POST', '/api/rooms', ['room_number' => 'L-2', 'room_type' => 'Twin']);
        $this->assertResponseCode(403);
        $this->assertStringContainsString('read-only', $this->responseJson()['message']);

        $this->callAs($this->deskToken, 'POST', "/api/reservations/{$this->reservationId}/check-in");
        $this->assertResponseCode(403, 'no check-ins');
        $this->callAs($this->deskToken, 'POST', "/api/reservations/{$this->reservationId}/post-room-charge");
        $this->assertResponseCode(403, 'no billing of a stay that has not started');
        $this->callAs($this->deskToken, 'POST', "/api/reservations/{$this->reservationId}/check-out");
        $this->assertResponseCode(400, 'check-out passes the gate; this booking just can\'t be checked out');

        $this->callAs($this->adminToken, 'POST', '/api/users', [
            'name' => 'New Desk', 'email' => 'lapsed-new-' . uniqid() . '@example.test',
            'password' => 'secret123', 'role' => 'receptionist',
        ]);
        $this->assertResponseCode(403, 'no new accounts');
        $this->callAs($this->adminToken, 'PATCH', "/api/users/$deskId", ['is_active' => false, 'reason' => 'Left the hotel']);
        $this->assertResponseOk('security comes first: deactivating still works (B2)');
        $this->callAs($this->adminToken, 'PATCH', "/api/users/$deskId", ['is_active' => true, 'reason' => 'Rehired']);
        $this->assertResponseOk('and reactivating');

        $this->callAs($this->adminToken, 'GET', '/api/auth/me');
        $me = $this->responseJson()['user'];
        $this->assertContains('finance.invoice.settle', $me['permissions']);
        $this->assertContains('finance.invoice.refund', $me['permissions']);
        $this->assertNotContains('front_desk.reservation.manage', $me['permissions']);
        $this->assertSame(['front_desk.reservation.manage'], $me['subscription']['wind_down']);
    }

    public function testARefusalNamesTheSubscriptionOnlyWhenItWithheldThePermission(): void
    {
        $this->lapse(10, Subscription::MODE_READ_ONLY);

        $this->callAs($this->deskToken, 'POST', '/api/receipt-series', ['prefix' => 'X']);
        $this->assertResponseCode(403);
        $this->assertStringNotContainsString('subscription', $this->responseJson()['message'], 'never theirs anyway');
    }

    public function testSuspensionRefusesSignInAndRecordsWhy(): void
    {
        $this->lapse(40, Subscription::MODE_SUSPEND);

        $this->callAs(null, 'POST', '/api/auth/login', ['email' => $this->deskEmail, 'password' => 'secret123']);
        $this->assertResponseCode(403);
        $this->assertStringContainsString('suspended', $this->responseJson()['error']);
        $event = $this->getTableLocator()->get('AccessEvents')->find()
            ->where(['subject_user_id' => $this->userIdFor($this->deskToken), 'event_type' => 'sign_in_refused'])
            ->firstOrFail();
        $this->assertEquals(['because' => 'subscription_suspended'], $event->changes);

        $this->callAs($this->adminToken, 'GET', '/api/reservations');
        $this->assertResponseCode(403, 'an open session holds nothing once suspended');
        $this->callAs($this->adminToken, 'GET', '/api/auth/me');
        $this->assertSame('suspended', $this->responseJson()['user']['subscription']['enforced']);

        $this->usePhase(Subscription::MODE_READ_ONLY);
        $this->callAs($this->adminToken, 'GET', '/api/reservations');
        $this->assertResponseOk('suspension not switched on yet: read-only');
    }

    public function testStatusSetInactiveByHandCountsFromTheDayItWasSet(): void
    {
        $this->usePhase(Subscription::MODE_SUSPEND);
        $ownerToken = $this->makeUser(null, 'owner', 'lapsed-owner-' . uniqid() . '@example.test');
        $this->callAs($ownerToken, 'PATCH', "/api/properties/{$this->propertyId}", ['subscription_status' => 'inactive']);
        $this->assertResponseOk();

        $this->callAs($ownerToken, 'GET', '/api/properties');
        $listed = array_values(array_filter(
            $this->responseJson()['properties'],
            fn($p) => (int)$p['id'] === $this->propertyId,
        ))[0];
        $this->assertSame('grace', $listed['subscription']['stage']);
        $this->assertSame(BusinessTime::today()->format('Y-m-d'), $listed['subscription']['lapsed_on']);
    }

    public function testThePlatformOwnerAndSupportSessionsAreUnaffected(): void
    {
        $this->lapse(40, Subscription::MODE_SUSPEND);
        $ownerToken = $this->makeUser(null, 'owner', 'lapsed-owner-' . uniqid() . '@example.test');

        $this->callAs($ownerToken, 'GET', '/api/platform/dashboard');
        $this->assertResponseOk();
        $this->callAs($ownerToken, 'POST', '/api/platform/support-sessions', [
            'property_id' => $this->propertyId, 'reason' => 'Renewal questions',
        ]);
        $this->assertResponseCode(201);
        $this->callAs($ownerToken, 'GET', '/api/reservations');
        $this->assertResponseOk();
    }
}
