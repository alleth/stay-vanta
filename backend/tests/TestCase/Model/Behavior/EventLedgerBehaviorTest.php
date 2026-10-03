<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Behavior;

use App\Event\EventContext;
use App\Event\ReasonRequiredException;
use App\Model\Table\FoodOrderEventsTable;
use App\Test\TestCase\Controller\Api\ApiScenarioTrait;
use App\Test\TestSuite\StrictFoodOrderEventsTable;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;
use LogicException;

/**
 * The event foundation's guarantees, tested on the first real ledger
 * (food_order_events): one way in, same transaction, append-only, reasons,
 * minimal snapshots, and correlation ids that reconstruct an action.
 */
class EventLedgerBehaviorTest extends TestCase
{
    use ApiScenarioTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private int $actorId;
    private FoodOrderEventsTable $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->propertyId = $this->createProperty('Ledger Resort');
        $token = $this->makeUser($this->propertyId, 'admin', 'ledger-' . uniqid() . '@example.test');
        $this->actorId = $this->userIdFor($token);
        /** @var \App\Model\Table\FoodOrderEventsTable $events */
        $events = $this->getTableLocator()->get('FoodOrderEvents');
        $this->events = $events;
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    private function context(string $correlationId = 'corr-ledger', ?string $reason = null, ?int $propertyId = null): EventContext
    {
        return new EventContext($this->actorId, 'admin', $propertyId ?? $this->propertyId, $correlationId, reason: $reason);
    }

    /**
     * @param array<string, mixed> $extra Overrides.
     */
    private function order(array $extra = []): EntityInterface
    {
        $id = $this->insertRow('FoodOrders', $extra + [
            'property_id' => $this->propertyId, 'receptionist_id' => $this->actorId,
            'status' => 'open', 'payment_status' => 'paid', 'payment_method' => 'cash',
            'total' => 245.5, 'total_diners' => 1,
        ]);

        return $this->getTableLocator()->get('FoodOrders')->get($id);
    }

    private function inTransaction(callable $work): mixed
    {
        return $this->events->getConnection()->transactional($work);
    }

    public function testRecordsTheEventAndItsIndexRowTogether(): void
    {
        $order = $this->order();
        $context = $this->context('corr-record');
        $event = $this->inTransaction(fn() => $this->events->record($context, FoodOrderEventsTable::PLACED, $order, [
            'columns' => ['amount' => 245.5],
        ]));

        $stored = $this->events->get($event->id);
        $this->assertSame($this->propertyId, (int)$stored->property_id);
        $this->assertSame((int)$order->id, (int)$stored->food_order_id);
        $this->assertSame('placed', $stored->event_type);
        $this->assertSame($this->actorId, (int)$stored->actor_id);
        $this->assertSame('admin', $stored->actor_role);
        $this->assertSame('web', $stored->source);
        $this->assertSame('corr-record', $stored->correlation_id);
        $this->assertNull($stored->reason);
        $this->assertSame(245.5, (float)$stored->amount);
        $this->assertSame($context->now->format('Y-m-d H:i:s'), $stored->occurred_at->format('Y-m-d H:i:s'));

        $index = $this->getTableLocator()->get('ActivityIndex')->find()
            ->where(['event_table' => 'food_order_events', 'event_id' => $event->id])->firstOrFail();
        $this->assertSame('food_order', $index->subject_type);
        $this->assertSame((int)$order->id, (int)$index->subject_id);
        $this->assertSame('placed', $index->event_type);
        $this->assertSame('corr-record', $index->correlation_id);
        $this->assertSame($this->actorId, (int)$index->actor_id);
        $this->assertSame(245.5, $index->summary['total']);
    }

    public function testRefusesToRecordOutsideATransaction(): void
    {
        $this->expectException(LogicException::class);
        $this->events->record($this->context(), FoodOrderEventsTable::PLACED, $this->order());
    }

