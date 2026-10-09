<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Event\RoomServiceImport;
use App\Model\BusinessTime;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Room service accountability (final review G4, approved 2026-10-09 as
 * R1–R5): whether a room can be sold and assigned (in service, maintenance,
 * out of service) has one writer and a ledger with before, after, who, why
 * and the request; occupancy follows check-in and check-out only. Check-in
 * is refused into a room off service, a booking only into one out of
 * service; check-out never clears maintenance; history is Managers'; the
 * import invents nothing.
 */
class RoomServiceApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private string $adminToken;
    private string $deskToken;
    private string $otherAdminToken;
    private int $roomId;
    private int $rooms = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Room Inn');
        $other = $this->createProperty('Other Room Inn');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "room-admin-$tag@example.test");
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', "room-desk-$tag@example.test");
        $this->otherAdminToken = $this->makeUser($other, 'admin', "room-other-$tag@example.test");
        $this->insertRow('RoomRates', ['property_id' => $this->propertyId, 'base_rate' => 1000]);
        $this->insertRow('BookingSources', ['property_id' => $this->propertyId, 'name' => 'Agoda', 'code' => 'agoda']);
        $this->roomId = $this->room();
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    private function room(): int
    {
        return $this->insertRow('Rooms', [
            'property_id' => $this->propertyId, 'room_number' => 'S-' . ++$this->rooms, 'room_type' => 'Deluxe',
            'status' => 'available',
        ]);
    }

    private function service(string $token, int $roomId, string $status, ?string $reason = null): void
    {
        $this->callAs($token, 'POST', "/api/rooms/$roomId/service", ['service_status' => $status] + ($reason ? ['reason' => $reason] : []));
    }

    /**
     * @return list<\Cake\Datasource\EntityInterface>
     */
    private function eventsOf(int $roomId): array
    {
        return $this->getTableLocator()->get('RoomEvents')->find()
            ->where(['room_id' => $roomId])->orderBy(['id' => 'ASC'])->all()->toList();
    }

    private function roomRow(int $id): EntityInterface
    {
        return $this->getTableLocator()->get('Rooms')->get($id);
    }

    public function testFrontDeskStartsAndCompletesMaintenanceAndEachIsRecorded(): void
    {
        $this->service($this->deskToken, $this->roomId, 'maintenance');
        $this->assertResponseCode(400, 'starting maintenance needs a reason');
        $this->assertSame('in_service', $this->roomRow($this->roomId)->service_status);
        $this->assertSame([], $this->eventsOf($this->roomId));

        $this->service($this->deskToken, $this->roomId, 'maintenance', 'Leaking tap');
        $this->assertResponseOk();
        $requestId = $this->_response->getHeaderLine('X-Request-Id');
        [$started] = $this->eventsOf($this->roomId);
        $this->assertSame('maintenance_started', $started->event_type);
        $this->assertSame(['in_service', 'maintenance'], [$started->service_before, $started->service_after]);
        $this->assertSame($this->userIdFor($this->deskToken), (int)$started->actor_id);
        $this->assertSame('Leaking tap', $started->reason);
        $this->assertSame($requestId, $started->correlation_id);
        $this->assertSame('maintenance', $this->roomRow($this->roomId)->status, 'older readers still see maintenance');
        $this->assertSame(1, $this->getTableLocator()->get('ActivityIndex')->find()
            ->where(['event_table' => 'room_events', 'event_id' => $started->id])->count(), 'every change reaches Activity');

        $this->service($this->deskToken, $this->roomId, 'maintenance', 'Again');
        $this->assertResponseCode(400, 'already under maintenance');
        $this->assertCount(1, $this->eventsOf($this->roomId), 'a repeat records nothing');

        $this->service($this->deskToken, $this->roomId, 'in_service');
        $this->assertResponseOk('completing needs no reason');
        $this->assertSame('maintenance_completed', $this->eventsOf($this->roomId)[1]->event_type);
        $this->assertSame('available', $this->roomRow($this->roomId)->status);
    }

    public function testOnlyManagersTakeARoomOutOfServiceAndBack(): void
    {
        $this->service($this->deskToken, $this->roomId, 'out_of_service', 'Water damage');
        $this->assertResponseCode(403);
        $this->service($this->adminToken, $this->roomId, 'out_of_service');
        $this->assertResponseCode(400, 'needs a reason');
        $this->assertSame([], $this->eventsOf($this->roomId));

        $this->service($this->adminToken, $this->roomId, 'out_of_service', 'Water damage, ceiling repair');
        $this->assertResponseOk();
        $this->service($this->deskToken, $this->roomId, 'in_service');
        $this->assertResponseCode(403, 'front desk cannot bring it back either');
        $this->service($this->adminToken, $this->roomId, 'in_service');
        $this->assertResponseOk();

        $this->assertSame(
            ['taken_out_of_service', 'returned_to_service'],
            array_map(fn($e) => $e->event_type, $this->eventsOf($this->roomId)),
        );
    }

    public function testCheckInIsRefusedIntoARoomOffServiceButLaterBookingsOnlyWhenOutOfService(): void
    {
        $reservation = $this->insertRow('Reservations', [
            'property_id' => $this->propertyId, 'room_id' => $this->roomId, 'status' => 'booked', 'source' => 'agoda',
            'booking_reference' => 'AG-9', 'payment_status' => 'unpaid', 'total_guests' => 1,
            'check_in' => BusinessTime::today()->format('Y-m-d'),
            'check_out' => BusinessTime::today()->addDays(1)->format('Y-m-d'),
        ]);
        $this->service($this->deskToken, $this->roomId, 'maintenance', 'Broken lock');

        $this->callAs($this->deskToken, 'POST', "/api/reservations/$reservation/check-in");
        $this->assertResponseCode(400);
        $this->assertStringContainsString('under maintenance', $this->responseJson()['message']);
        $this->assertSame('booked', $this->getTableLocator()->get('Reservations')->get($reservation)->status);

        $this->callAs($this->deskToken, 'POST', '/api/reservations', [
            'room_id' => $this->roomId, 'source' => 'walk_in', 'guest_name' => 'Walk In', 'total_guests' => 1,
            'check_out' => BusinessTime::today()->addDays(1)->format('Y-m-d'),
        ]);
        $this->assertResponseCode(400, 'a walk-in is a check-in');

        $later = [
            'room_id' => $this->roomId, 'source' => 'agoda', 'booking_reference' => 'AG-10', 'total_guests' => 1,
            'check_in' => BusinessTime::today()->addDays(10)->format('Y-m-d'),
            'check_out' => BusinessTime::today()->addDays(12)->format('Y-m-d'),
        ];
        $this->callAs($this->deskToken, 'POST', '/api/reservations', $later);
        $this->assertResponseCode(201, 'a later booking is fine during maintenance (R3)');

        $this->service($this->adminToken, $this->roomId, 'out_of_service', 'Renovation');
        $this->callAs($this->deskToken, 'POST', '/api/reservations', ['booking_reference' => 'AG-11'] + array_merge($later, [
            'check_in' => BusinessTime::today()->addDays(20)->format('Y-m-d'),
            'check_out' => BusinessTime::today()->addDays(22)->format('Y-m-d'),
        ]));
        $this->assertResponseCode(400, 'no booking into a room out of service');
        $this->assertStringContainsString('out of service', $this->responseJson()['message']);
    }

    public function testCheckOutKeepsMaintenanceAndOccupancyIsNeverSetByHand(): void
    {
        $this->callAs($this->deskToken, 'POST', '/api/reservations', [
            'room_id' => $this->roomId, 'source' => 'walk_in', 'guest_name' => 'Staying Guest', 'total_guests' => 1,
            'check_out' => BusinessTime::today()->addDays(1)->format('Y-m-d'),
        ]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $reservation = (int)$this->responseJson()['reservation']['id'];

        $this->callAs($this->deskToken, 'PATCH', "/api/rooms/{$this->roomId}", ['status' => 'available']);
        $this->assertResponseCode(400, 'occupancy follows check-in and check-out (R4)');
        $this->assertSame('occupied', $this->roomRow($this->roomId)->status);

        $this->service($this->deskToken, $this->roomId, 'maintenance', 'Guest reports a broken AC');
        $this->assertResponseOk();
        $this->assertSame('occupied', $this->roomRow($this->roomId)->status, 'the guest stays in the room');

        $this->callAs($this->deskToken, 'POST', "/api/reservations/$reservation/check-out");
        $this->assertResponseOk((string)$this->_response->getBody());
        $room = $this->roomRow($this->roomId);
        $this->assertSame('maintenance', $room->service_status, 'check-out never clears maintenance');
        $this->assertSame('maintenance', $room->status);

        $this->callAs($this->adminToken, 'GET', '/api/operations/today');
        $rooms = $this->responseJson()['operations']['rooms'];
        $this->assertSame(1, $rooms['maintenance']);
        $this->assertSame(0, $rooms['occupied']);
    }

    public function testOnlyManagersReadTheHistoryWithBeforeAfterAndRequestId(): void
    {
        $this->service($this->deskToken, $this->roomId, 'maintenance', 'Leaking tap');
        $requestId = $this->_response->getHeaderLine('X-Request-Id');

        $this->callAs($this->adminToken, 'GET', "/api/rooms/{$this->roomId}/history");
        $this->assertResponseOk();
        [$line] = $this->responseJson()['events'];
        $this->assertSame(['maintenance_started', 'in_service', 'maintenance', 'Leaking tap', $requestId], [
            $line['event'], $line['before'], $line['after'], $line['reason'], $line['request_id'],
        ]);
        $this->assertNotNull($line['actor']);

        $this->callAs($this->deskToken, 'GET', "/api/rooms/{$this->roomId}/history");
        $this->assertResponseCode(403);
        $this->callAs($this->otherAdminToken, 'GET', "/api/rooms/{$this->roomId}/history");
        $this->assertResponseCode(404);
    }

    public function testTheImportRecordsRoomsAlreadyUnderMaintenanceOnceAndInventsNothing(): void
    {
        $old = $this->room();
        $this->getTableLocator()->get('Rooms')->updateAll(['status' => 'maintenance'], ['id' => $old]);
        /** @var \Cake\Database\Connection $connection */
        $connection = $this->getTableLocator()->get('Rooms')->getConnection();
        $import = new RoomServiceImport($connection);

        $this->assertSame(1, $import->run($this->propertyId)['imported']);
        $this->assertSame('maintenance', $this->roomRow($old)->service_status);
        [$event] = $this->eventsOf($old);
        $this->assertSame('imported', $event->event_type);
        $this->assertSame('import', $event->source);
        $this->assertNull($event->actor_id, 'who started it was never recorded');
        $this->assertNull($event->reason, 'nor why');
        $this->assertSame('not recorded', $event->snapshot['started'], 'nor when');
        $this->assertSame([], $this->eventsOf($this->roomId), 'a room in service needs no event');

        $this->assertSame(0, $import->run($this->propertyId)['imported'], 'a second run adds nothing');
        $this->assertTrue($import->check($this->propertyId)[$this->propertyId]['complete']);
    }
}
