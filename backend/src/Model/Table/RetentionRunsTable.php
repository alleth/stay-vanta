<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * The retention routine's own record (final review G5): one row per policy
 * per run, dry runs included, with its cutoff, how many rows it cleared and
 * the run's request id. Append-only, like every ledger: a run is never
 * edited or removed.
 */
class RetentionRunsTable extends Table
{
    use AppendOnlyTableTrait;

    /**
     * @param array<string, mixed> $config Table config.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('retention_runs');
        $this->addBehavior('Timestamp');
        $this->addBehavior('AppendOnly');
    }
}