    public function testIfTheEventFailsTheChangeRollsBack(): void
    {
        $order = $this->order();
        try {
            $this->inTransaction(function () use ($order): void {
                $this->getTableLocator()->get('FoodOrders')->updateAll(['status' => 'served'], ['id' => $order->id]);
                $this->events->record($this->context(), 'not_a_type', $order);
            });
            $this->fail('an unknown event type was recorded');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('open', $this->getTableLocator()->get('FoodOrders')->get($order->id)->status);
        $this->assertSame(0, $this->events->find()->where(['food_order_id' => $order->id])->count());
    }

    public function testATypeThatRequiresAReasonIsRefusedWithoutOne(): void
    {
        /** @var \App\Model\Table\FoodOrderEventsTable $strict */
        $strict = $this->getTableLocator()->get('StrictFoodOrderEvents', ['className' => StrictFoodOrderEventsTable::class]);
        $order = $this->order(['status' => 'served']);

        try {
            $this->inTransaction(fn() => $strict->record(
                $this->context(),
                FoodOrderEventsTable::CANCELLED_AFTER_PAYMENT,
                $order,
            ));
            $this->fail('recorded without a reason');
        } catch (ReasonRequiredException) {
            $this->addToAssertionCount(1);
        }

        $event = $this->inTransaction(fn() => $strict->record(
            $this->context(reason: 'Guest was double-charged'),
            FoodOrderEventsTable::CANCELLED_AFTER_PAYMENT,
            $order,
        ));
        $this->assertSame('Guest was double-charged', $this->events->get($event->id)->reason);
    }

    public function testDuringTheGraceWindowTheReasonMayBeMissing(): void
    {
        $event = $this->inTransaction(fn() => $this->events->record(
            $this->context(),
            FoodOrderEventsTable::CANCELLED_AFTER_PAYMENT,
            $this->order(['status' => 'served']),
        ));
        $this->assertNull($this->events->get($event->id)->reason);
    }

    public function testLedgerRowsCanNeitherChangeNorBeRemoved(): void
    {
        $event = $this->inTransaction(fn() => $this->events->record(
            $this->context(),
            FoodOrderEventsTable::PLACED,
            $this->order(),
        ));
        $index = $this->getTableLocator()->get('ActivityIndex');
        $indexRow = $index->find()->where(['event_id' => $event->id, 'event_table' => 'food_order_events'])->firstOrFail();

        $attempts = [
            'save an event' => function () use ($event): void {
                $event->set('event_type', 'served');
                $this->events->save($event);
            },
            'delete an event' => fn() => $this->events->delete($event),
            'updateAll events' => fn() => $this->events->updateAll(['reason' => 'x'], ['id' => $event->id]),
            'deleteAll events' => fn() => $this->events->deleteAll(['id' => $event->id]),
            'save an index row' => function () use ($index, $indexRow): void {
                $indexRow->set('event_type', 'served');
                $index->save($indexRow);
            },
            'delete an index row' => fn() => $index->delete($indexRow),
            'deleteAll index rows' => fn() => $index->deleteAll(['id' => $indexRow->id]),
        ];
        foreach ($attempts as $label => $attempt) {
            try {
                $attempt();
                $this->fail("$label was allowed");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('placed', $this->events->get($event->id)->event_type);
    }

    public function testAnEventCannotBeRecordedForAnotherProperty(): void
    {
        $this->expectException(LogicException::class);
        $this->inTransaction(fn() => $this->events->record(
            $this->context(propertyId: $this->propertyId + 100000),
            FoodOrderEventsTable::PLACED,
            $this->order(),
        ));
    }

    public function testAnUnknownTypedColumnIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->inTransaction(fn() => $this->events->record($this->context(), FoodOrderEventsTable::PLACED, $this->order(), [
            'columns' => ['not_a_column' => 1],
        ]));
    }

    public function testSnapshotsKeepOnlyTheMinimum(): void
    {
        $guestId = $this->insertRow('Guests', [
            'property_id' => $this->propertyId, 'full_name' => 'Ana Cruz', 'guest_type' => 'local',
            'contact_number' => '09170000000', 'email' => 'ana@example.test',
        ]);
        $roomId = $this->insertRow('Rooms', [
            'property_id' => $this->propertyId, 'room_number' => 'L-7', 'room_type' => 'Deluxe', 'status' => 'occupied',
        ]);
        $event = $this->inTransaction(fn() => $this->events->record(
            $this->context(),
            FoodOrderEventsTable::PLACED,
            $this->order(['guest_id' => $guestId, 'room_id' => $roomId, 'payment_status' => 'charge_to_room']),
        ));

        // MySQL reorders JSON keys, so compare as a map, then the exact key set.
        $snapshot = $this->events->get($event->id)->snapshot;
        $this->assertEquals([
            'total' => 245.5, 'status' => 'open', 'payment_status' => 'charge_to_room', 'room' => 'L-7',
            'guest_id' => $guestId, 'guest_name' => 'Ana Cruz',
        ], $snapshot);
        $keys = array_keys($snapshot);
        sort($keys);
        $this->assertSame(['guest_id', 'guest_name', 'payment_status', 'room', 'status', 'total'], $keys);
    }

    public function testOneCorrelationIdReconstructsTheWholeAction(): void
    {
        $order = $this->order();
        $other = $this->order();
        $this->inTransaction(function () use ($order, $other): void {
            $this->events->record($this->context('corr-sale'), FoodOrderEventsTable::PLACED, $order);
            $this->events->record($this->context('corr-other'), FoodOrderEventsTable::PLACED, $other);
            $this->events->record($this->context('corr-sale'), FoodOrderEventsTable::SERVED, $order);
        });

        /** @var \App\Model\Table\ActivityIndexTable $index */
        $index = $this->getTableLocator()->get('ActivityIndex');
        $action = $index->forCorrelation($this->propertyId, 'corr-sale');

        $this->assertSame(['placed', 'served'], array_map(fn($row) => $row->event_type, $action));
        $this->assertSame([(int)$order->id, (int)$order->id], array_map(fn($row) => (int)$row->subject_id, $action));
        $this->assertSame([], $index->forCorrelation($this->propertyId + 100000, 'corr-sale'), 'scoped to the property');
    }
}
