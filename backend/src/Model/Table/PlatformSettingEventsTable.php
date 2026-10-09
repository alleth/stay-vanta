<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;

/**
 * Platform settings ledger (final review G6, approved 2026-10-09 as
 * E-D1–E-D6): every change to a platform-wide setting, today the
 * subscription-enforcement phase. Written only by App\Platform\Enforcement
 * under a lock on the setting row. Platform events: no property, never in a
 * property's Activity feed. Read by the Platform Owner (E-D4). Kept
 * indefinitely: it holds no personal data beyond the actor's id and the reason.
 */
class PlatformSettingEventsTable extends Table
{
    use AppendOnlyTableTrait;
    use EventLedgerTableTrait;

    /** Once, at release: the phase already in force; who set it and why were never recorded. */
    public const ENFORCEMENT_PHASE_RECORDED = 'enforcement_phase_recorded';
    /** The Platform Owner moved the phase one step forward. Needs a reason. */
    public const ENFORCEMENT_PHASE_RAISED = 'enforcement_phase_raised';
    /** The Platform Owner moved the phase back (the rollback). Needs a reason. */
    public const ENFORCEMENT_PHASE_LOWERED = 'enforcement_phase_lowered';
    /** The app first saw a new ceiling (the environment variable): when it was observed, not set. */
    public const ENFORCEMENT_CEILING_OBSERVED = 'enforcement_ceiling_observed';

    public const TYPES = [
        self::ENFORCEMENT_PHASE_RECORDED,
        self::ENFORCEMENT_PHASE_RAISED,
        self::ENFORCEMENT_PHASE_LOWERED,
        self::ENFORCEMENT_CEILING_OBSERVED,
    ];

    public const REQUIRES_REASON = [
        self::ENFORCEMENT_PHASE_RAISED,
        self::ENFORCEMENT_PHASE_LOWERED,
    ];

    public const REASON_GRACE = [];

    /**
     * @param array<string, mixed> $config Table config.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('platform_setting_events');
        $this->addBehavior('EventLedger', [
            'subjectKey' => 'platform_setting_id',
            'subjectType' => 'platform_setting',
            'platformEvents' => true,
        ]);
    }

    /**
     * The setting as it stood: its key.
     *
     * @param \Cake\Datasource\EntityInterface $setting A platform setting.
     * @return array<string, mixed>
     */
    public function snapshotOf(EntityInterface $setting): array
    {
        return ['setting' => $setting->get('setting_key')];
    }
}
