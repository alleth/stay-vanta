<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Event\ActivityBackfill;
use App\Event\EventContext;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Build step 6: invoice events join Operations → Activity as their own lines
 * (settled, downpayment collected, reversed, refunded), showing the event as
 * it happened: when, who, the amount, receipt numbers and reason. Imported
 * history says it wasn't recorded instead of guessing who.
 */
class InvoiceFeedApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private string $adminToken;
    private string $deskToken;
    private int $guestId;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Invoice Feed Resort');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "ifeed-admin-$tag@example.test");
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', "ifeed-desk-$tag@example.test");
        $this->guestId = $this->insertRow('Guests', [
            'property_id' => $this->propertyId, 'full_name' => 'Hana Cruz', 'guest_type' => 'local',
        ]);
        $this->insertRow('ReceiptSeries', [
            'property_id' => $this->propertyId, 'type' => 'invoice', 'prefix' => 'SI-',
            'start_number' => 1, 'end_number' => 50, 'next_number' => 1, 'pad_length' => 4, 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    /**
     * An open invoice with two lines, created through the API's ledger.
     *
     * @return array{0: int, 1: int} Invoice id and its first line's id.
     */
    private function openInvoice(): array
    {
        /** @var \App\Model\Table\InvoicesTable $invoices */
        $invoices = $this->getTableLocator()->get('Invoices');
        $context = new EventContext(
            $this->userIdFor($this->deskToken),
            'receptionist',
            $this->propertyId,
            'corr-setup',
        );
        $invoice = $invoices->openInvoiceFor($context, $this->propertyId, $this->guestId);
        $line = $invoices->addLine($context, $invoice, 'Extra bed', 500, 'manual', 1);
        $invoices->addLine($context, $invoice, 'Room charge', 2000, 'manual', 2);

        return [(int)$invoice->id, (int)$line->id];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function invoiceLines(): array
    {
        $this->callAs($this->adminToken, 'GET', '/api/operations/activity');
        $this->assertResponseOk();

        return array_values(array_filter($this->responseJson()['activity'], fn($l) => $l['type'] === 'invoice'));
    }

    public function testASettlementIsALineWithWhoAndTheNumbers(): void
    {
        [$invoiceId] = $this->openInvoice();
        $this->callAs($this->deskToken, 'POST', "/api/invoices/$invoiceId/settle", ['use_invoice' => true]);
        $this->assertResponseOk();

        $lines = $this->invoiceLines();
        $this->assertCount(1, $lines, 'opened and line_added stay out of the feed');
        $line = $lines[0];
        $this->assertSame('settled', $line['event']);
        $this->assertSame($invoiceId, $line['invoice_id']);
        $this->assertTrue($line['recorded']);
        $this->assertStringContainsString('ifeed-desk', (string)$line['actor']);
        $this->assertEquals(2500, $line['amount']);
        $this->assertSame('SI-0001', $line['invoice_number']);
        $this->assertSame('Hana Cruz', $line['guest']);
        $this->assertSame('invoice-event-' . $this->getTableLocator()->get('InvoiceEvents')->find()
            ->where(['invoice_id' => $invoiceId, 'event_type' => 'settled'])->firstOrFail()->id, $line['id']);
    }

    public function testAReversalShowsTheLineAndTheReason(): void
    {
        [$invoiceId, $lineId] = $this->openInvoice();
        $this->callAs($this->adminToken, 'POST', "/api/invoices/$invoiceId/lines/$lineId/reverse", ['reason' => 'Guest declined']);
        $this->assertResponseOk();

        $line = $this->invoiceLines()[0];
        $this->assertSame('line_reversed', $line['event']);
        $this->assertSame('Extra bed', $line['line']);
        $this->assertSame('Guest declined', $line['reason']);
        $this->assertEquals(-500, $line['amount']);
        $this->assertEquals(2000, $line['total'], 'the invoice as it stands now');
    }

    public function testImportedHistorySaysWhoWasNotRecorded(): void
    {
        $this->insertRow('Invoices', [
            'property_id' => $this->propertyId, 'guest_id' => $this->guestId, 'status' => 'settled', 'total' => 900,
            'created' => new DateTime('2026-08-01 08:00:00'), 'settled_at' => new DateTime('2026-08-01 10:00:00'),
        ]);
        /** @var \Cake\Database\Connection $connection */
        $connection = $this->getTableLocator()->get('Properties')->getConnection();
        (new ActivityBackfill($connection))->run($this->propertyId);

        $line = $this->invoiceLines()[0];
        $this->assertSame('settled', $line['event']);
        $this->assertNull($line['actor']);
        $this->assertFalse($line['recorded']);
        $this->assertNull($line['amount'], 'the amount then is not claimed');
        $this->assertEquals(900, $line['total']);
        $this->assertSame('2026-08-01T10:00:00+00:00', (new DateTime($line['at']))->setTimezone('UTC')->format('c'));
    }

    public function testNewestFirstWithTheOtherLines(): void
    {
        [$first] = $this->openInvoice();
        $this->callAs($this->deskToken, 'POST', "/api/invoices/$first/settle");
        $this->assertResponseOk();

        $this->callAs($this->adminToken, 'GET', '/api/operations/today');
        $this->assertResponseOk();
        $card = $this->responseJson()['operations']['activity'];
        $this->assertSame('invoice', $card[0]['type'], 'the Operations card shows it too');
    }
}
