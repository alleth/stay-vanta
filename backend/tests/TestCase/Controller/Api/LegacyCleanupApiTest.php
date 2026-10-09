<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * G8 legacy cleanup, part a (approved 2026-10-09 as L-D1–L-D5): the
 * compatibility routes and fallbacks are gone, and what replaced them is the
 * only way in. Nothing recorded is changed by their removal.
 */
class LegacyCleanupApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private string $adminToken;
    private string $deskToken;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tag = uniqid();
        $this->propertyId = $this->createProperty('Legacy Cleanup Inn');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "g8-admin-{$this->tag}@example.test");
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', "g8-desk-{$this->tag}@example.test");
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    /**
     * L7, L8, L13: the old /reports paths, the old payment path, and paths
     * only CakePHP's catch-all routes used to reach.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function removedRouteProvider(): array
    {
        return [
            'old platform dashboard' => ['GET', '/api/reports/owner-dashboard'],
            'old admin dashboard' => ['GET', '/api/reports/admin-dashboard'],
            'old daily collection' => ['GET', '/api/reports/daily-collection'],
            'old monthly summary' => ['GET', '/api/reports/monthly-summary'],
            'old operations' => ['GET', '/api/reports/operations'],
            'old activity' => ['GET', '/api/reports/activity'],
            'old mark paid' => ['POST', '/api/reservations/1/payment'],
            'catch-all: finance action by name' => ['GET', '/api/finance/admin-dashboard'],
            'catch-all: index by name' => ['GET', '/api/users/index'],
            'catch-all: controller and action' => ['GET', '/api/reservations/payment/1'],
            'catch-all: delete by GET' => ['GET', '/api/reservations/delete/1'],
        ];
    }

    #[DataProvider('removedRouteProvider')]
    public function testRemovedRoutesAreGone(string $method, string $url): void
    {
        $this->callAs($this->adminToken, $method, $url);
        $this->assertResponseCode(404, "$method $url");
    }

    /**
     * L1, L2: guest details travel in a POST body only, never the URL.
     */
    public function testGuestSearchAndMatchingNoLongerAcceptTheUrl(): void
    {
        $this->callAs($this->deskToken, 'POST', '/api/guests', [
            'full_name' => 'Url Guest', 'email' => "url-{$this->tag}@example.test", 'guest_type' => 'local',
        ]);
        $this->assertResponseCode(201);

        $this->callAs($this->deskToken, 'GET', '/api/guests?q=Url');
        $this->assertResponseCode(400);
        $this->assertStringContainsString('POST /api/guests/search', (string)$this->responseJson()['message']);

        $this->callAs($this->deskToken, 'GET', '/api/guests/match?full_name=Url+Guest');
        $this->assertContains($this->_response->getStatusCode(), [404, 405], 'GET /guests/match is gone');

        // The plain list and the POST forms still work.
        $this->callAs($this->deskToken, 'GET', '/api/guests');
        $this->assertResponseOk();
        $this->callAs($this->deskToken, 'POST', '/api/guests/search', ['q' => 'Url']);
        $this->assertResponseOk();
        $this->assertSame(['Url Guest'], array_column($this->responseJson()['guests'], 'full_name'));
        $this->callAs($this->deskToken, 'POST', '/api/guests/match', ['full_name' => 'Url Guest']);
        $this->assertResponseOk();
        $this->assertCount(1, $this->responseJson()['duplicates']);
    }

    /**
     * L4: an account with no membership has no access, whatever
     * users.role / users.property_id say, and the refusal is recorded.
     */
    public function testAnAccountWithoutAMembershipCannotSignIn(): void
    {
        $email = "g8-nomember-{$this->tag}@example.test";
        $token = $this->makeUser($this->propertyId, 'receptionist', $email);
        $userId = $this->userIdFor($token);
        $this->getTableLocator()->get('Users')->getConnection()
            ->delete('property_memberships', ['user_id' => $userId]);

        $this->callAs(null, 'POST', '/api/auth/login', ['email' => $email, 'password' => 'secret123']);
        $this->assertResponseCode(401);
        $refused = $this->getTableLocator()->get('AccessEvents')->find()
            ->where(['subject_user_id' => $userId, 'event_type' => 'sign_in_refused'])->firstOrFail();
        $this->assertSame('no_membership', $refused->changes['because']);

        // A token issued earlier opens nothing either.
        $this->callAs($token, 'GET', '/api/reservations');
        $this->assertContains($this->_response->getStatusCode(), [401, 403]);
    }

    /**
     * L5: "role owner, no property" is no longer read as the platform flag.
     */
    public function testAnOwnerWithoutThePlatformFlagHoldsNothing(): void
    {
        $email = "g8-owner-{$this->tag}@example.test";
        $token = $this->makeUser(null, 'owner', $email);
        $this->getTableLocator()->get('Users')->updateAll(['is_platform' => false], ['id' => $this->userIdFor($token)]);

        $this->callAs(null, 'POST', '/api/auth/login', ['email' => $email, 'password' => 'secret123']);
        $this->assertResponseCode(401);
        $this->callAs($token, 'GET', '/api/platform/dashboard');
        $this->assertContains($this->_response->getStatusCode(), [401, 403]);
    }

    /**
     * L12 (readers): occupancy and service are separate filters; the combined
     * `status=maintenance` value is refused.
     */
    public function testRoomFiltersSeparateOccupancyAndService(): void
    {
        $inService = $this->insertRow('Rooms', [
            'property_id' => $this->propertyId, 'room_number' => 'G8-1', 'status' => 'available', 'service_status' => 'in_service',
        ]);
        $underMaintenance = $this->insertRow('Rooms', [
            'property_id' => $this->propertyId, 'room_number' => 'G8-2', 'status' => 'available', 'service_status' => 'maintenance',
        ]);

        $this->callAs($this->deskToken, 'GET', '/api/rooms?status=maintenance');
        $this->assertResponseCode(400);

        $this->callAs($this->deskToken, 'GET', '/api/rooms?service_status=maintenance');
        $this->assertResponseOk();
        $this->assertSame([$underMaintenance], array_map('intval', array_column($this->responseJson()['rooms'], 'id')));

        $this->callAs($this->deskToken, 'GET', '/api/operations/today');
        $this->assertResponseOk();
        $rooms = $this->responseJson()['operations']['rooms'];
        $byId = array_column($rooms['map'], 'status', 'id');
        $this->assertSame('maintenance', $byId[$underMaintenance], 'service decides, even with status available');
        $this->assertSame('available', $byId[$inService]);
    }
}
