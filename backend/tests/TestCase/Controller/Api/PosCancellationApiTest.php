<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Who may cancel which POS sale, pinned before cancellations start writing
 * food_order_events (build step 5). Only a served and paid order is
 * restricted (pos.sale.cancel_paid, a Manager); every other cancel is open to
 * anyone who can sell. During the compatibility window a reason is accepted
 * but not yet required, so sending one must never change the answer.
 */
class PosCancellationApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;

    /**
     * @var array<string, string>
     */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('POS Cancellation Resort');
        $this->tokens['admin'] = $this->makeUser($this->propertyId, 'admin', "cancel-admin-$tag@example.test");
        $this->tokens['receptionist'] = $this->makeUser(
            $this->propertyId,
            'receptionist',
            "cancel-desk-$tag@example.test",
        );
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: int}>
     */
    public static function cancelProvider(): array
    {
        return [
            'desk: open, unpaid' => ['receptionist', 'open', 'unpaid', 200],
            'desk: open, paid' => ['receptionist', 'open', 'paid', 200],
            'desk: served, unpaid' => ['receptionist', 'served', 'unpaid', 200],
            'desk: served, charged to room' => ['receptionist', 'served', 'charge_to_room', 200],
            'desk: served, paid' => ['receptionist', 'served', 'paid', 403],
            'manager: served, paid' => ['admin', 'served', 'paid', 200],
            'manager: open, unpaid' => ['admin', 'open', 'unpaid', 200],
        ];
    }

    private function order(string $status, string $payment): int
    {
        return $this->insertRow('FoodOrders', [
            'property_id' => $this->propertyId,
            'receptionist_id' => $this->userIdFor($this->tokens['admin']),
            'status' => $status, 'payment_status' => $payment,
            'payment_method' => $payment === 'paid' ? 'cash' : null,
            'total' => 150, 'total_diners' => 1,
        ]);
    }

    #[DataProvider('cancelProvider')]
    public function testWhoMayCancelWhat(string $role, string $status, string $payment, int $expected): void
    {
        foreach ([[], ['reason' => 'Guest changed their mind']] as $body) {
            $id = $this->order($status, $payment);
            $this->callAs($this->tokens[$role], 'POST', "/api/food-orders/$id/cancel", $body);
            $this->assertResponseCode($expected, $body ? 'with a reason' : 'without a reason');

            $now = $this->getTableLocator()->get('FoodOrders')->get($id)->status;
            $this->assertSame($expected === 200 ? 'cancelled' : $status, $now);
            if ($expected === 403) {
                $this->assertStringContainsString('Manager', (string)$this->responseJson()['message']);
            }
        }
    }

    public function testACancelledOrderCannotBeCancelledAgain(): void
    {
        $id = $this->order('cancelled', 'unpaid');
        $this->callAs($this->tokens['admin'], 'POST', "/api/food-orders/$id/cancel");
        $this->assertResponseCode(400);
    }

    /**
     * Hotfix (2026-10-03): a sale charged to a room whose invoice is settled
     * can't be cancelled. Before, cancelling it deleted the line from the
     * settled invoice and lowered its total, rewriting that day's Collected.
     */
    public function testASaleOnASettledInvoiceCannotBeCancelled(): void
    {
        $guestId = $this->insertRow('Guests', [
            'property_id' => $this->propertyId, 'full_name' => 'Cora Lim', 'guest_type' => 'local',
        ]);
        $orderId = $this->insertRow('FoodOrders', [
            'property_id' => $this->propertyId, 'receptionist_id' => $this->userIdFor($this->tokens['admin']),
            'guest_id' => $guestId, 'status' => 'served', 'payment_status' => 'charge_to_room',
            'total' => 300, 'total_diners' => 1,
        ]);
        $invoiceId = $this->insertRow('Invoices', [
            'property_id' => $this->propertyId, 'guest_id' => $guestId, 'status' => 'settled',
            'total' => 300, 'settled_at' => new DateTime('2026-09-30 10:00:00'),
        ]);
        $this->insertRow('InvoiceLines', [
            'invoice_id' => $invoiceId, 'description' => "Food order #$orderId", 'amount' => 300,
            'source_type' => 'food_order', 'source_id' => $orderId,
        ]);

        foreach (['receptionist', 'admin'] as $role) {
            $this->callAs($this->tokens[$role], 'POST', "/api/food-orders/$orderId/cancel", ['reason' => 'test']);
            $this->assertResponseCode(400, $role);
            $this->assertStringContainsString('settled invoice', (string)$this->responseJson()['message']);
        }

        $invoice = $this->getTableLocator()->get('Invoices')->get($invoiceId);
        $this->assertSame(300.0, (float)$invoice->total, 'the settled invoice is untouched');
        $this->assertSame(1, $this->getTableLocator()->get('InvoiceLines')->find()->where(['invoice_id' => $invoiceId])->count());
        $this->assertSame('served', $this->getTableLocator()->get('FoodOrders')->get($orderId)->status);
        $this->assertSame(0, $this->getTableLocator()->get('FoodOrderEvents')->find()
            ->where(['food_order_id' => $orderId, 'event_type IN' => ['cancelled', 'cancelled_after_payment']])->count());
    }

    public function testASaleOnAnOpenInvoiceCanStillBeCancelled(): void
    {
        $guestId = $this->insertRow('Guests', [
            'property_id' => $this->propertyId, 'full_name' => 'Dan Uy', 'guest_type' => 'local',
        ]);
        $orderId = $this->insertRow('FoodOrders', [
            'property_id' => $this->propertyId, 'receptionist_id' => $this->userIdFor($this->tokens['admin']),
            'guest_id' => $guestId, 'status' => 'served', 'payment_status' => 'charge_to_room',
            'total' => 120, 'total_diners' => 1,
        ]);
        $invoiceId = $this->insertRow('Invoices', [
            'property_id' => $this->propertyId, 'guest_id' => $guestId, 'status' => 'open', 'total' => 120,
        ]);
        $this->insertRow('InvoiceLines', [
            'invoice_id' => $invoiceId, 'description' => "Food order #$orderId", 'amount' => 120,
            'source_type' => 'food_order', 'source_id' => $orderId,
        ]);

        $this->callAs($this->tokens['receptionist'], 'POST', "/api/food-orders/$orderId/cancel");
        $this->assertResponseOk();
        $this->assertSame(0.0, (float)$this->getTableLocator()->get('Invoices')->get($invoiceId)->total);
    }
}
