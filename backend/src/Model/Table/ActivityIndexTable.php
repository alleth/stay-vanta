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
     * Index a stored event. Called by EventLedgerBehavior::record() only.
     *
     * @param \Cake\Datasource\EntityInterface $event The stored event row.
     * @param string $eventTable Its table, e.g. food_order_events.
     * @param string $subjectType e.g. food_order.
     * @param int $subjectId The subject's id.
     * @param array<string, mixed> $summary What the feed shows.
     * @return \Cake\Datasource\EntityInterface
     */
    public function add(
        EntityInterface $event,
        string $eventTable,
        string $subjectType,
        int $subjectId,
        array $summary,
    ): EntityInterface {
        $row = $this->newEntity([], ['validate' => false]);
        $row->patch([
            'property_id' => $event->get('property_id'),
            'occurred_at' => $event->get('occurred_at'),
            'actor_id' => $event->get('actor_id'),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'event_table' => $eventTable,
            'event_id' => $event->get('id'),
            'event_type' => $event->get('event_type'),
            'correlation_id' => $event->get('correlation_id'),
            'summary' => $summary ?: null,
        ], ['guard' => false]);

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
