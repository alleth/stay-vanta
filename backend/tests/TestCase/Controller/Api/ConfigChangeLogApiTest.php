<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Event\ConfigBaseline;
use Cake\Database\Connection;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use DateTime;

/**
 * Build step 9, part 2: the change log (the authoritative configuration
 * audit), configuration lines in Operations → Activity (price changes and
 * deletions, C4), and the baseline import (C7: the rows as they stand at
 * import, no actor, nothing earlier invented; idempotent; checked).
 */
class ConfigChangeLogApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private int $otherId;
    private string $adminToken;
    private string $deskToken;
    private string $ownerToken;
    private string $otherAdminToken;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = uniqid();
        $this->propertyId = $this->createProperty('Change Log Inn');
        $this->otherId = $this->createProperty('Change Log Other');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "log-admin-$tag@example.test");
        $this->deskToken = $this->makeUser($this->propertyId, 'receptionist', "log-desk-$tag@example.test");
        $this->otherAdminToken = $this->makeUser($this->otherId, 'admin', "log-other-$tag@example.test");
        $this->ownerToken = $this->makeUser(null, 'owner', "log-owner-$tag@example.test");
        /** @var \Cake\Database\Connection $connection */
        $connection = $this->getTableLocator()->get('ConfigChanges')->getConnection();
        $this->connection = $connection;
    }

    protected function tearDown(): void
    {
        $this->cleanupScenario();
        parent::tearDown();
    }

    /** A Manager adds a rate, then raises it with a reason, then renames it. */
    private function rateHistory(string $token): int
    {
        $this->callAs($token, 'POST', '/api/room-rates', ['base_rate' => 1000, 'description' => 'Standard']);
        $this->assertResponseCode(201, (string)$this->_response->getBody());
        $id = (int)$this->responseJson()['roomRate']['id'];
        $this->callAs($token, 'PATCH', "/api/room-rates/$id", ['room_id' => '', 'base_rate' => 1200, 'description' => 'Standard', 'reason' => 'Peak season']);
        $this->assertResponseOk((string)$this->_response->getBody());
        $this->callAs($token, 'PATCH', "/api/room-rates/$id", ['room_id' => '', 'base_rate' => 1200, 'description' => 'Standard (garden)']);
        $this->assertResponseOk((string)$this->_response->getBody());

        return $id;
    }

    public function testTheChangeLogIsTheManagersAndOnlyTheirPropertys(): void
    {
        $rateId = $this->rateHistory($this->adminToken);
        $this->rateHistory($this->otherAdminToken);

        $this->callAs($this->adminToken, 'GET', "/api/config-changes?entity_type=room_rate&entity_id=$rateId");
        $this->assertResponseOk();
        $log = $this->responseJson()['changes'];
        $this->assertSame(['updated', 'updated', 'created'], array_column($log, 'event'), 'newest first');
        $this->assertSame(['operational', 'price', 'price'], array_column($log, 'impact'));
        $this->assertSame('Peak season', $log[1]['reason']);
        $this->assertEquals(['before' => 1000, 'after' => 1200], $log[1]['changes']['base_rate']);
        $this->assertStringContainsString('log-admin', (string)$log[1]['actor']);
        $this->assertTrue($log[1]['recorded']);

        $this->callAs($this->adminToken, 'GET', '/api/config-changes?impact=price');
        $this->assertResponseOk();
        foreach ($this->responseJson()['changes'] as $change) {
            $this->assertSame('price', $change['impact']);
            $this->assertSame($this->propertyId, $change['property_id'], 'never another hotel’s changes');
        }

        $this->callAs($this->deskToken, 'GET', '/api/config-changes');
        $this->assertResponseCode(403);
    }

    public function testPropertyRecordsAreThePlatformOwnersLog(): void
    {
        $this->callAs($this->ownerToken, 'PATCH', "/api/properties/{$this->propertyId}", ['subscription_fee' => 3000, 'reason' => 'New plan']);
        $this->assertResponseOk((string)$this->_response->getBody());

        $this->callAs($this->adminToken, 'GET', '/api/config-changes?entity_type=property');
        $this->assertResponseOk();
        $this->assertSame([], $this->responseJson()['changes'], 'a Manager doesn’t see the subscription log');

        $this->callAs($this->ownerToken, 'GET', "/api/platform/property-changes?property_id={$this->propertyId}");
        $this->assertResponseOk();
        $updated = array_values(array_filter($this->responseJson()['changes'], fn($c) => $c['event'] === 'updated'));
        $this->assertSame('New plan', $updated[0]['reason']);
        $this->assertSame('price', $updated[0]['impact']);
        $this->callAs($this->adminToken, 'GET', '/api/platform/property-changes');
        $this->assertResponseCode(403);
    }

    public function testPriceChangesAndDeletionsJoinActivity(): void
    {
        $rateId = $this->rateHistory($this->adminToken);
        $this->callAs($this->adminToken, 'POST', '/api/extra-charges', ['name' => 'Towel', 'amount' => 50]);
        $chargeId = (int)$this->responseJson()['extraCharge']['id'];
        $this->callAs($this->adminToken, 'DELETE', "/api/extra-charges/$chargeId", ['reason' => 'Towels are free now']);
        $this->assertResponseOk((string)$this->_response->getBody());

        $this->callAs($this->adminToken, 'GET', '/api/operations/activity');
        $this->assertResponseOk();
        $lines = array_values(array_filter($this->responseJson()['activity'], fn($e) => $e['type'] === 'config'));
        $seen = array_map(fn($l) => $l['entity_type'] . ':' . $l['event'], $lines);
        $this->assertContains('extra_charge:deleted', $seen);
        $this->assertContains('room_rate:updated', $seen);
        $priceLine = array_values(array_filter($lines, fn($l) => $l['entity_id'] === $rateId && $l['event'] === 'updated'));
        $this->assertCount(1, $priceLine, 'the price change only: the rename is operational and stays in the change log');
        $this->assertEquals(['before' => 1000, 'after' => 1200], $priceLine[0]['fields']['base_rate']);
        $this->assertSame('Peak season', $priceLine[0]['reason']);
    }

    public function testTheBaselineRecordsWhatIsKnownNowAndNothingElse(): void
    {
        // Rows from before the audit: written straight to the table, no change recorded.
        $this->connection->insert('room_rates', [
            'property_id' => $this->propertyId, 'room_id' => null, 'base_rate' => 1800, 'description' => 'Old rate',
            'created' => '2026-01-15 02:00:00', 'modified' => '2026-03-01 02:00:00',
        ]);
        $oldRate = (int)$this->connection->execute('SELECT MAX(id) AS id FROM room_rates')->fetch('assoc')['id'];
        $this->connection->insert('extra_charges', [
            'property_id' => $this->propertyId, 'name' => 'Old charge', 'amount' => 150, 'is_active' => 1,
            'created' => '2026-01-15 02:00:00', 'modified' => '2026-01-15 02:00:00',
        ]);
        // A row recorded live already has its own history: it gets no baseline.
        $this->callAs($this->adminToken, 'POST', '/api/room-rates', ['base_rate' => 900, 'description' => 'New']);
        $liveRate = (int)$this->responseJson()['roomRate']['id'];

        $baseline = new ConfigBaseline($this->connection);
        $added = $baseline->run($this->propertyId);
        $this->assertSame(1, $added['room_rate']);
        $this->assertSame(1, $added['extra_charge']);
        $again = $baseline->run($this->propertyId);
        $this->assertSame(0, array_sum($again), 'a second run adds nothing');

        $row = $this->getTableLocator()->get('ConfigChanges')->find()
            ->where(['entity_type' => 'room_rate', 'entity_id' => $oldRate])->firstOrFail();
        $this->assertSame('baseline_recorded', $row->event_type);
        $this->assertNull($row->actor_id, 'who made it was never recorded');
        $this->assertNull($row->reason);
        $this->assertSame('import', $row->source);
        $this->assertSame("import-config-room_rate-$oldRate", $row->correlation_id);
        $this->assertEquals(1800, $row->changes['after']['base_rate'], 'its value as it stands now');
        $this->assertGreaterThan(new DateTime('2026-03-01'), $row->occurred_at, 'dated at import, not at `created`');
        $this->assertSame(0, $this->getTableLocator()->get('ConfigChanges')->find()
            ->where(['entity_type' => 'room_rate', 'entity_id' => $liveRate, 'event_type' => 'baseline_recorded'])->count());

        $check = $baseline->check($this->propertyId)[$this->propertyId];
        $this->assertTrue($check['complete']);
        $this->assertSame(0, $check['unrecorded']);

        // Baselines are history, not changes: never in Activity.
        $this->callAs($this->adminToken, 'GET', '/api/operations/activity');
        foreach ($this->responseJson()['activity'] as $line) {
            $this->assertNotSame('baseline_recorded', $line['event'] ?? null);
        }
    }
}
