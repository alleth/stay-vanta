<?php
declare(strict_types=1);

namespace App\Model\Behavior;

use App\Event\EventContext;
use App\Event\ReasonRequiredException;
use Cake\Datasource\EntityInterface;
use Cake\Log\Log;
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
 * Two ways in, both requiring the transaction of the change they describe
 * (if the event can't be written, the change rolls back with it):
 * - record(): an `*_events` table builds and stores a whole event row
 *   (tables use EventLedgerTableTrait for their record() method);
 * - write(): a ledger that builds its own rows (stock_movements, whose row
 *   is the movement itself) has the shared columns stamped and the row
 *   stored and indexed.
 * Either way one activity_index row is written alongside.
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
        // Column holding the actor (stock_movements keeps receptionist_id).
        'actorColumn' => 'actor_id',
        // Column holding the event type, or null when the row implies it
        // (a stock movement's direction); activity_index always has it.
        'eventTypeColumn' => 'event_type',
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
     * Record that `$type` happened to `$subject`, as a new row of this table.
     *
     * @param \App\Event\EventContext $context Who, for whom, why, which action.
     * @param string $type One of the table's TYPES.
     * @param \Cake\Datasource\EntityInterface $subject The record the event is about (has property_id).
     * @param array<string, mixed> $options `changes` (before/after), `columns` (typed columns such as
     *   amount), `correctsEventId` (the event this one corrects), `snapshot` (facts added to the
     *   table's own snapshot).
     * @return \Cake\Datasource\EntityInterface The stored event.
     */
    public function record(
        EventContext $context,
        string $type,
        EntityInterface $subject,
        array $options = [],
    ): EntityInterface {
        $table = $this->table();
        $columns = (array)($options['columns'] ?? []);
        foreach (array_keys($columns) as $column) {
            if (!$table->getSchema()->hasColumn($column)) {
                throw new InvalidArgumentException($table::class . " has no typed column '$column'.");
            }
        }

        $event = $table->newEntity([], ['validate' => false]);
        $event->patch([
            $this->getConfig('subjectKey') => $subject->get('id'),
            'changes' => $options['changes'] ?? null,
            'corrects_event_id' => $options['correctsEventId'] ?? null,
        ] + $columns, ['guard' => false]);

        // `snapshot` adds facts to the table's own snapshot (e.g. a
        // reservation's price, step 9); it never replaces them.
        $writeOptions = [];
        if (!empty($options['snapshot'])) {
            $base = method_exists($table, 'snapshotOf') ? $table->snapshotOf($subject) : [];
            $writeOptions['snapshot'] = $base + (array)$options['snapshot'];
        }

        return $this->write($context, $type, $event, $subject, $writeOptions);
    }

    /**
     * Stamp the shared columns on a row this ledger built, store it, and
     * index it. The row's own reason (e.g. a stock movement's "restock") is
     * kept; the context's reason fills it only when it's empty.
     *
     * @param \App\Event\EventContext $context Who, for whom, why, which action.
     * @param string $type One of the table's TYPES.
     * @param \Cake\Datasource\EntityInterface $row The new ledger row, not yet saved.
     * @param \Cake\Datasource\EntityInterface $subject The record it's about (has property_id).
     * @param array<string, mixed> $options `snapshot` and `summary` to use instead of the table's,
     *   `subjectType` for the index row when the ledger covers several.
     * @return \Cake\Datasource\EntityInterface The stored row.
     */
    public function write(
        EventContext $context,
        string $type,
        EntityInterface $row,
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
        if (!$row->isNew()) {
            throw new LogicException('Only a new row can be recorded.');
        }

        $class = $table::class;
        if (!in_array($type, $this->constantOf($class, 'TYPES'), true)) {
            throw new InvalidArgumentException("'$type' is not a $class event type.");
        }
        if ($context->reason === null && in_array($type, $this->constantOf($class, 'REQUIRES_REASON'), true)) {
            if (!in_array($type, $this->constantOf($class, 'REASON_GRACE'), true)) {
                throw new ReasonRequiredException();
            }
            // Accepted only during a compatibility window. Every use is
            // logged: the window closes once these lines stop appearing.
            Log::warning(sprintf(
                'reason grace used: %s %s recorded without a reason (subject %s)',
                $table->getTable(),
                $type,
                (string)$subject->get('id'),
            ));
        }

        $propertyId = (int)$subject->get('property_id');
        if ($propertyId === 0) {
            throw new LogicException('An event subject must belong to a property.');
        }
        if ($context->propertyId !== null && $context->propertyId !== $propertyId) {
            throw new LogicException('An event cannot be recorded for another property than the request\'s.');
        }

        $snapshot = $options['snapshot']
            ?? (method_exists($table, 'snapshotOf') ? $table->snapshotOf($subject) : []);
        $stamp = [
            'property_id' => $propertyId,
            $this->getConfig('actorColumn') => $context->actorId,
            'actor_role' => $context->actorRole,
            'source' => $context->source,
            'snapshot' => $snapshot ?: null,
            'correlation_id' => $context->correlationId,
            'occurred_at' => $context->now,
        ];
        if ($this->getConfig('eventTypeColumn') !== null) {
            $stamp[$this->getConfig('eventTypeColumn')] = $type;
        }
        if (($row->get('reason') === null || $row->get('reason') === '') && $context->reason !== null) {
            $stamp['reason'] = $context->reason;
        }
        $row->patch($stamp, ['guard' => false]);
        $table->saveOrFail($row, ['atomic' => false]);

        $summary = $options['summary']
            ?? (method_exists($table, 'summaryOf') ? $table->summaryOf($subject, $type) : $snapshot);
        /** @var \App\Model\Table\ActivityIndexTable $index */
        $index = TableRegistry::getTableLocator()->get('ActivityIndex');
        $index->add([
            'property_id' => $propertyId,
            'occurred_at' => $context->now,
            'actor_id' => $context->actorId,
            // One ledger may cover several subject types (config_changes, step 9).
            'subject_type' => $options['subjectType'] ?? $this->getConfig('subjectType'),
            'subject_id' => (int)$subject->get('id'),
            'event_table' => $table->getTable(),
            'event_id' => (int)$row->get('id'),
            'event_type' => $type,
            'correlation_id' => $context->correlationId,
            'summary' => $summary ?: null,
        ]);

        return $row;
    }

    /**
     * @return list<string>
     */
    private function constantOf(string $class, string $name): array
    {
        return defined("$class::$name") ? constant("$class::$name") : [];
    }
}
