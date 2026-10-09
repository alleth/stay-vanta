<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Model\BusinessTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Build step 8: reservation accountability (approved 2026-10-04, R1–R8).
 * Every reservation action records exactly one `reservation_events` row
 * (who, role, what changed before/after, when, why, request id) under lock,
 * check, change, record; reasons where the standard requires them; soft
 * delete hides a reservation everywhere without removing it.
 *
 * Idempotency, as strictly as settlement's: the second of two identical
 * check-ins, check-outs, cancellations, corrections, backdated entries and
 * deletions is refused, records no event and changes no business data.
 */
class ReservationEventsApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private string $adminToken;
    private string $deskToken;
    private int $roomId;
    private int $otherRoomId;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Reservation Ledger Inn');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "resv-admin-$tag@example.test");
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', "resv-desk-$tag@example.test");
        $this->roomId = $this->insertRow('Rooms', [
            'property_id' => $this->propertyId, 'room_number' => 'E-1', 'room_type' => 'Deluxe', 'status' => 'available',
        ]);
        $this->otherRoomId = $this->insertRow('Rooms', [
            'property_id' => $this->propertyId, 'room_number' => 'E-2', 'room_type' => 'Deluxe', 'status' => 'available',
        ]);
        $this->insertRow('RoomRates', ['property_id' => $this->propertyId, 'room_id' => null, 'base_rate' => 1000]);
        $this->insertRow('BookingSources', ['property_id' => $this->propertyId, 'name' => 'Agoda', 'code' => 'agoda']);
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    private function day(int $offset): string
    {
        return BusinessTime::today()->addDays($offset)->format('Y-m-d');
    }

    /** An online booking (no guest, so no downpayment) for today and tomorrow; returns its id. */
    private function booking(int $roomId, string $token = ''): int
    {
        $this->callAs($token ?: $this->deskToken, 'POST', '/api/reservations', [
            'room_id' => $roomId, 'source' => 'agoda', 'booking_reference' => 'AG-' . uniqid(),
            'check_in' => $this->day(0), 'check_out' => $this->day(2), 'total_guests' => 1,
        ]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());

        return (int)$this->responseJson()['reservation']['id'];
    }

    /** A past stay a Manager enters (backdated), ended yesterday; returns its id. */
    private function pastStay(int $roomId): int
    {
        $this->callAs($this->adminToken, 'POST', '/api/reservations', [
            'room_id' => $roomId, 'source' => 'walk_in', 'guest_name' => 'Past Guest',
            'check_in' => $this->day(-4), 'check_out' => $this->day(-2), 'reason' => 'Paper log typed in',
        ]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());

        return (int)$this->responseJson()['reservation']['id'];
    }

    /**
     * @return list<string>
     */
    private function types(int $reservationId): array
    {
        return $this->getTableLocator()->get('ReservationEvents')->find()
            ->where(['reservation_id' => $reservationId])->orderBy(['id' => 'ASC'])
            ->all()->extract('event_type')->toList();
    }

    private function eventCount(): int
    {
        return $this->getTableLocator()->get('ReservationEvents')->find()
            ->where(['property_id' => $this->propertyId])->count();
    }

    /**
     * Everything an action could change: the reservation row (deleted or
     * not), its discounts, its room, the rooms' statuses, invoice lines.
     *
     * @return array<string, mixed>
     */
    private function businessState(): array
    {
        $locator = $this->getTableLocator();
        $reservations = $locator->get('Reservations')->find('all', withDeleted: true)
            ->where(['property_id' => $this->propertyId])->orderBy(['id' => 'ASC'])
            ->disableHydration()->all()->toList();

        return [
            'reservations' => array_map(function ($r) {
                unset($r['modified']);

                return $r;
            }, $reservations),
            'discounts' => $locator->get('ReservationDiscounts')->find()
                ->where(['reservation_id IN' => array_column($reservations, 'id') ?: [0]])
                ->disableHydration()->all()->toList(),
            'rooms' => $locator->get('Rooms')->find()->select(['id', 'status'])
                ->where(['property_id' => $this->propertyId])->disableHydration()->all()->toList(),
            'invoice_lines' => $locator->get('InvoiceLines')->find()->innerJoinWith('Invoices')
                ->where(['Invoices.property_id' => $this->propertyId])->count(),
        ];
    }

    /**
     * Send the request a second time: it must be refused, record no event and change nothing.
     *
     * @param array<string, mixed> $body
     */
    private function assertRepeatChangesNothing(string $token, string $method, string $url, array $body, string $what): void
    {
        $events = $this->eventCount();
        $state = $this->businessState();
        $this->callAs($token, $method, $url, $body);
        $this->assertContains(
            $this->_response->getStatusCode(),
            [400, 404, 422],
            "$what twice: the second is refused (" . $this->_response->getBody() . ')',
        );
        $this->assertSame($events, $this->eventCount(), "$what twice: no second event");
        $this->assertEquals($state, $this->businessState(), "$what twice: no business data changes");
    }

    public function testEachActionRecordsOneEventWithWhoWhatWhenAndWhy(): void
    {
        $id = $this->booking($this->roomId);
        $this->callAs($this->deskToken, 'PATCH', "/api/reservations/$id", ['total_guests' => 2]);
        $this->assertResponseOk((string)$this->_response->getBody());
        $editRequest = $this->_response->getHeaderLine('X-Request-Id');
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$id/check-in");
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$id/check-out");
        $this->assertResponseOk((string)$this->_response->getBody());

        $this->assertSame(['booked', 'edited', 'checked_in', 'checked_out'], $this->types($id));

        $events = $this->getTableLocator()->get('ReservationEvents')->find()
            ->where(['reservation_id' => $id])->orderBy(['id' => 'ASC'])->all()->toList();
        $deskId = $this->userIdFor($this->deskToken);
        foreach ($events as $event) {
            $this->assertSame($deskId, (int)$event->actor_id, $event->event_type . ': who');
            $this->assertSame('receptionist', $event->actor_role);
            $this->assertSame('web', $event->source);
            $this->assertNotNull($event->occurred_at);
            $this->assertSame($this->roomId, (int)$event->room_id);
            $this->assertSame('E-1', $event->snapshot['room']);
        }
        $this->assertSame('booked', $events[0]->status_after);
        $this->assertSame($this->day(0), $events[0]->changes['after']['check_in']);
        // MySQL stores JSON objects with its own key order: compare by key.
        $this->assertEquals(['before' => 1, 'after' => 2], $events[1]->changes['total_guests']);
        $this->assertSame($editRequest, $events[1]->correlation_id);
        $this->assertEquals(['before' => 'booked', 'after' => 'checked_in'], $events[2]->changes['status']);
        $this->assertSame('checked_out', $events[3]->status_after);

        // The ledger is the source of truth: the event index has one row per event.
        $this->assertSame(4, $this->getTableLocator()->get('ActivityIndex')->find()
            ->where(['event_table' => 'reservation_events', 'subject_id' => $id])->count());
    }

    public function testASecondIdenticalRequestIsRefusedRecordsNothingAndChangesNothing(): void
    {
        // Check in twice, check out twice.
        $stay = $this->booking($this->roomId);
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$stay/check-in");
        $this->assertResponseOk();
        $this->assertRepeatChangesNothing($this->deskToken, 'POST', "/api/reservations/$stay/check-in", [], 'Check in');
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$stay/check-out");
        $this->assertResponseOk();
        $this->assertRepeatChangesNothing($this->deskToken, 'POST', "/api/reservations/$stay/check-out", [], 'Check out');

        // Cancel twice.
        $booking = $this->booking($this->roomId);
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$booking/cancel");
        $this->assertResponseOk();
        $this->assertRepeatChangesNothing($this->deskToken, 'POST', "/api/reservations/$booking/cancel", [], 'Cancel');

        // Backdate twice: the same past stay entered again.
        $past = [
            'room_id' => $this->otherRoomId, 'source' => 'walk_in', 'guest_name' => 'Paper Guest',
            'check_in' => $this->day(-6), 'check_out' => $this->day(-5), 'reason' => 'Paper log typed in',
        ];
        $this->callAs($this->adminToken, 'POST', '/api/reservations', $past);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $pastId = (int)$this->responseJson()['reservation']['id'];
        $this->assertSame(['backdated'], $this->types($pastId));
        $this->assertRepeatChangesNothing($this->adminToken, 'POST', '/api/reservations', $past, 'Backdate');

        // Correct twice.
        $correction = ['check_in' => $this->day(-7), 'reason' => 'Arrived a day earlier'];
        $this->callAs($this->adminToken, 'PATCH', "/api/reservations/$pastId", $correction);
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->assertSame(['backdated', 'corrected'], $this->types($pastId));
        $this->assertRepeatChangesNothing($this->adminToken, 'PATCH', "/api/reservations/$pastId", $correction, 'Correct');

        // Delete twice.
        $mistake = $this->booking($this->otherRoomId);
        $this->callAs($this->adminToken, 'DELETE', "/api/reservations/$mistake", ['reason' => 'Booked the wrong guest']);
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->assertSame(['booked', 'deleted'], $this->types($mistake));
        $this->assertRepeatChangesNothing(
            $this->adminToken,
            'DELETE',
            "/api/reservations/$mistake",
            ['reason' => 'Booked the wrong guest'],
            'Delete',
        );
    }

    public function testReasonsAreRequiredWhereTheStandardSaysSo(): void
    {
        $count = fn() => $this->getTableLocator()->get('Reservations')->find('all', withDeleted: true)
            ->where(['property_id' => $this->propertyId])->count();

        // A past stay.
        $this->callAs($this->adminToken, 'POST', '/api/reservations', [
            'room_id' => $this->roomId, 'source' => 'walk_in', 'check_in' => $this->day(-3), 'check_out' => $this->day(-1),
        ]);
        $this->assertResponseCode(400);
        $this->assertSame(0, $count(), 'nothing saved');

        // A referral discount (R3), at booking and on an edit.
        $referral = [
            'room_id' => $this->roomId, 'source' => 'agoda', 'booking_reference' => 'AG-REF',
            'check_in' => $this->day(0), 'check_out' => $this->day(1), 'discount_amount' => 200,
        ];
        $this->callAs($this->deskToken, 'POST', '/api/reservations', $referral);
        $this->assertResponseCode(400);
        $this->callAs($this->deskToken, 'POST', '/api/reservations', $referral + ['reason' => 'Referred by Mr. Santos']);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $id = (int)$this->responseJson()['reservation']['id'];
        $booked = $this->getTableLocator()->get('ReservationEvents')->find()->where(['reservation_id' => $id])->firstOrFail();
        $this->assertSame('Referred by Mr. Santos', $booked->reason);
        $this->assertEquals(200, $booked->changes['after']['discount_amount']);

        $this->callAs($this->deskToken, 'PATCH', "/api/reservations/$id", ['discount_amount' => 300]);
        $this->assertResponseCode(400);
        $this->callAs($this->deskToken, 'PATCH', "/api/reservations/$id", ['discount_amount' => 300, 'reason' => 'Agreed with the owner']);
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->assertSame(['booked', 'discount_changed'], $this->types($id));

        // A correction and a deletion (Manager actions).
        $past = $this->pastStay($this->otherRoomId);
        $this->callAs($this->adminToken, 'PATCH', "/api/reservations/$past", ['check_in' => $this->day(-5)]);
        $this->assertResponseCode(400);
        $this->callAs($this->adminToken, 'DELETE', "/api/reservations/$past");
        $this->assertResponseCode(400);
        $this->assertSame(['backdated'], $this->types($past));

        // A cancellation: a reason only once money was taken (R2).
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$id/cancel");
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->assertSame('cancelled', $this->types($id)[2]);

        $this->callAs($this->deskToken, 'POST', '/api/reservations', [
            'room_id' => $this->roomId, 'source' => 'walk_in', 'guest_name' => 'Charged Guest', 'check_out' => $this->day(1),
        ]);
        $walkIn = (int)$this->responseJson()['reservation']['id'];
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$walkIn/post-room-charge");
        $this->assertResponseOk();
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$walkIn/cancel");
        $this->assertResponseCode(400);
        $this->assertSame('checked_in', $this->getTableLocator()->get('Reservations')->get($walkIn)->status);
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$walkIn/cancel", ['reason' => 'Guest left after an hour']);
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->assertSame(['walked_in', 'cancelled_after_payment'], $this->types($walkIn));
        $cancel = $this->getTableLocator()->get('ReservationEvents')->find()
            ->where(['reservation_id' => $walkIn, 'event_type' => 'cancelled_after_payment'])->firstOrFail();
        $this->assertSame('Guest left after an hour', $cancel->reason);
        $this->assertTrue($cancel->changes['charges_reversed']);
        // Its reversal lines were recorded by the same request.
        $this->assertTrue($this->getTableLocator()->get('InvoiceEvents')->exists([
            'correlation_id' => $cancel->correlation_id, 'event_type' => 'line_reversed_on_cancel',
        ]));
    }

    public function testDiscountChangesKeepTheBeneficiariesBeforeAndAfter(): void
    {
        $this->callAs($this->deskToken, 'POST', '/api/reservations', [
            'room_id' => $this->roomId, 'source' => 'agoda', 'booking_reference' => 'AG-SR',
            'check_in' => $this->day(0), 'check_out' => $this->day(2), 'total_guests' => 2,
            'discount_beneficiaries' => [['discount_type' => 'senior', 'name' => 'Lola Ines', 'id_number' => 'SC-1']],
        ]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $id = (int)$this->responseJson()['reservation']['id'];

        $this->callAs($this->deskToken, 'PATCH', "/api/reservations/$id", [
            'discount_beneficiaries' => [['discount_type' => 'pwd', 'name' => 'Mang Ben', 'id_number' => 'PWD-9']],
        ]);
        $this->assertResponseOk((string)$this->_response->getBody());

        $event = $this->getTableLocator()->get('ReservationEvents')->find()
            ->where(['reservation_id' => $id, 'event_type' => 'discount_changed'])->firstOrFail();
        $this->assertEquals(
            [['discount_type' => 'senior', 'beneficiary_name' => 'Lola Ines', 'id_number' => 'SC-1']],
            $event->changes['beneficiaries']['before'],
        );
        $this->assertEquals(
            [['discount_type' => 'pwd', 'beneficiary_name' => 'Mang Ben', 'id_number' => 'PWD-9']],
            $event->changes['beneficiaries']['after'],
        );
        $this->assertNull($event->reason, 'statutory discounts follow the law: no reason asked');
    }

    public function testADeletedReservationIsHiddenEverywhereButKept(): void
    {
        $this->callAs($this->deskToken, 'POST', '/api/reservations', [
            'room_id' => $this->roomId, 'source' => 'walk_in', 'guest_name' => 'Mistaken Guest', 'check_out' => $this->day(1),
            'total_guests' => 1,
            'discount_beneficiaries' => [['discount_type' => 'senior', 'name' => 'Mistaken Guest', 'id_number' => 'SC-7']],
        ]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $id = (int)$this->responseJson()['reservation']['id'];
        $this->callAs($this->adminToken, 'GET', '/api/guests/stats');
        $inHouse = (int)$this->responseJson()['stats']['inHouse'];
        $this->assertSame('occupied', $this->getTableLocator()->get('Rooms')->get($this->roomId)->status);

        $this->callAs($this->adminToken, 'DELETE', "/api/reservations/$id", ['reason' => 'Typed into the wrong property']);
        $this->assertResponseOk((string)$this->_response->getBody());

        // Hidden from the list, the calendar, stats, guests in house, and the room is free again.
        $this->callAs($this->adminToken, 'GET', '/api/reservations?limit=100');
        $this->assertNotContains($id, array_map('intval', array_column($this->responseJson()['reservations'], 'id')));
        $this->callAs($this->adminToken, 'GET', '/api/reservations?on_date=' . $this->day(0));
        $this->assertNotContains($id, array_map('intval', array_column($this->responseJson()['reservations'], 'id')));
        $this->callAs($this->adminToken, 'GET', '/api/guests/stats');
        $this->assertSame($inHouse - 1, (int)$this->responseJson()['stats']['inHouse']);
        $this->assertSame('available', $this->getTableLocator()->get('Rooms')->get($this->roomId)->status);
        $this->callAs($this->deskToken, 'POST', '/api/reservations', [
            'room_id' => $this->roomId, 'source' => 'walk_in', 'guest_name' => 'Real Guest', 'check_out' => $this->day(1),
        ]);
        $this->assertResponseCode(201, 'the room can be booked again');

        // Nothing can act on it any more.
        $this->callAs($this->adminToken, 'PATCH', "/api/reservations/$id", ['total_guests' => 2, 'reason' => 'x']);
        $this->assertResponseCode(404);
        $this->callAs($this->adminToken, 'POST', "/api/reservations/$id/cancel", ['reason' => 'x']);
        $this->assertResponseCode(404);

        // But it, its discount rows and its history are all kept.
        $row = $this->getTableLocator()->get('Reservations')->find('all', withDeleted: true)
            ->where(['id' => $id])->firstOrFail();
        $this->assertNotNull($row->deleted_at);
        $this->assertSame(1, $this->getTableLocator()->get('ReservationDiscounts')->find()->where(['reservation_id' => $id])->count());
        $deleted = $this->getTableLocator()->get('ReservationEvents')->find()
            ->where(['reservation_id' => $id, 'event_type' => 'deleted'])->firstOrFail();
        $this->assertSame('Typed into the wrong property', $deleted->reason);
        $this->assertSame('admin', $deleted->actor_role);
        $this->assertSame('SC-7', $deleted->changes['before']['beneficiaries'][0]['id_number']);
    }

    public function testACheckOutAndItsRoomChargeAreOneAction(): void
    {
        $this->callAs($this->deskToken, 'POST', '/api/reservations', [
            'room_id' => $this->roomId, 'source' => 'walk_in', 'guest_name' => 'Leaving Guest', 'check_out' => $this->day(1),
        ]);
        $id = (int)$this->responseJson()['reservation']['id'];
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$id/check-out");
        $this->assertResponseOk((string)$this->_response->getBody());
        $requestId = $this->_response->getHeaderLine('X-Request-Id');

        $event = $this->getTableLocator()->get('ReservationEvents')->find()
            ->where(['reservation_id' => $id, 'event_type' => 'checked_out'])->firstOrFail();
        $this->assertSame($requestId, $event->correlation_id);
        $this->assertTrue($event->changes['room_charge_posted']);
        $this->assertTrue($this->getTableLocator()->get('InvoiceEvents')->exists([
            'correlation_id' => $requestId, 'event_type' => 'line_added',
        ]), 'the room charge posted by the check-out shares its request id');
    }

    // ---- Part 2: timeline, feed, staff actions, the Deleted view ---------

    public function testTheTimelineShowsTheReservationAndItsMoneyInOrder(): void
    {
        $this->callAs($this->deskToken, 'POST', '/api/reservations', [
            'room_id' => $this->roomId, 'source' => 'walk_in', 'guest_name' => 'Timeline Guest', 'check_out' => $this->day(1),
        ]);
        $id = (int)$this->responseJson()['reservation']['id'];
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$id/check-out");
        $this->assertResponseOk((string)$this->_response->getBody());
        $checkOutRequest = $this->_response->getHeaderLine('X-Request-Id');
        $invoiceId = (int)$this->getTableLocator()->get('InvoiceLines')->find()
            ->where(['source_type' => 'reservation', 'source_id' => $id])->firstOrFail()->invoice_id;
        $this->callAs($this->deskToken, 'POST', "/api/invoices/$invoiceId/settle");
        $this->assertResponseOk();

        $this->callAs($this->deskToken, 'GET', "/api/reservations/$id/history");
        $this->assertResponseOk((string)$this->_response->getBody());
        $body = $this->responseJson();
        $this->assertSame($id, $body['reservation_id']);
        $this->assertFalse($body['deleted']);
        $timeline = array_map(fn($h) => $h['source'] . ':' . $h['event'], $body['history']);
        $this->assertSame(
            ['reservation:walked_in', 'reservation:checked_out', 'invoice:line_added', 'invoice:settled'],
            array_values(array_filter($timeline, fn($t) => $t !== 'invoice:opened')),
            'its own events and its money, oldest first; within the check-out, the reservation event leads',
        );
        $byEvent = array_column($body['history'], null, 'event');
        $this->assertStringContainsString('resv-desk', (string)$byEvent['walked_in']['actor']);
        $this->assertTrue($byEvent['walked_in']['recorded']);
        $this->assertSame($checkOutRequest, $byEvent['checked_out']['correlation_id']);
        $this->assertSame($checkOutRequest, $byEvent['line_added']['correlation_id'], 'one action, one request id');
        $this->assertEquals(1000, $byEvent['line_added']['amount']);
        $this->assertNotNull($byEvent['line_added']['line']);
    }

    public function testDeletedReservationsAreAManagersReadOnlyView(): void
    {
        $id = $this->booking($this->roomId);
        $this->callAs($this->adminToken, 'DELETE', "/api/reservations/$id", ['reason' => 'Duplicate booking']);
        $this->assertResponseOk();

        $this->callAs($this->adminToken, 'GET', '/api/reservations?deleted=only');
        $this->assertResponseOk();
        $this->assertSame([$id], array_map('intval', array_column($this->responseJson()['reservations'], 'id')));
        $this->callAs($this->deskToken, 'GET', '/api/reservations?deleted=only');
        $this->assertResponseCode(403);

        $this->callAs($this->adminToken, 'GET', "/api/reservations/$id/history");
        $this->assertResponseOk();
        $this->assertTrue($this->responseJson()['deleted']);
        $this->assertSame(['booked', 'deleted'], array_column($this->responseJson()['history'], 'event'));
        $this->assertSame('Duplicate booking', $this->responseJson()['history'][1]['reason']);
        $this->callAs($this->deskToken, 'GET', "/api/reservations/$id/history");
        $this->assertResponseCode(404, 'Front Desk Staff never see a deleted reservation');
    }

    public function testReservationEventsJoinTheActivityFeed(): void
    {
        $id = $this->booking($this->roomId);
        $this->callAs($this->deskToken, 'PATCH', "/api/reservations/$id", ['total_guests' => 2]);
        $this->callAs($this->deskToken, 'PATCH', "/api/reservations/$id", ['discount_amount' => 150, 'reason' => 'Referred by a regular']);
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$id/check-in");
        $this->assertResponseOk();

        $this->callAs($this->adminToken, 'GET', '/api/operations/activity');
        $this->assertResponseOk();
        $lines = array_values(array_filter(
            $this->responseJson()['activity'],
            fn($e) => $e['type'] === 'reservation' && $e['reservation_id'] === $id,
        ));
        $this->assertSame(['checked_in', 'discount_changed', 'booked'], array_column($lines, 'event'), 'newest first; a plain edit stays out');
        $this->assertSame('E-1', $lines[0]['room']);
        $this->assertStringContainsString('resv-desk', (string)$lines[0]['actor']);
        $this->assertSame('Referred by a regular', $lines[1]['reason']);
        $this->assertContains('discount_amount', $lines[1]['changed']);
    }

    public function testStaffActionsCountEveryRequestOnceAcrossLedgers(): void
    {
        $id = $this->booking($this->roomId);
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$id/check-in");
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$id/check-out");
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$id/check-out"); // refused: records nothing

        $this->callAs($this->adminToken, 'GET', '/api/operations/today');
        $this->assertResponseOk();
        $members = array_column($this->responseJson()['operations']['staff']['members'], null, 'id');
        $this->assertSame(3, $members[$this->userIdFor($this->deskToken)]['actions_today'], 'book, check in, check out');
        $this->assertSame(0, $members[$this->userIdFor($this->adminToken)]['actions_today']);
    }

    public function testEmbeddedStaffCarryOnlyTheirName(): void
    {
        $this->booking($this->roomId);
        $this->callAs($this->adminToken, 'GET', '/api/reservations?limit=25');
        $this->assertResponseOk();
        $row = $this->responseJson()['reservations'][0];
        // G8b: the legacy "last touched by" and Mark paid flag are no longer answered.
        foreach (['receptionist', 'receptionist_id', 'payment_status'] as $legacy) {
            $this->assertArrayNotHasKey($legacy, $row);
        }
        $this->assertSame(['name', 'recorded', 'at'], array_keys($row['booked_by']), 'only the name of who booked it');
        // Who booked it comes from its creation event, not the legacy column.
        $this->assertStringContainsString('resv-desk', (string)$row['booked_by']['name']);
        $this->assertTrue($row['booked_by']['recorded']);
    }
}
