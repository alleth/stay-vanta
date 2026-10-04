<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * Configuration ledger (build step 9): every change to a configuration row,
 * across all configuration tables, with who, when, why, what changed
 * (before/after per field) and its impact class. Written only by
 * ConfigAuditBehavior, inside the save's own transaction; nothing can change
 * configuration around it. The subject is `entity_type` + `entity_id`.
 */
class ConfigChangesTable extends Table
{
    use AppendOnlyTableTrait;
    use EventLedgerTableTrait;

    /** A configuration row is added (all its values). */
    public const CREATED = 'created';
    /** Fields change (each before and after); a price change needs a reason. */
    public const UPDATED = 'updated';
    /** A row is soft-deleted (all its values); needs a reason. */
    public const DELETED = 'deleted';
    /** Import: the row as it stood when the audit began (no actor). */
    public const BASELINE_RECORDED = 'baseline_recorded';

    public const TYPES = [self::CREATED, self::UPDATED, self::DELETED, self::BASELINE_RECORDED];

    /** `updated` needs one too when its impact is `price` (ConfigAuditBehavior decides). */
    public const REQUIRES_REASON = [self::DELETED];

    public const REASON_GRACE = [];

    /** Impact classes, highest first (step 9, approved 2026-10-04). */
    public const IMPACT_PRICE = 'price';
    public const IMPACT_BOOKING = 'booking';
    public const IMPACT_OPERATIONAL = 'operational';
    public const IMPACT_ADMINISTRATIVE = 'administrative';
    public const IMPACTS = [
        self::IMPACT_PRICE,
        self::IMPACT_BOOKING,
        self::IMPACT_OPERATIONAL,
        self::IMPACT_ADMINISTRATIVE,
    ];

    /**
     * @param array<string, mixed> $config Table config.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('config_changes');
        $this->addBehavior('EventLedger', ['subjectKey' => 'entity_id', 'subjectType' => 'configuration']);
    }
}
