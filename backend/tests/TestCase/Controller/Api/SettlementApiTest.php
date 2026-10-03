<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Settlement, pinned before invoice accountability (build step 6) changes how
 * it's written. Idempotency is an acceptance criterion (decided 2026-10-03):
 * a repeated settle is refused, consumes no receipt number and changes no
 * revenue figure. Step 6 adds: the settled event is recorded once.
 */
class SettlementApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private string $adminToken;
    private string $deskToken;
    private int $guestId;
    private int $siSeries;
    private int $orSeries;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Settlement Resort');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "settle-admin-$tag@example.test");
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', "settle-desk-$tag@example.test");
        $this->guestId = $this->insertRow('Guests', [
            'property_id' => $this->propertyId, 'full_name' => 'Eva Santos', 'guest_type' => 'local',
        ]);
        $series = fn(string $type, string $prefix, int $end) => $this->insertRow('ReceiptSeries', [
            'property_id' => $this->propertyId, 'type' => $type, 'prefix' => $prefix,
            'start_number' => 1, 'end_number' => $end, 'next_number' => 1, 'pad_length' => 4, 'is_active' => true,
        ]);
        $this->siSeries = $series('invoice', 'SI-', 100);
        $this->orSeries = $series('official_receipt', 'OR-', 100);
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    private function openInvoice(float $total): int
    {
        $id = $this->insertRow('Invoices', [
            'property_id' => $this->propertyId, 'guest_id' => $this->guestId, 'status' => 'open', 'total' => $total,
        ]);
        $this->insertRow('InvoiceLines', [
            'invoice_id' => $id, 'description' => 'Room charge', 'amount' => $total,
            'source_type' => 'manual_test', 'source_id' => $id,
        ]);

        return $id;
    }

    private function nextNumber(int $seriesId): int
    {
        return (int)$this->getTableLocator()->get('ReceiptSeries')->get($seriesId)->next_number;
    }

    /**
     * Collected today and outstanding, as Finance reports them.
     *
     * @return array{0: float, 1: float}
     */
    private function figures(): array
    {
        $this->callAs($this->adminToken, 'GET', '/api/finance/collections');
        $this->assertResponseOk();
        $c = $this->responseJson()['collection'];

        return [(float)$c['total'], (float)$c['outstanding']['total']];
    }

    public function testSettlingIsDoneOnceWhateverIsRepeated(): void
    {
        $invoiceId = $this->openInvoice(2500);
        [$collectedBefore, $outstandingBefore] = $this->figures();

        $this->callAs($this->deskToken, 'POST', "/api/invoices/$invoiceId/settle", ['use_invoice' => true, 'use_or' => true]);
        $this->assertResponseOk();
        $settled = $this->getTableLocator()->get('Invoices')->get($invoiceId);
        $this->assertSame('settled', $settled->status);
        $this->assertNotNull($settled->settled_at);
        $this->assertSame('SI-0001', $settled->invoice_number);
        $this->assertSame('OR-0001', $settled->or_number);
        $this->assertSame(2, $this->nextNumber($this->siSeries));
        $this->assertSame(2, $this->nextNumber($this->orSeries));
        [$collected, $outstanding] = $this->figures();
        $this->assertEquals($collectedBefore + 2500, $collected, 'collected once');
        $this->assertEquals($outstandingBefore - 2500, $outstanding);

        foreach ([$this->deskToken, $this->adminToken] as $token) {
            $this->callAs($token, 'POST', "/api/invoices/$invoiceId/settle", ['use_invoice' => true, 'use_or' => true]);
            $this->assertResponseCode(400, 'a second settle is refused');
        }

        $again = $this->getTableLocator()->get('Invoices')->get($invoiceId);
        $this->assertSame('SI-0001', $again->invoice_number, 'numbers unchanged');
        $this->assertSame('OR-0001', $again->or_number);
        $this->assertEquals($settled->settled_at, $again->settled_at, 'settled time unchanged');
        $this->assertSame(2, $this->nextNumber($this->siSeries), 'no receipt number consumed twice');
        $this->assertSame(2, $this->nextNumber($this->orSeries));
        $this->assertSame([$collected, $outstanding], $this->figures(), 'revenue unchanged by repeats');
    }

    public function testAnExhaustedBookletLeavesTheInvoiceOpenAndConsumesNothing(): void
    {
        $this->getTableLocator()->get('ReceiptSeries')->updateAll(['next_number' => 101], ['id' => $this->siSeries]);
        $invoiceId = $this->openInvoice(800);

        $this->callAs($this->adminToken, 'POST', "/api/invoices/$invoiceId/settle", ['use_invoice' => true, 'use_or' => true]);
        $this->assertResponseCode(400);

        $invoice = $this->getTableLocator()->get('Invoices')->get($invoiceId);
        $this->assertSame('open', $invoice->status);
        $this->assertNull($invoice->invoice_number);
        $this->assertNull($invoice->or_number);
        $this->assertSame(1, $this->nextNumber($this->orSeries), 'no OR number is taken either');
    }

    public function testSettlingWithoutReceiptNumbersTakesNone(): void
    {
        $invoiceId = $this->openInvoice(300);
        $this->callAs($this->deskToken, 'POST', "/api/invoices/$invoiceId/settle", []);
        $this->assertResponseOk();

        $invoice = $this->getTableLocator()->get('Invoices')->get($invoiceId);
        $this->assertSame('settled', $invoice->status);
        $this->assertNull($invoice->invoice_number);
        $this->assertSame(1, $this->nextNumber($this->siSeries));
    }
}
