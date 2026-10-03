<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;

/**
 * One row per event from every ledger, written in the event's transaction by
 * EventLedgerBehavior. It's what lets one query answer "what happened at this
 * property" (the Operations feed) and "everything this business action did"
 * (forCorrelation), without a UNION over every ledger. The event itself, with
 * its typed columns and full snapshot, stays in its own table.
 */
class ActivityIndexTable extends Table
{
    use AppendOnlyTableTrait;

    /**
     * @param array<string, mixed> $config Table config.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('activity_index');
        $this->addBehavior('AppendOnly');
        $this->getSchema()->setColumnType('summary', 'json');
    }

    /**
     * Index a stored event. Called by EventLedgerBehavior only, in the
     * event's transaction.
     *
     * @param array<string, mixed> $values property_id, occurred_at, actor_id, subject_type,
     *   subject_id, event_table, event_id, event_type, correlation_id, summary.
     * @return \Cake\Datasource\EntityInterface
     */
    public function add(array $values): EntityInterface
    {
        $row = $this->newEntity([], ['validate' => false]);
        $row->patch($values, ['guard' => false]);

        return $this->saveOrFail($row, ['atomic' => false]);
    }

    /**
     * Every event one business action wrote, across all ledgers, in the order
     * they happened: the reconstruction of a checkout, a settlement or a sale.
     *
     * @return list<\Cake\Datasource\EntityInterface>
     */
    public function forCorrelation(int $propertyId, string $correlationId): array
    {
        return $this->find()
            ->where(['property_id' => $propertyId, 'correlation_id' => $correlationId])
            ->orderBy(['occurred_at' => 'ASC', 'id' => 'ASC'])
            ->all()
            ->toList();
    }
}
