<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

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
}
