<?php
declare(strict_types=1);

namespace App\Test\TestCase\Event;

use App\Event\ActivityBackfill;
use App\Event\EventContext;
use App\Test\TestCase\Controller\Api\ApiScenarioTrait;
use Cake\Database\Connection;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * The backfill rule: idempotent, auditable, repeatable, and it never invents
 * a fact the history didn't record.
 */
class ActivityBackfillTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private int $otherId;
    private int $deskId;
    private string $deskToken;
    private int $itemId;
    private int $guestId;
    private int $roomId;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->propertyId = $this->createProperty('Backfill Resort');
        $this->otherId = $this->createProperty('Backfill Other');
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', 'bf-desk-' . uniqid() . '@example.test');
        $this->deskId = $this->userIdFor($this->deskToken);
        $categoryId = $this->insertRow('InventoryCategories', [
            'property_id' => $this->propertyId, 'name' => 'Linens', 'kind' => 'linen',
        ]);
        $this->itemId = $this->insertRow('InventoryItems', [
            'property_id' => $this->propertyId, 'inventory_category_id' => $categoryId, 'name' => 'Pillow case',
            'unit' => 'pc', 'quantity' => 50, 'tracking_type' => 'consumable',
        ]);
        $this->guestId = $this->insertRow('Guests', [
            'property_id' => $this->propertyId, 'full_name' => 'Ben Reyes', 'guest_type' => 'local',
            'contact_number' => '09180000000',
        ]);
        $this->roomId = $this->insertRow('Rooms', [
            'property_id' => $this->propertyId, 'room_number' => 'B-3', 'room_type' => 'Twin', 'status' => 'occupied',
        ]);
        /** @var \Cake\Database\Connection $connection */
        $connection = $this->getTableLocator()->get('Properties')->getConnection();
        $this->connection = $connection;
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    private function historicalMovement(?DateTime $created, int $propertyId = 0): int
    {
        // Straight into the table, as rows from before step 5 were written.
        $this->connection->insert('stock_movements', [
            'property_id' => $propertyId ?: $this->propertyId, 'inventory_item_id' => $this->itemId,
            'receptionist_id' => $this->deskId, 'direction' => 'out', 'quantity' => 2, 'reason' => 'issued',
            'created' => $created?->format('Y-m-d H:i:s'),
        ]);

        return (int)$this->connection->getDriver()->lastInsertId();
    }

    private function historicalOrder(): int
    {
        return $this->insertRow('FoodOrders', [
            'property_id' => $this->propertyId, 'receptionist_id' => $this->deskId, 'guest_id' => $this->guestId,
            'room_id' => $this->roomId, 'status' => 'cancelled', 'payment_status' => 'charge_to_room',
            'total' => 410.5, 'total_diners' => 2,
            'created' => new DateTime('2026-08-20 10:00:00'), 'modified' => new DateTime('2026-08-21 09:00:00'),
        ]);
    }

    private function backfill(?int $propertyId = null): array
    {
        return (new ActivityBackfill($this->connection))->run($propertyId ?? $this->propertyId);
    }

    public function testASecondRunAddsNothing(): void
    {
        $this->historicalMovement(new DateTime('2026-08-01 08:00:00'));
        $this->historicalMovement(new DateTime('2026-08-02 08:00:00'));
        $this->historicalOrder();

        $this->assertSame(['placed_events' => 1, 'order_index' => 1, 'stock_index' => 2, 'invoice_opened_events' => 0, 'invoice_line_events' => 0, 'invoice_settled_events' => 0, 'invoice_index' => 0, 'reservation_created_events' => 0, 'reservation_checked_in_events' => 0, 'reservation_checked_out_events' => 0, 'reservation_cancelled_events' => 0, 'reservation_index' => 0], $this->backfill());
        $this->assertSame(['placed_events' => 0, 'order_index' => 0, 'stock_index' => 0, 'invoice_opened_events' => 0, 'invoice_line_events' => 0, 'invoice_settled_events' => 0, 'invoice_index' => 0, 'reservation_created_events' => 0, 'reservation_checked_in_events' => 0, 'reservation_checked_out_events' => 0, 'reservation_cancelled_events' => 0, 'reservation_index' => 0], $this->backfill());

        $counts = (new ActivityBackfill($this->connection))->check($this->propertyId)[$this->propertyId];
        $this->assertSame(2, $counts['stock_indexed']);
        $this->assertSame(1, $counts['placed']);
        $this->assertSame(1, $counts['placed_indexed']);
    }

    public function testImportedRowsAreMarkedAndInventNothing(): void
    {
        $movementId = $this->historicalMovement(new DateTime('2026-08-01 08:00:00'));
        $orderId = $this->historicalOrder();
        $this->backfill();

        $placed = $this->getTableLocator()->get('FoodOrderEvents')->find()->where(['food_order_id' => $orderId])->firstOrFail();
        $this->assertSame('placed', $placed->event_type);
        $this->assertSame('import', $placed->source);
        $this->assertSame("import-food_orders-$orderId", $placed->correlation_id);
        $this->assertSame($this->deskId, (int)$placed->actor_id, 'the actor history recorded');
        $this->assertNull($placed->actor_role, 'their role then was never recorded');
        $this->assertNull($placed->reason);
        $this->assertSame('2026-08-20 10:00:00', $placed->occurred_at->format('Y-m-d H:i:s'), 'when it was placed');
        $this->assertSame(410.5, (float)$placed->amount);
        $keys = array_keys($placed->snapshot);
        sort($keys);
        $this->assertSame(['guest_id', 'guest_name', 'room', 'total'], $keys, 'no status "as it was": unknown');
        $this->assertSame('Ben Reyes', $placed->snapshot['guest_name']);

        $index = $this->getTableLocator()->get('ActivityIndex')->find()
            ->where(['event_table' => 'stock_movements', 'event_id' => $movementId])->firstOrFail();
        $this->assertSame('moved_out', $index->event_type);
        $this->assertSame("import-stock_movements-$movementId", $index->correlation_id);
        $this->assertSame($this->deskId, (int)$index->actor_id);
        $this->assertSame('Pillow case', $index->summary['item']);

        $movement = $this->getTableLocator()->get('StockMovements')->get($movementId);
        $this->assertNull($movement->correlation_id, 'the ledger row itself is never changed');
        $this->assertNull($movement->source);
    }

    public function testWhatWasRecordedLiveIsNotDoubled(): void
    {
        $menuId = $this->insertRow('FoodMenuItems', [
            'property_id' => $this->propertyId, 'name' => 'Pillow rental', 'price' => 50, 'type' => 'linen',
            'inventory_item_id' => $this->itemId, 'is_available' => true,
        ]);
        $this->callAs($this->deskToken, 'POST', '/api/food-orders', [
            'items' => [['food_menu_item_id' => $menuId, 'quantity' => 1]],
            'payment_status' => 'paid', 'payment_method' => 'cash',
        ]);
        $this->assertResponseCode(201);
        $indexed = $this->getTableLocator()->get('ActivityIndex')->find()->where(['property_id' => $this->propertyId])->count();

        $this->assertSame(['placed_events' => 0, 'order_index' => 0, 'stock_index' => 0, 'invoice_opened_events' => 0, 'invoice_line_events' => 0, 'invoice_settled_events' => 0, 'invoice_index' => 0, 'reservation_created_events' => 0, 'reservation_checked_in_events' => 0, 'reservation_checked_out_events' => 0, 'reservation_cancelled_events' => 0, 'reservation_index' => 0], $this->backfill());
        $this->assertSame(
            $indexed,
            $this->getTableLocator()->get('ActivityIndex')->find()->where(['property_id' => $this->propertyId])->count(),
        );
    }

    public function testAMovementWithNoTimeIsLeftOutAndCounted(): void
    {
        $this->historicalMovement(null);
        $this->assertSame(0, $this->backfill()['stock_index'], 'no time is invented for it');

        $counts = (new ActivityBackfill($this->connection))->check($this->propertyId)[$this->propertyId];
        $this->assertSame(1, $counts['stock']);
        $this->assertSame(1, $counts['stock_undated']);
        $this->assertSame(0, $counts['stock_indexed']);
    }

    public function testOnePropertyAtATime(): void
    {
        $this->historicalMovement(new DateTime('2026-08-01 08:00:00'));
        $this->historicalMovement(new DateTime('2026-08-01 09:00:00'), $this->otherId);

        $this->assertSame(1, $this->backfill($this->otherId)['stock_index']);
        // Fixture configuration rows are recorded since step 9; this is about the backfill only.
        $this->assertSame(
            0,
            $this->getTableLocator()->get('ActivityIndex')->find()
                ->where(['property_id' => $this->propertyId, 'event_table !=' => 'config_changes'])->count(),
        );
    }

    /**
     * Invoices from before step 6: opened, each line, and the settlement are
     * recorded at the times history kept, with no actor, no reason, and no
     * amount or total it can't vouch for.
     */
    public function testInvoiceHistoryIsRecordedWithoutInventingAnything(): void
    {
        $invoiceId = $this->insertRow('Invoices', [
            'property_id' => $this->propertyId, 'guest_id' => $this->guestId, 'status' => 'settled', 'total' => 1500,
            'invoice_number' => 'SI-0007', 'or_number' => 'OR-0003',
            'created' => new DateTime('2026-08-10 09:00:00'), 'settled_at' => new DateTime('2026-08-11 12:00:00'),
        ]);
        $lineId = $this->insertRow('InvoiceLines', [
            'invoice_id' => $invoiceId, 'description' => 'Room charge', 'amount' => 1500,
            'source_type' => 'reservation', 'source_id' => 99, 'created' => new DateTime('2026-08-10 09:00:00'),
        ]);
        // A line with no recorded time is left out and counted, not given a time.
        $this->connection->insert('invoice_lines', [
            'invoice_id' => $invoiceId, 'description' => 'Old fee', 'amount' => 0,
            'source_type' => 'manual', 'source_id' => 1,
        ]);

        $added = $this->backfill();
        $this->assertSame(1, $added['invoice_opened_events']);
        $this->assertSame(1, $added['invoice_line_events']);
        $this->assertSame(1, $added['invoice_settled_events']);
        $this->assertSame(3, $added['invoice_index']);

        $events = $this->getTableLocator()->get('InvoiceEvents')->find()
            ->where(['invoice_id' => $invoiceId])->orderBy(['id' => 'ASC'])->all()->toList();
        $this->assertSame(['opened', 'line_added', 'settled'], array_map(fn($e) => $e->event_type, $events));
        foreach ($events as $e) {
            $this->assertNull($e->actor_id, 'history never recorded who');
            $this->assertNull($e->actor_role);
            $this->assertNull($e->reason);
            $this->assertSame('import', $e->source);
            $this->assertStringStartsWith('import-', $e->correlation_id);
        }
        [$opened, $line, $settled] = $events;
        $this->assertSame('2026-08-10 09:00:00', $opened->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame($lineId, (int)$line->invoice_line_id);
        $this->assertSame(1500.0, (float)$line->amount);
        $this->assertNull($line->total_after, 'a running total history can\'t vouch for');
        $this->assertSame('2026-08-11 12:00:00', $settled->occurred_at->format('Y-m-d H:i:s'), 'when it was settled');
        $this->assertSame('SI-0007', $settled->invoice_number);
        $this->assertSame('OR-0003', $settled->or_number);
        $this->assertNull($settled->amount, 'the amount then isn\'t known for sure');

        $this->assertSame(0, $this->backfill()['invoice_index'], 'a second run adds nothing');
        $check = (new ActivityBackfill($this->connection))->check($this->propertyId)[$this->propertyId];
        $this->assertTrue($check['complete']);
        $this->assertSame(1, $check['lines_undated']);
    }

    public function testInvoiceEventsRecordedLiveAreNotDoubled(): void
    {
        /** @var \App\Model\Table\InvoicesTable $invoices */
        $invoices = $this->getTableLocator()->get('Invoices');
        $context = new EventContext($this->deskId, 'receptionist', $this->propertyId, 'corr-live');
        $invoice = $invoices->openInvoiceFor($context, $this->propertyId, $this->guestId);
        $invoices->addLine($context, $invoice, 'Minibar', 250, 'manual', 1);
        $invoices->settle($context, $invoices->get($invoice->id), false, false);

        $added = $this->backfill();
        $this->assertSame(0, $added['invoice_opened_events']);
        $this->assertSame(0, $added['invoice_line_events']);
        $this->assertSame(0, $added['invoice_settled_events']);
        $this->assertSame(0, $added['invoice_index']);
    }

    /**
     * A reservation as stored before step 8 (no events), with the moments it recorded.
     *
     * @param array<string, mixed> $fields Columns.
     */
    private function legacyReservation(array $fields): int
    {
        return $this->insertRow('Reservations', $fields + [
            'property_id' => $this->propertyId, 'room_id' => $this->roomId, 'guest_id' => $this->guestId,
            'receptionist_id' => $this->deskId, 'total_guests' => 1, 'payment_status' => 'unpaid',
            'check_in' => '2026-08-01', 'check_out' => '2026-08-02',
        ]);
    }

    /**
     * @return list<string>
     */
    private function reservationTypes(int $id): array
    {
        return $this->getTableLocator()->get('ReservationEvents')->find()
            ->where(['reservation_id' => $id])->orderBy(['occurred_at' => 'ASC', 'id' => 'ASC'])
            ->all()->extract('event_type')->toList();
    }

    public function testReservationHistoryIsImportedWithoutInventingAnything(): void
    {
        $booking = $this->legacyReservation([
            'source' => 'agoda', 'status' => 'checked_out', 'created' => new DateTime('2026-08-01 02:00:00'),
            'checked_in_at' => new DateTime('2026-08-03 06:00:00'), 'checked_out_at' => new DateTime('2026-08-05 03:00:00'),
        ]);
        $walkIn = $this->legacyReservation([
            'source' => 'walk_in', 'status' => 'checked_in', 'created' => new DateTime('2026-08-10 01:00:00'),
            'checked_in_at' => new DateTime('2026-08-10 01:00:00'),
        ]);
        $cancelled = $this->legacyReservation([
            'source' => 'agoda', 'status' => 'cancelled', 'created' => new DateTime('2026-08-12 01:00:00'),
            'cancelled_at' => new DateTime('2026-08-13 01:00:00'),
        ]);
        // A past stay typed in on 20 Aug for 15–17 Aug: its moments are the stay's dates.
        $pastStay = $this->legacyReservation([
            'source' => 'walk_in', 'status' => 'checked_out', 'created' => new DateTime('2026-08-20 04:00:00'),
            'checked_in_at' => new DateTime('2026-08-14 16:00:00'), 'checked_out_at' => new DateTime('2026-08-16 16:00:00'),
        ]);
        $undated = $this->legacyReservation(['source' => 'agoda', 'status' => 'booked']);
        $this->connection->execute('UPDATE reservations SET created = NULL WHERE id = ?', [$undated]);

        $added = $this->backfill();
        $this->assertSame(4, $added['reservation_created_events']);
        $this->assertSame(1, $added['reservation_checked_in_events'], 'walk-ins and past stays were created checked in');
        $this->assertSame(2, $added['reservation_checked_out_events']);
        $this->assertSame(1, $added['reservation_cancelled_events']);
        $this->assertSame(8, $added['reservation_index']);

        $this->assertSame(['booked', 'checked_in', 'checked_out'], $this->reservationTypes($booking));
        $this->assertSame(['walked_in'], $this->reservationTypes($walkIn));
        $this->assertSame(['booked', 'cancelled'], $this->reservationTypes($cancelled));
        $this->assertSame(['checked_out', 'walked_in'], $this->reservationTypes($pastStay), 'each at the time the row recorded');
        $this->assertSame([], $this->reservationTypes($undated), 'no time recorded: nothing invented');

        $events = $this->getTableLocator()->get('ReservationEvents')->find()
            ->where(['property_id' => $this->propertyId])->all()->toList();
        foreach ($events as $event) {
            $this->assertNull($event->actor_id, 'no actor: receptionist_id is only "last touched by"');
            $this->assertNull($event->actor_role);
            $this->assertNull($event->reason);
            $this->assertNull($event->room_id, 'the room at the time was not recorded');
            $this->assertSame('import', $event->source);
            $this->assertStringStartsWith('import-reservations-' . $event->reservation_id . '-', $event->correlation_id);
            $this->assertSame($this->deskId, (int)$event->snapshot['last_touched_by_before_step_8']);
            $this->assertSame('B-3', $event->snapshot['room_at_import']);
        }
        $created = $this->getTableLocator()->get('ReservationEvents')->find()
            ->where(['reservation_id' => $booking, 'event_type' => 'booked'])->firstOrFail();
        $this->assertSame('2026-08-01 02:00:00', $created->occurred_at->format('Y-m-d H:i:s'));
        $pastEvents = $this->getTableLocator()->get('ReservationEvents')->find()
            ->where(['reservation_id' => $pastStay])->all()->toList();
        foreach ($pastEvents as $event) {
            $this->assertTrue($event->changes['backdated_entry'], 'a stay entered after the fact says so');
        }

        // Idempotent, and the check is complete.
        $again = $this->backfill();
        foreach (['reservation_created_events', 'reservation_checked_in_events', 'reservation_checked_out_events', 'reservation_cancelled_events', 'reservation_index'] as $step) {
            $this->assertSame(0, $again[$step], "$step on a second run");
        }
        $check = (new ActivityBackfill($this->connection))->check($this->propertyId)[$this->propertyId];
        $this->assertTrue($check['complete']);
        $this->assertSame(5, $check['reservations']);
        $this->assertSame(1, $check['reservations_undated']);
        $this->assertSame(8, $check['reservation_imported']);
    }

    public function testReservationEventsRecordedLiveAreNotDoubled(): void
    {
        $this->callAs($this->deskToken, 'POST', '/api/reservations', [
            'room_id' => $this->insertRow('Rooms', [
                'property_id' => $this->propertyId, 'room_number' => 'B-9', 'room_type' => 'Twin', 'status' => 'available',
            ]),
            'source' => 'walk_in', 'guest_name' => 'Live Guest', 'check_out' => (new DateTime('+2 days'))->format('Y-m-d'),
        ]);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $id = (int)$this->responseJson()['reservation']['id'];
        $this->callAs($this->deskToken, 'POST', "/api/reservations/$id/cancel");
        $this->assertResponseOk((string)$this->_response->getBody());

        $added = $this->backfill();
        foreach (['reservation_created_events', 'reservation_checked_in_events', 'reservation_checked_out_events', 'reservation_cancelled_events', 'reservation_index'] as $step) {
            $this->assertSame(0, $added[$step], "$step: live events are never imported again");
        }
        $this->assertSame(['walked_in', 'cancelled'], $this->reservationTypes($id));
    }
}
