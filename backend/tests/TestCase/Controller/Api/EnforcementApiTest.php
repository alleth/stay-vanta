<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Model\BusinessTime;
use App\Model\Table\PlatformSettingEventsTable;
use App\Platform\Enforcement;
use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use LogicException;

/**
 * Enforcement-setting audit (final review G6, approved 2026-10-09 as
 * E-D1–E-D6): the phase lives in the database and changes only with an event
 * (who, before, after, why, request id, properties affected); one step
 * forward, any step back, a reason both ways; the environment variable is an
 * emergency ceiling that can only lower the phase and is recorded when first
 * observed; the baseline invents no actor or reason.
 */
class EnforcementApiTest extends TestCase
{
    use ApiScenarioTrait;
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    private int $propertyId;
    private string $ownerToken;
    private string $adminToken;
    private Connection $connection;
    private int $rooms = 0;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var \Cake\Database\Connection $connection */
        $connection = $this->getTableLocator()->get('Properties')->getConnection();
        $this->connection = $connection;
        $this->wipe();
        Configure::delete('App.subscriptionEnforcement');
        $tag = uniqid();
        $this->ownerToken = $this->makeUser(null, 'owner', "enf-owner-$tag@example.test");
        $this->propertyId = $this->createProperty('Enforcement Inn');
        $this->adminToken = $this->makeUser($this->propertyId, 'admin', "enf-admin-$tag@example.test");
        // Paid through 10 hotel days ago: read-only by its dates.
        $this->getTableLocator()->get('Properties')->updateAll(
            ['subscription_expires_at' => BusinessTime::today()->subDays(10)->format('Y-m-d')],
            ['id' => $this->propertyId],
        );
    }

    protected function tearDown(): void
    {
        Configure::delete('App.subscriptionEnforcement');
        $this->wipe();
        $this->cleanupScenario();
        parent::tearDown();
    }

    /**
     * Platform settings belong to no property: this test starts and ends with none.
     */
    private function wipe(): void
    {
        $this->connection->delete('platform_setting_events', []);
        $this->connection->delete('platform_settings', []);
    }

    /**
     * @return list<\Cake\Datasource\EntityInterface>
     */
    private function events(): array
    {
        return $this->getTableLocator()->get('PlatformSettingEvents')->find()
            ->orderBy(['id' => 'ASC'])->all()->toList();
    }

    private function change(string $phase, ?string $reason = 'Rollout review'): void
    {
        $this->callAs($this->ownerToken, 'POST', '/api/platform/enforcement', array_filter([
            'phase' => $phase, 'reason' => $reason,
        ]));
    }

    /**
     * The Manager of the lapsed property tries a change (adding a room).
     */
    private function managerAddsARoom(): int
    {
        $this->callAs($this->adminToken, 'POST', '/api/rooms', [
            'room_number' => 'E-' . ++$this->rooms, 'room_type' => 'Twin',
        ]);

        return $this->_response->getStatusCode();
    }

    public function testTheBaselineIsRecordedOnceAndInventsNothing(): void
    {
        $this->assertTrue(Enforcement::baseline());
        $this->assertFalse(Enforcement::baseline(), 'a second run adds nothing');

        [$baseline] = $this->events();
        $this->assertCount(1, $this->events());
        $this->assertSame(PlatformSettingEventsTable::ENFORCEMENT_PHASE_RECORDED, $baseline->event_type);
        $this->assertSame('import', $baseline->source);
        $this->assertNull($baseline->actor_id, 'who set it was never recorded');
        $this->assertNull($baseline->reason, 'nor why');
        $this->assertSame([null, 'report'], [$baseline->value_before, $baseline->value_after]);
        $this->assertStringStartsWith('import-platform_settings-', $baseline->correlation_id);
        $this->assertNull($baseline->property_id, 'a platform event');

        $check = Enforcement::check();
        $this->assertTrue($check['complete']);
        $this->assertSame([1, 0, 'report'], [$check['baselines'], $check['changes'], $check['effective']]);
    }

    public function testOneStepForwardAnyStepBackEachWithAReasonAndRecorded(): void
    {
        Enforcement::baseline();
        $this->assertSame(201, $this->managerAddsARoom(), 'report enforces nothing');

        $this->change('grace', null);
        $this->assertResponseCode(400, 'a reason is required');
        $this->change('read_only');
        $this->assertResponseCode(400, 'one phase at a time');
        $this->assertStringContainsString('one phase at a time', $this->responseJson()['message']);
        $this->assertCount(1, $this->events(), 'refusals record nothing');

        $this->change('grace', 'Customers notified on Oct 1');
        $this->assertResponseOk((string)$this->_response->getBody());
        $raisedId = $this->_response->getHeaderLine('X-Request-Id');
        $this->change('grace');
        $this->assertResponseCode(400, 'the same phase twice: the second is refused');

        $this->change('read_only', 'Grace ended for unpaid properties');
        $this->assertResponseOk();
        $this->assertSame(403, $this->managerAddsARoom(), 'read-only now applies to the lapsed property');
        $this->assertStringContainsString('read-only', $this->responseJson()['message']);

        $this->change('report', 'Payment provider outage: roll back');
        $this->assertResponseOk();
        $this->assertSame(201, $this->managerAddsARoom(), 'the rollback applies on the very next request');

        $types = array_map(fn($e) => [$e->event_type, $e->value_before, $e->value_after], $this->events());
        $this->assertSame([
            ['enforcement_phase_recorded', null, 'report'],
            ['enforcement_phase_raised', 'report', 'grace'],
            ['enforcement_phase_raised', 'grace', 'read_only'],
            ['enforcement_phase_lowered', 'read_only', 'report'],
        ], $types);

        $raised = $this->events()[1];
        $this->assertSame($this->userIdFor($this->ownerToken), (int)$raised->actor_id);
        $this->assertSame('owner', $raised->actor_role);
        $this->assertSame('Customers notified on Oct 1', $raised->reason);
        $this->assertSame($raisedId, $raised->correlation_id);
        $this->assertEquals(['before' => 'report', 'after' => 'grace'], $raised->changes['phase']);
        $this->assertGreaterThanOrEqual(1, $raised->snapshot['properties']);

        $readOnly = $this->events()[2];
        $this->assertGreaterThanOrEqual(1, $readOnly->snapshot['moved_stricter'], 'the lapsed property moved');
        $this->assertGreaterThanOrEqual(1, $readOnly->snapshot['enforced_after']['read_only']);

        $this->assertTrue(Enforcement::check()['complete'], 'the history is consistent');
    }

    public function testTheCeilingOnlyLowersAndIsRecordedWhenFirstObserved(): void
    {
        Enforcement::baseline();
        $this->change('grace');
        $this->change('read_only');
        $this->assertSame('read_only', Enforcement::effective());

        Configure::write('App.subscriptionEnforcement', 'grace');
        $this->assertSame('grace', Enforcement::effective(), 'the ceiling lowers the phase in force');
        $this->assertSame('read_only', Enforcement::phase(), 'the set phase is unchanged');
        $this->assertSame(201, $this->managerAddsARoom(), 'grace blocks nothing');
        $this->assertFalse(Enforcement::check()['ceiling_seen_ok'], 'not yet recorded');

        $this->assertTrue(Enforcement::observeCeiling());
        $this->assertFalse(Enforcement::observeCeiling(), 'recorded once');
        $observed = array_values(array_filter(
            $this->events(),
            fn($e) => $e->event_type === PlatformSettingEventsTable::ENFORCEMENT_CEILING_OBSERVED,
        ));
        $this->assertCount(1, $observed);
        $this->assertSame('system', $observed[0]->source);
        $this->assertNull($observed[0]->actor_id, 'who changed the variable is not known');
        $this->assertSame([null, 'grace'], [$observed[0]->value_before, $observed[0]->value_after]);
        $this->assertArrayHasKey('observed_not_set', $observed[0]->changes);
        $this->assertTrue(Enforcement::check()['complete']);

        Configure::write('App.subscriptionEnforcement', 'suspend');
        $this->assertSame('read_only', Enforcement::effective(), 'a higher ceiling never raises the phase');
    }

    public function testThePanelShowsThePreviewAndTheHistory(): void
    {
        Enforcement::baseline();
        $this->change('grace', 'Customers notified');

        $this->callAs($this->ownerToken, 'GET', '/api/platform/enforcement');
        $this->assertResponseOk();
        $e = $this->responseJson()['enforcement'];
        $this->assertSame(['grace', null, 'grace'], [$e['phase'], $e['ceiling'], $e['effective']]);
        $this->assertGreaterThanOrEqual(1, $e['preview']['stages']['read_only'], 'the lapsed property, by its dates');
        $this->assertGreaterThanOrEqual(1, $e['preview']['enforced_by_phase']['read_only']['read_only']);
        $this->assertGreaterThanOrEqual(1, $e['preview']['enforced_by_phase']['report']['active']);
        $this->assertSame(['enforcement_phase_raised', 'enforcement_phase_recorded'], array_column($e['history'], 'event'));
        $this->assertSame('Customers notified', $e['history'][0]['reason']);
        $this->assertNotNull($e['history'][0]['actor']);
        $this->assertNull($e['history'][1]['actor']);

        $this->callAs($this->adminToken, 'GET', '/api/platform/enforcement');
        $this->assertResponseCode(403, 'Managers see only their own stage (E-D4)');
        $this->callAs($this->adminToken, 'POST', '/api/platform/enforcement', ['phase' => 'report', 'reason' => 'x']);
        $this->assertResponseCode(403);
    }

    public function testTheSettingAndItsHistoryCannotBeEditedDirectly(): void
    {
        Enforcement::baseline();
        $settings = $this->getTableLocator()->get('PlatformSettings');
        $setting = $settings->find()->where(['setting_key' => Enforcement::KEY])->firstOrFail();
        $refused = 0;
        foreach (
            [
            fn() => $settings->save($setting->set('value', 'suspend')),
            fn() => $settings->delete($setting),
            fn() => $this->getTableLocator()->get('PlatformSettingEvents')->updateAll(['reason' => 'x'], []),
            ] as $attempt
        ) {
            try {
                $attempt();
            } catch (LogicException) {
                $refused++;
            }
        }
        $this->assertSame(3, $refused);
        $this->assertSame('report', Enforcement::phase());
    }
}
