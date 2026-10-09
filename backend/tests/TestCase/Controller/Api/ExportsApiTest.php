<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Model\BusinessTime;
use App\Model\Finance\Collections;
use App\Model\Subscription;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Build step 10c (approved 2026-10-06, X1–X5): Managers export
 * reservations, guests, invoices and collections as CSV for a date range.
 * Every download is one `data_exported` access event (who, which list, which
 * dates, how many rows; never the rows) and one Activity line. No government
 * ID numbers; another hotel's rows never appear; exports survive the
 * read-only period but not a support session.
 */
class ExportsApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private const TRICKY = "Cruz, \"Ana\"\nde la Paz";
    private const FORMULA = '=HYPERLINK("http://example.test")';

    private int $propertyId;
    private int $otherPropertyId;
    private string $adminToken;
    private string $deskToken;
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->today = BusinessTime::today()->format('Y-m-d');
        $this->propertyId = $this->createProperty('Export Inn');
        $this->otherPropertyId = $this->createProperty('Other Export Inn');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "export-admin-$tag@example.test");
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', "export-desk-$tag@example.test");

        $room = $this->insertRow('Rooms', [
            'property_id' => $this->propertyId, 'room_number' => 'E-1', 'room_type' => 'Deluxe', 'status' => 'available',
        ]);
        $guest = $this->insertRow('Guests', [
            'property_id' => $this->propertyId, 'full_name' => self::TRICKY, 'guest_type' => 'local',
            'email' => 'ana@example.test',
        ]);
        $this->insertRow('Guests', [
            'property_id' => $this->propertyId, 'full_name' => self::FORMULA, 'guest_type' => 'foreign',
        ]);
        $this->insertRow('Guests', [
            'property_id' => $this->otherPropertyId, 'full_name' => 'Elsewhere Guest', 'guest_type' => 'local',
        ]);
        $this->insertRow('Reservations', [
            'property_id' => $this->propertyId, 'room_id' => $room, 'guest_id' => $guest,
            'status' => 'booked', 'source' => 'walk_in', 'payment_status' => 'unpaid', 'total_guests' => 2,
            'booking_reference' => 'EXP-1', 'check_in' => $this->today,
            'check_out' => BusinessTime::today()->addDays(2)->format('Y-m-d'),
        ]);
        $invoice = $this->insertRow('Invoices', [
            'property_id' => $this->propertyId, 'guest_id' => $guest, 'status' => 'settled', 'total' => 1500,
            'settled_at' => new DateTime(), 'invoice_number' => 'SI-0007',
        ]);
        $charge = $this->insertRow('InvoiceLines', [
            'invoice_id' => $invoice, 'description' => 'Room charge', 'amount' => 1800, 'source_type' => 'reservation',
        ]);
        $this->insertRow('InvoiceLines', [
            'invoice_id' => $invoice, 'description' => 'Reversal: Room charge', 'amount' => -300,
            'source_type' => 'reservation', 'reverses_line_id' => $charge,
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    private function export(?string $token, string $path, ?string $from = null, ?string $to = null): void
    {
        $from ??= $this->today;
        $to ??= $this->today;
        $this->callAs($token, 'GET', "$path?from=$from&to=$to");
    }

    /**
     * The response as rows of cells (byte-order mark removed).
     *
     * @return list<list<string>>
     */
    private function csvRows(): array
    {
        $body = (string)$this->_response->getBody();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'UTF-8 with a byte-order mark');
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, substr($body, 3));
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return list<\Cake\Datasource\EntityInterface>
     */
    private function exportEvents(): array
    {
        return $this->getTableLocator()->get('AccessEvents')->find()
            ->where(['property_id' => $this->propertyId, 'event_type' => 'data_exported'])
            ->orderBy(['id' => 'ASC'])->all()->toList();
    }

    public function testAManagerExportsReservationsAndTheDownloadIsRecorded(): void
    {
        $this->export($this->adminToken, '/api/reservations/export');
        $this->assertResponseOk();
        $this->assertContentType('text/csv');
        $this->assertHeaderContains('Content-Disposition', "reservations-{$this->today}-to-{$this->today}.csv");
        [$header, $row] = $this->csvRows();
        $this->assertSame('Reference', $header[0]);
        $this->assertSame(['EXP-1', self::TRICKY, 'E-1'], array_slice($row, 0, 3));
        $this->assertSame('Not billed', $row[9]);

        [$event] = $this->exportEvents();
        $this->assertSame($this->userIdFor($this->adminToken), (int)$event->actor_id);
        $this->assertSame('admin', $event->actor_role);
        $this->assertEquals(
            ['dataset' => 'reservations', 'from' => $this->today, 'to' => $this->today, 'rows' => 1],
            $event->changes,
        );
        $this->assertSame(1, $this->getTableLocator()->get('ActivityIndex')->find()
            ->where(['event_table' => 'access_events', 'event_id' => $event->id])->count(), 'one Activity line (X4)');
    }

    public function testGuestsKeepTrickyNamesIntactDefuseFormulasAndStayAtTheirHotel(): void
    {
        $this->export($this->adminToken, '/api/guests/export');
        $this->assertResponseOk();
        $rows = $this->csvRows();
        $names = array_column(array_slice($rows, 1), 0);
        $this->assertContains(self::TRICKY, $names, 'commas, quotes and line breaks survive');
        $this->assertContains("'" . self::FORMULA, $names, 'a formula is defused');
        $this->assertNotContains('Elsewhere Guest', $names, 'another hotel\'s guests never appear');
        $this->assertSame(
            ['Name', 'Type', 'Nationality', 'Phone', 'Email', 'Registered', 'Stays', 'First stay', 'Last stay'],
            $rows[0],
            'no government ID numbers (X3)',
        );
        $tricky = array_values(array_filter($rows, fn($r) => $r[0] === self::TRICKY))[0];
        $this->assertSame('1', $tricky[6], 'one stay');
        $this->assertSame(2, $this->exportEvents()[0]->changes['rows']);
    }

    public function testInvoicesListEachLineSoTheyAddUpToTheTotal(): void
    {
        $this->export($this->adminToken, '/api/invoices/export');
        $this->assertResponseOk();
        $rows = array_slice($this->csvRows(), 1);
        $this->assertCount(2, $rows);
        $this->assertSame('SI-0007', $rows[0][1]);
        $this->assertSame('Settled', $rows[0][4]);
        $this->assertSame(1500.0, array_sum(array_map(fn($r) => (float)$r[9], $rows)));
        $this->assertSame('Reversal', $rows[1][10]);
    }

    public function testCollectionsMatchFinanceToTheCentavo(): void
    {
        $this->export($this->adminToken, '/api/finance/collections/export');
        $this->assertResponseOk();
        [, $day] = $this->csvRows();
        $figures = (new Collections($this->propertyId))->figuresOn($this->today);
        $this->assertSame($this->today, $day[0]);
        $this->assertSame((float)$figures['collected']['total'], (float)$day[3]);
        $this->assertSame((float)$figures['net'], (float)$day[5]);
        $this->assertSame(1500.0, (float)$day[3]);
    }

    public function testOnlyManagersExport(): void
    {
        $ownerToken = $this->makeUser(null, 'owner', 'export-owner-' . uniqid() . '@example.test');
        $paths = ['/api/reservations/export', '/api/guests/export', '/api/invoices/export', '/api/finance/collections/export'];
        foreach ($paths as $path) {
            $this->export($this->deskToken, $path);
            $this->assertResponseCode(403, "front desk: $path");
            $this->export($ownerToken, $path);
            $this->assertResponseCode(403, "platform owner: $path");
        }

        $this->callAs($ownerToken, 'POST', '/api/platform/support-sessions', [
            'property_id' => $this->propertyId, 'reason' => 'Checking an export question',
        ]);
        $this->assertResponseCode(201);
        $this->export($ownerToken, '/api/guests/export');
        $this->assertResponseCode(403, 'a support session never takes a hotel\'s data away');
        $this->assertSame([], $this->exportEvents());
    }

    public function testTheRangeIsCheckedBeforeAnythingIsRecorded(): void
    {
        $this->callAs($this->adminToken, 'GET', '/api/guests/export');
        $this->assertResponseCode(400);
        $this->export($this->adminToken, '/api/guests/export', $this->today, '2000-01-01');
        $this->assertResponseCode(400);
        $this->export($this->adminToken, '/api/guests/export', '2025-01-01', '2026-03-01');
        $this->assertResponseCode(400);
        $this->assertSame([], $this->exportEvents());
    }

    public function testAReadOnlyHotelCanStillTakeItsData(): void
    {
        $this->usePhase(Subscription::MODE_READ_ONLY);
        $this->getTableLocator()->get('Properties')->updateAll(
            ['subscription_expires_at' => BusinessTime::today()->subDays(10)->format('Y-m-d')],
            ['id' => $this->propertyId],
        );

        $this->export($this->adminToken, '/api/reservations/export');
        $this->assertResponseOk();
        $this->assertCount(1, $this->exportEvents());
    }
}
