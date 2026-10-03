<?php
declare(strict_types=1);

namespace App\Model\Behavior;

use App\Event\EventContext;
use App\Event\ReasonRequiredException;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Behavior;
use Cake\ORM\TableRegistry;
use InvalidArgumentException;
use LogicException;

/**
 * The one way an event is recorded (the accountability standard in CLAUDE.md;
 * catalog in docs/EVENTS.md).
 *
 * A ledger table attaches this with its subject column, and declares on its
 * class:
 * - `TYPES`: its event types (past-tense constants);
 * - `REQUIRES_REASON`: the types that must say why;
 * - `REASON_GRACE`: required types still accepted without a reason during a
 *   compatibility window (an older frontend can't send one yet); empty once
 *   the window closes;
 * - `snapshotOf($subject)`: the minimal facts to keep as they were then (ids
 *   and display names, never contact details or ID numbers);
 * - optionally `summaryOf($subject, $type)`: what the activity feed shows.
 *
 * record() writes the event and its activity_index row, and must run inside
 * the transaction of the change it describes: if the event can't be written,
 * the change rolls back with it.
 */
class EventLedgerBehavior extends Behavior
{
    /**
     * @var array<string, mixed>
     */
    protected array $_defaultConfig = [
        // Column holding the subject's id, e.g. food_order_id.
        'subjectKey' => null,
        // Name of the subject in activity_index, e.g. food_order.
        'subjectType' => null,
    ];

    /**
     * @param array<string, mixed> $config Behavior config.
     * @return void
     */
    public function initialize(array $config): void
    {
        if (!$this->getConfig('subjectKey') || !$this->getConfig('subjectType')) {
            throw new LogicException('EventLedgerBehavior needs subjectKey and subjectType.');
        }
        $table = $this->table();
        if (!$table->hasBehavior('AppendOnly')) {
            $table->addBehavior('AppendOnly');
        }
        foreach (['changes', 'snapshot'] as $column) {
            $table->getSchema()->setColumnType($column, 'json');
        }
    }

    /**
     * Record that `$type` happened to `$subject`.
     *
     * @param \App\Event\EventContext $context Who, for whom, why, which action.
     * @param string $type One of the table's TYPES.
     * @param \Cake\Datasource\EntityInterface $subject The record the event is about (has property_id).
     * @param array<string, mixed> $options `changes` (before/after), `columns` (typed columns such as
     *   amount), `correctsEventId` (the event this one corrects).
     * @return \Cake\Datasource\EntityInterface The stored event.
     */
    public function record(
        EventContext $context,
        string $type,
        EntityInterface $subject,
        array $options = [],
    ): EntityInterface {
        $table = $this->table();
        if (!$table->getConnection()->inTransaction()) {
            throw new LogicException(
                'Record an event inside the transaction of the change it describes ' .
                '(wrap both in transactional(), taking the lock first).',
            );
        }

        $class = $table::class;
        if (!in_array($type, $this->constantOf($class, 'TYPES'), true)) {
            throw new InvalidArgumentException("'$type' is not a $class event type.");
        }
        if (
            $context->reason === null
            && in_array($type, $this->constantOf($class, 'REQUIRES_REASON'), true)
            && !in_array($type, $this->constantOf($class, 'REASON_GRACE'), true)
        ) {
            throw new ReasonRequiredException();
        }

        $propertyId = (int)$subject->get('property_id');
        if ($propertyId === 0) {
            throw new LogicException('An event subject must belong to a property.');
        }
        if ($context->propertyId !== null && $context->propertyId !== $propertyId) {
            throw new LogicException('An event cannot be recorded for another property than the request\'s.');
        }

        $columns = (array)($options['columns'] ?? []);
        foreach (array_keys($columns) as $column) {
            if (!$table->getSchema()->hasColumn($column)) {
                throw new InvalidArgumentException("$class has no typed column '$column'.");
            }
        }

        $snapshot = method_exists($table, 'snapshotOf') ? $table->snapshotOf($subject) : [];
        $event = $table->newEntity([], ['validate' => false]);
        $event->set([
            'property_id' => $propertyId,
            $this->getConfig('subjectKey') => $subject->get('id'),
            'event_type' => $type,
            'actor_id' => $context->actorId,
            'actor_role' => $context->actorRole,
            'source' => $context->source,
            'reason' => $context->reason,
            'changes' => $options['changes'] ?? null,
            'snapshot' => $snapshot ?: null,
            'correlation_id' => $context->correlationId,
            'occurred_at' => $context->now,
            'corrects_event_id' => $options['correctsEventId'] ?? null,
        ] + $columns, ['guard' => false]);
        $table->saveOrFail($event, ['atomic' => false]);

        $summary = method_exists($table, 'summaryOf') ? $table->summaryOf($subject, $type) : $snapshot;
        /** @var \App\Model\Table\ActivityIndexTable $index */
        $index = TableRegistry::getTableLocator()->get('ActivityIndex');
        $index->add($event, $table->getTable(), $this->getConfig('subjectType'), (int)$subject->get('id'), $summary);

        return $event;
    }

    /**
     * @return list<string>
     */
    private function constantOf(string $class, string $name): array
    {
        return defined("$class::$name") ? constant("$class::$name") : [];
    }
}
