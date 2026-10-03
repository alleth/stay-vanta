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

        $this->assertSame(['placed_events' => 1, 'order_index' => 1, 'stock_index' => 2, 'invoice_opened_events' => 0, 'invoice_line_events' => 0, 'invoice_settled_events' => 0, 'invoice_index' => 0], $this->backfill());
        $this->assertSame(['placed_events' => 0, 'order_index' => 0, 'stock_index' => 0, 'invoice_opened_events' => 0, 'invoice_line_events' => 0, 'invoice_settled_events' => 0, 'invoice_index' => 0], $this->backfill());

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

        $this->assertSame(['placed_events' => 0, 'order_index' => 0, 'stock_index' => 0, 'invoice_opened_events' => 0, 'invoice_line_events' => 0, 'invoice_settled_events' => 0, 'invoice_index' => 0], $this->backfill());
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
        $this->assertSame(
            0,
            $this->getTableLocator()->get('ActivityIndex')->find()->where(['property_id' => $this->propertyId])->count(),
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
}
