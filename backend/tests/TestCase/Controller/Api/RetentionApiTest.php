<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Event\EventContext;
use App\Model\Table\AccessEventsTable;
use App\Model\Table\GuestEventsTable;
use App\Privacy\RetentionRoutine;
use Cake\Database\Connection;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use LogicException;

/**
 * The retention routine (final review G5, approved 2026-10-09 as P1–P6):
 * sign-in device details are cleared 12 months after the event, guest
 * contact values in guest history 24 months after; the events stay, with
 * their actor, type, time, reason, request id and which fields changed, and
 * `redacted_at` says when. A dry run changes nothing; every run is recorded;
 * a second run clears nothing; ordinary updates of an event stay refused.
 * Guest search and matching travel in POST bodies (P6).
 */
class RetentionApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private string $adminToken;
    private string $deskToken;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Retention Inn');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "ret-admin-$tag@example.test");
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', "ret-desk-$tag@example.test");
        /** @var \Cake\Database\Connection $connection */
        $connection = $this->getTableLocator()->get('AccessEvents')->getConnection();
        $this->connection = $connection;
    }

    protected function tearDown(): void
    {
        $this->connection->delete('retention_runs', ['property_id' => $this->propertyId]);
        $this->cleanupScenario();
        parent::tearDown();
    }

    private function context(string $at, ?string $reason = null): EventContext
    {
        $deskId = $this->userIdFor($this->deskToken);

        return new EventContext($deskId, 'receptionist', $this->propertyId, 'req-' . uniqid(), EventContext::SOURCE_WEB, $reason, new DateTime($at));
    }

    private function signIn(string $at): EntityInterface
    {
        $user = $this->getTableLocator()->get('Users')->get($this->userIdFor($this->deskToken));
        /** @var \App\Model\Table\AccessEventsTable $events */
        $events = $this->getTableLocator()->get('AccessEvents');

        return $this->connection->transactional(fn() => $events->record($this->context($at), AccessEventsTable::SIGNED_IN, $user, [
            'columns' => ['user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130', 'client_address' => '203.0.113.7'],
        ]));
    }

    private function guestEvent(string $at, string $type, array $changes, ?string $reason = null): EntityInterface
    {
        $guestId = $this->insertRow('Guests', [
            'property_id' => $this->propertyId, 'full_name' => 'Ana Cruz', 'guest_type' => 'local',
        ]);
        $guest = $this->getTableLocator()->get('Guests')->get($guestId);
        /** @var \App\Model\Table\GuestEventsTable $events */
        $events = $this->getTableLocator()->get('GuestEvents');

        return $this->connection->transactional(fn() => $events->record($this->context($at, $reason), $type, $guest, ['changes' => $changes]));
    }

    private function reload(string $alias, EntityInterface $event): EntityInterface
    {
        return $this->getTableLocator()->get($alias)->get($event->id);
    }

    public function testDeviceDetailsAreClearedAfter12MonthsAndTheEventStays(): void
    {
        $old = $this->signIn('-13 months');
        $recent = $this->signIn('-11 months');
        $routine = new RetentionRoutine($this->connection);

        $result = $routine->run(false, $this->propertyId);
        $this->assertSame(1, $result[RetentionRoutine::ACCESS_DEVICE_DETAILS]['rows']);

        $cleared = $this->reload('AccessEvents', $old);
        $this->assertNull($cleared->user_agent);
        $this->assertNull($cleared->client_address);
        $this->assertNotNull($cleared->redacted_at, 'recorded and later cleared, not never recorded');
        $this->assertSame(
            [$old->event_type, (int)$old->actor_id, $old->actor_role, $old->correlation_id, $old->occurred_at->format('c')],
            [$cleared->event_type, (int)$cleared->actor_id, $cleared->actor_role, $cleared->correlation_id, $cleared->occurred_at->format('c')],
            'who, what, when and the request id stay',
        );
        $kept = $this->reload('AccessEvents', $recent);
        $this->assertSame('203.0.113.7', $kept->client_address, 'inside 12 months: untouched');
        $this->assertNull($kept->redacted_at);

        $this->assertSame(0, $routine->run(false, $this->propertyId)[RetentionRoutine::ACCESS_DEVICE_DETAILS]['rows'], 'a second run clears nothing');
        $this->assertTrue($routine->check($this->propertyId)[RetentionRoutine::ACCESS_DEVICE_DETAILS]['complete']);

        $this->callAs($this->adminToken, 'GET', '/api/users/' . $this->userIdFor($this->deskToken) . '/access-history');
        $lines = array_column($this->responseJson()['events'], null, 'id');
        $this->assertNotNull($lines[$old->id]['redacted_at']);
        $this->assertNull($lines[$old->id]['user_agent']);
        $this->assertNull($lines[$recent->id]['redacted_at']);
    }

    public function testGuestContactValuesAreClearedAfter24MonthsKeepingNamesFieldsAndReasons(): void
    {
        $registered = $this->guestEvent('-25 months', GuestEventsTable::REGISTERED, ['via' => 'guests', 'after' => [
            'full_name' => 'Ana Cruz', 'email' => 'ana@example.test', 'contact_number' => '0917', 'address' => 'Manila',
            'nationality' => 'PH', 'guest_type' => 'local',
        ]]);
        $updated = $this->guestEvent('-25 months', GuestEventsTable::DETAILS_UPDATED, [
            'contact_number' => ['before' => '0917', 'after' => '0920'],
            'guest_type' => ['before' => 'local', 'after' => 'foreign'],
        ]);
        $renamed = $this->guestEvent('-25 months', GuestEventsTable::RENAMED, [
            'full_name' => ['before' => 'Ana Cruz', 'after' => 'Ana C. Cruz'],
        ], 'Legal name per ID');
        $recent = $this->guestEvent('-23 months', GuestEventsTable::DETAILS_UPDATED, [
            'email' => ['before' => 'a@example.test', 'after' => 'b@example.test'],
        ]);

        $result = (new RetentionRoutine($this->connection))->run(false, $this->propertyId);
        $this->assertSame(2, $result[RetentionRoutine::GUEST_CONTACT_VALUES]['rows']);

        $r = $this->reload('GuestEvents', $registered);
        $this->assertSame('Ana Cruz', $r->changes['after']['full_name'], 'names are kept (P2)');
        $this->assertSame('local', $r->changes['after']['guest_type']);
        foreach (['email', 'contact_number', 'address', 'nationality'] as $field) {
            $this->assertArrayHasKey($field, $r->changes['after'], "$field: the field is still named");
            $this->assertNull($r->changes['after'][$field], "$field: its value is cleared");
        }
        $this->assertSame('guests', $r->changes['via']);
        $this->assertNotNull($r->redacted_at);

        $u = $this->reload('GuestEvents', $updated);
        $this->assertEquals(['before' => null, 'after' => null], $u->changes['contact_number'], 'which field changed stays');
        $this->assertEquals(['before' => 'local', 'after' => 'foreign'], $u->changes['guest_type'], 'not a contact value');

        $n = $this->reload('GuestEvents', $renamed);
        $this->assertNull($n->redacted_at, 'a rename holds no contact value');
        $this->assertSame('Legal name per ID', $n->reason);
        $this->assertEquals(['before' => 'Ana Cruz', 'after' => 'Ana C. Cruz'], $n->changes['full_name']);

        $this->assertSame('b@example.test', $this->reload('GuestEvents', $recent)->changes['email']['after'], 'inside 24 months: untouched');
    }

    public function testADryRunChangesNothingAndEveryRunIsRecorded(): void
    {
        $old = $this->signIn('-13 months');
        $routine = new RetentionRoutine($this->connection);

        $dry = $routine->run(true, $this->propertyId);
        $this->assertSame(1, $dry[RetentionRoutine::ACCESS_DEVICE_DETAILS]['rows'], 'would clear one');
        $this->assertSame('203.0.113.7', $this->reload('AccessEvents', $old)->client_address, 'but changes nothing');
        $this->assertFalse($routine->check($this->propertyId)[RetentionRoutine::ACCESS_DEVICE_DETAILS]['complete']);

        $routine->run(false, $this->propertyId);
        $runs = $this->getTableLocator()->get('RetentionRuns')->find()
            ->where(['property_id' => $this->propertyId, 'policy' => RetentionRoutine::ACCESS_DEVICE_DETAILS])
            ->orderBy(['id' => 'ASC'])->all()->toList();
        $this->assertSame([[true, 1], [false, 1]], array_map(fn($r) => [(bool)$r->dry_run, (int)$r->rows_cleared], $runs));
        $this->assertSame('access_events', $runs[1]->table_name);
        $this->assertStringStartsWith('retention-', $runs[1]->correlation_id);
    }

    public function testOrdinaryUpdatesOfAnEventStayRefused(): void
    {
        $event = $this->signIn('-13 months');
        $events = $this->getTableLocator()->get('AccessEvents');
        $refused = 0;
        try {
            $events->updateAll(['client_address' => null], ['id' => $event->id]);
        } catch (LogicException) {
            $refused++;
        }
        try {
            $row = $events->get($event->id);
            $row->set('client_address', null);
            $events->save($row);
        } catch (LogicException) {
            $refused++;
        }
        $this->assertSame(2, $refused, 'only the retention routine may clear a value');
        $this->assertSame('203.0.113.7', $this->reload('AccessEvents', $event)->client_address);
    }

    public function testGuestSearchAndMatchingTravelInTheBody(): void
    {
        $this->callAs($this->deskToken, 'POST', '/api/guests', [
            'full_name' => 'Body Search', 'email' => 'body@example.test', 'guest_type' => 'local',
        ]);
        $this->assertResponseCode(201);

        $this->callAs($this->deskToken, 'POST', '/api/guests/search', ['q' => 'Body Sea']);
        $this->assertResponseOk();
        $this->assertSame(['Body Search'], array_column($this->responseJson()['guests'], 'full_name'));

        $this->callAs($this->deskToken, 'POST', '/api/guests/match', ['full_name' => 'Body Search', 'email' => 'body@example.test']);
        $this->assertResponseOk();
        $this->assertCount(1, $this->responseJson()['duplicates']);
    }
}
