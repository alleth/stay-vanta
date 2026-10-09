<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Event\GuestHistoryImport;
use App\Model\BusinessTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Guest accountability (final review G3, approved 2026-10-09 as GU1–GU5):
 * every registration says where it came from (Guests, a booking, a walk-in);
 * every change keeps before and after; a rename and a guest created despite
 * look-alikes need a reason and reach Activity; a booking that fills empty
 * details is recorded and never overwrites; only Managers read the history;
 * history before G3 is imported once and invents nothing.
 */
class GuestHistoryApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private int $otherPropertyId;
    private string $adminToken;
    private string $deskToken;
    private string $otherAdminToken;
    private int $rooms = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Guest Inn');
        $this->otherPropertyId = $this->createProperty('Other Guest Inn');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "guest-admin-$tag@example.test");
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', "guest-desk-$tag@example.test");
        $this->otherAdminToken = $this->makeUser($this->otherPropertyId, 'admin', "guest-other-$tag@example.test");
        $this->insertRow('RoomRates', ['property_id' => $this->propertyId, 'base_rate' => 1000]);
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    /**
     * @return list<\Cake\Datasource\EntityInterface>
     */
    private function eventsOf(int $guestId, ?string $type = null): array
    {
        $query = $this->getTableLocator()->get('GuestEvents')->find()
            ->where(['guest_id' => $guestId])->orderBy(['id' => 'ASC']);
        if ($type !== null) {
            $query->where(['event_type' => $type]);
        }

        return $query->all()->toList();
    }

    private function guest(string $name, array $extra = []): int
    {
        $this->callAs($this->deskToken, 'POST', '/api/guests', ['full_name' => $name, 'guest_type' => 'local'] + $extra);
        $this->assertResponseCode(201, (string)$this->_response->getBody());

        return (int)$this->responseJson()['guest']['id'];
    }

    private function walkIn(array $body): void
    {
        $room = $this->insertRow('Rooms', [
            'property_id' => $this->propertyId, 'room_number' => 'G-' . ++$this->rooms, 'room_type' => 'Deluxe',
            'status' => 'available',
        ]);
        $this->callAs($this->deskToken, 'POST', '/api/reservations', $body + [
            'room_id' => $room, 'source' => 'walk_in', 'total_guests' => 1,
            'check_out' => BusinessTime::today()->addDays(1)->format('Y-m-d'),
        ]);
    }

    private function feedRows(int $eventId): int
    {
        return $this->getTableLocator()->get('ActivityIndex')->find()
            ->where(['event_table' => 'guest_events', 'event_id' => $eventId])->count();
    }

    public function testARegistrationFromGuestsRecordsWhoWhereFromAndTheDetails(): void
    {
        $id = $this->guest('Ana Cruz', ['email' => 'ana@example.test', 'nationality' => 'PH']);

        [$event] = $this->eventsOf($id);
        $this->assertSame('registered', $event->event_type);
        $this->assertSame($this->userIdFor($this->deskToken), (int)$event->actor_id);
        $this->assertSame('receptionist', $event->actor_role);
        $this->assertSame('guests', $event->changes['via']);
        $this->assertSame('ana@example.test', $event->changes['after']['email']);
        $this->assertEquals(['guest_id' => $id, 'name' => 'Ana Cruz'], $event->snapshot, 'snapshot: id and name only');
        $this->assertSame(0, $this->feedRows((int)$event->id), 'routine registrations stay out of Activity');
    }

    public function testCreatingDespiteLookAlikesNeedsAReasonAndRecordsTheMatches(): void
    {
        $first = $this->guest('Ben Reyes', ['email' => 'ben@example.test']);
        $body = ['full_name' => 'Ben Reyes', 'email' => 'ben@example.test', 'guest_type' => 'local'];

        $this->callAs($this->deskToken, 'POST', '/api/guests', $body);
        $this->assertResponseCode(409);
        $this->callAs($this->deskToken, 'POST', '/api/guests', $body + ['force' => true]);
        $this->assertResponseCode(400, 'overriding look-alikes needs a reason');
        $this->assertSame(1, $this->getTableLocator()->get('Guests')->find()->where(['full_name' => 'Ben Reyes', 'property_id' => $this->propertyId])->count(), 'no guest without the reason');

        $this->callAs($this->deskToken, 'POST', '/api/guests', $body + ['force' => true, 'reason' => 'Father and son, same name']);
        $this->assertResponseCode(201);
        $second = (int)$this->responseJson()['guest']['id'];
        [$event] = $this->eventsOf($second);
        $this->assertSame('registered_despite_matches', $event->event_type);
        $this->assertSame('Father and son, same name', $event->reason);
        // assertEquals: MySQL's JSON column sorts object keys.
        $this->assertEquals([['guest_id' => $first, 'name' => 'Ben Reyes']], $event->changes['matches']);
        $this->assertSame(1, $this->feedRows((int)$event->id), 'a look-alike override reaches Activity');
    }

    public function testABookingRecordsWhereTheGuestCameFromAndChecksLookAlikesOnTheServer(): void
    {
        $this->walkIn(['guest_name' => 'Carla Dizon', 'contact_number' => '0917']);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $reservationId = (int)$this->responseJson()['reservation']['id'];
        $guestId = (int)$this->responseJson()['reservation']['guest_id'];
        [$event] = $this->eventsOf($guestId);
        $this->assertSame('walk_in', $event->changes['via']);
        $booked = $this->getTableLocator()->get('ReservationEvents')->find()
            ->where(['reservation_id' => $reservationId])->firstOrFail();
        $this->assertSame($booked->correlation_id, $event->correlation_id, 'one request: the booking and its guest');

        $this->walkIn(['guest_name' => 'Carla Dizon', 'contact_number' => '0917']);
        $this->assertContains($this->_response->getStatusCode(), [400, 409], 'a look-alike is refused on the server too');
        $this->walkIn(['guest_name' => 'Carla Dizon', 'contact_number' => '0917', 'new_guest_force' => true]);
        $this->assertContains($this->_response->getStatusCode(), [400, 409], 'overriding needs a reason');
        $this->assertSame(1, $this->getTableLocator()->get('Guests')->find()->where(['full_name' => 'Carla Dizon'])->count());

        $this->walkIn([
            'guest_name' => 'Carla Dizon', 'contact_number' => '0917',
            'new_guest_force' => true, 'new_guest_reason' => 'A different Carla, checked her ID',
        ]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $second = (int)$this->responseJson()['reservation']['guest_id'];
        [$override] = $this->eventsOf($second);
        $this->assertSame('registered_despite_matches', $override->event_type);
        $this->assertSame('walk_in', $override->changes['via']);
        $this->assertSame('A different Carla, checked her ID', $override->reason);
    }

    public function testABookingFillsOnlyEmptyDetailsAndRecordsJustThose(): void
    {
        $id = $this->guest('Dan Lim', ['email' => 'dan@example.test']);
        $this->walkIn(['guest_id' => $id, 'contact_number' => '0918', 'email' => 'other@example.test']);
        $this->assertResponseCode(201, (string)$this->_response->getBody());

        [$completed] = $this->eventsOf($id, 'details_completed');
        $this->assertEquals(['contact_number' => ['before' => null, 'after' => '0918']], $completed->changes);
        $this->assertSame('dan@example.test', $this->getTableLocator()->get('Guests')->get($id)->email, 'never overwrites');

        $this->walkIn(['guest_id' => $id, 'contact_number' => '0999']);
        $this->assertResponseCode(201);
        $this->assertCount(1, $this->eventsOf($id, 'details_completed'), 'nothing filled, nothing recorded');
    }

    public function testEditsKeepBeforeAndAfterAndARenameNeedsAReason(): void
    {
        $id = $this->guest('Eve Santos', ['contact_number' => '0917']);

        $this->callAs($this->deskToken, 'PATCH', "/api/guests/$id", ['contact_number' => '0920']);
        $this->assertResponseOk();
        [$updated] = $this->eventsOf($id, 'details_updated');
        $this->assertEquals(['contact_number' => ['before' => '0917', 'after' => '0920']], $updated->changes);

        $this->callAs($this->deskToken, 'PATCH', "/api/guests/$id", ['full_name' => 'Eva Santos']);
        $this->assertResponseCode(400, 'a rename needs a reason');
        $this->assertSame('Eve Santos', $this->getTableLocator()->get('Guests')->get($id)->full_name);
        $this->assertSame([], $this->eventsOf($id, 'renamed'));

        $this->callAs($this->deskToken, 'PATCH', "/api/guests/$id", [
            'full_name' => 'Eva Santos', 'email' => 'eva@example.test', 'reason' => 'Legal name per passport',
        ]);
        $this->assertResponseOk();
        [$renamed] = $this->eventsOf($id, 'renamed');
        $this->assertEquals(['full_name' => ['before' => 'Eve Santos', 'after' => 'Eva Santos']], $renamed->changes);
        $this->assertSame('Legal name per passport', $renamed->reason);
        $this->assertSame(1, $this->feedRows((int)$renamed->id), 'a rename reaches Activity');
        $updates = $this->eventsOf($id, 'details_updated');
        $this->assertCount(2, $updates);
        $this->assertSame($renamed->correlation_id, $updates[1]->correlation_id, 'one request, one correlation');
        $this->assertSame(0, $this->feedRows((int)$updates[1]->id), 'routine updates stay out of Activity');

        $this->callAs($this->deskToken, 'PATCH', "/api/guests/$id", ['full_name' => 'Eva Santos', 'reason' => 'again']);
        $this->assertResponseCode(400, 'nothing to change');
        $this->assertCount(1, $this->eventsOf($id, 'renamed'), 'no duplicated event');
    }

    public function testOnlyManagersReadTheHistoryOfTheirOwnGuests(): void
    {
        $id = $this->guest('Fay Uy');

        $this->callAs($this->adminToken, 'GET', "/api/guests/$id/history");
        $this->assertResponseOk();
        [$line] = $this->responseJson()['events'];
        $this->assertSame('registered', $line['event']);
        $this->assertNotNull($line['actor']);

        $this->callAs($this->deskToken, 'GET', "/api/guests/$id/history");
        $this->assertResponseCode(403);
        $this->callAs($this->otherAdminToken, 'GET', "/api/guests/$id/history");
        $this->assertResponseCode(404);
    }

    public function testTheImportRecordsEachGuestOnceAndInventsNothing(): void
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = $this->getTableLocator()->get('Guests')->getConnection();
        $connection->insert('guests', [
            'property_id' => $this->propertyId, 'full_name' => 'Old Guest', 'guest_type' => 'local',
            'created' => '2026-03-01 02:00:00', 'modified' => '2026-04-01 02:00:00',
        ]);
        $id = (int)$connection->execute('SELECT MAX(id) AS id FROM guests')->fetch('assoc')['id'];

        $import = new GuestHistoryImport($connection);
        $this->assertSame(1, $import->run($this->propertyId)['imported']);
        [$event] = $this->eventsOf($id);
        $this->assertSame('imported', $event->event_type);
        $this->assertSame('import', $event->source);
        $this->assertNull($event->actor_id, 'who registered them was never recorded');
        $this->assertNull($event->reason);
        $this->assertSame('2026-03-01 02:00:00', $event->occurred_at->format('Y-m-d H:i:s'));
        $this->assertTrue($event->snapshot['changed_since_registration'], 'changed later, by someone unrecorded');
        $this->assertSame('import-guests-' . $id, $event->correlation_id);

        $this->assertSame(0, $import->run($this->propertyId)['imported'], 'a second run adds nothing');
        $this->assertCount(1, $this->eventsOf($id));
        $check = $import->check($this->propertyId)[$this->propertyId];
        $this->assertTrue($check['complete']);
    }
}
