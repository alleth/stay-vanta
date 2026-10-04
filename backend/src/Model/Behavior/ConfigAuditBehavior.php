<?php
declare(strict_types=1);

namespace App\Model\Behavior;

use App\Event\EventContext;
use App\Event\ReasonRequiredException;
use App\Model\Table\ConfigChangesTable;
use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\Http\Exception\BadRequestException;
use Cake\I18n\Date;
use Cake\ORM\Behavior;
use Cake\ORM\Entity;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;
use DateTimeInterface;
use LogicException;
use WeakMap;

/**
 * The configuration audit (build step 9, approved 2026-10-04 as C1–C9):
 * every change to a configuration row is recorded in `config_changes`, in
 * the save's own transaction, with who, when, why, each changed field's
 * before and after, and an impact class (price > booking > operational >
 * administrative). Attached to every configuration table.
 *
 * Rules it enforces, so nothing can change configuration around the audit:
 * - **no actor, no save**: a save that changes an audited field must carry
 *   the request's EventContext as `eventContext` (jobs and auto-seeding pass
 *   a system context); otherwise it is refused. Saves that touch only
 *   ignored fields (a room's occupancy status, a booklet's next number) are
 *   operational and need none;
 * - **reasons** (C2): changing a price field, or deleting, needs one;
 * - **soft delete** (C3): a hard delete is refused; setting `deleted_at`
 *   records `deleted` with every value, and deleted rows are hidden from
 *   top-level queries unless `withDeleted` (associations still show them,
 *   so a reservation keeps its deleted room's number);
 * - **no empty change**: with `refuseNoop`, a save that changes nothing is
 *   refused ("Nothing to change") and records nothing.
 *
 * Config: `entityType` (e.g. `room_rate`), `defaultImpact` (creations,
 * deletions and unlisted fields), `impacts` (field => impact), `ignore`
 * (fields never audited), `labelFields` (what the snapshot names it by),
 * `propertyField` (`id` for properties themselves), `softDelete`.
 *
 * A save may pass `auditDefer` to record later with auditDeferred(), when
 * one change spans child rows too (a menu item with its recipe and options).
 */
class ConfigAuditBehavior extends Behavior
{
    /** Never audited: bookkeeping columns. */
    private const ALWAYS_IGNORED = ['id', 'created', 'modified', 'deleted_at'];

    private const RANK = [
        ConfigChangesTable::IMPACT_ADMINISTRATIVE => 1,
        ConfigChangesTable::IMPACT_OPERATIONAL => 2,
        ConfigChangesTable::IMPACT_BOOKING => 3,
        ConfigChangesTable::IMPACT_PRICE => 4,
    ];

    /**
     * @var array<string, mixed>
     */
    protected array $_defaultConfig = [
        'entityType' => null,
        'defaultImpact' => ConfigChangesTable::IMPACT_ADMINISTRATIVE,
        'impacts' => [],
        'ignore' => [],
        'labelFields' => ['name', 'room_number', 'description', 'label', 'source', 'prefix', 'code'],
        'propertyField' => 'property_id',
        'softDelete' => false,
    ];

    /**
     * Changes computed before a save, written after it (or by auditDeferred()).
     *
     * @var \WeakMap<\Cake\Datasource\EntityInterface, array<string, mixed>>
     */
    private WeakMap $pending;

    /**
     * @param array<string, mixed> $config Behavior config.
     * @return void
     */
    public function initialize(array $config): void
    {
        if (!$this->getConfig('entityType')) {
            throw new LogicException('ConfigAuditBehavior needs an entityType.');
        }
        $this->pending = new WeakMap();
    }

    /**
     * Hide soft-deleted rows from top-level queries (associations keep them).
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event The event.
     * @param \Cake\ORM\Query\SelectQuery $query The query.
     * @param \ArrayObject<string, mixed> $options Find options (`withDeleted`).
     * @param bool $primary Whether this is the root query.
     * @return void
     */
    public function beforeFind(EventInterface $event, SelectQuery $query, ArrayObject $options, bool $primary): void
    {
        if ($this->getConfig('softDelete') && $primary && empty($options['withDeleted'])) {
            $query->where([$this->table()->aliasField('deleted_at') . ' IS' => null]);
        }
    }

    /**
     * Work out the change, refuse what the rules refuse, and keep it for afterSave().
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event The event.
     * @param \Cake\Datasource\EntityInterface $entity The row being saved.
     * @param \ArrayObject<string, mixed> $options Save options.
     * @return void
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        [$type, $changes] = $this->changeOf($entity);
        if ($type === null) {
            if (!empty($options['refuseNoop']) && empty($options['auditDefer'])) {
                throw new BadRequestException('Nothing to change: it already reads like this.');
            }
            if (empty($options['auditDefer'])) {
                return;
            }
        }
        $context = $options['eventContext'] ?? null;
        if (!$context instanceof EventContext) {
            throw new LogicException(sprintf(
                'A %s change needs the EventContext of who made it (save option `eventContext`).',
                $this->getConfig('entityType'),
            ));
        }
        $this->pending[$entity] = ['type' => $type, 'changes' => $changes, 'context' => $context];
    }

    /**
     * Record the change in the save's transaction (unless it was deferred).
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event The event.
     * @param \Cake\Datasource\EntityInterface $entity The saved row.
     * @param \ArrayObject<string, mixed> $options Save options.
     * @return void
     */
    public function afterSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        if (!isset($this->pending[$entity]) || !empty($options['auditDefer'])) {
            return;
        }
        $pending = $this->pending[$entity];
        unset($this->pending[$entity]);
        if ($pending['type'] !== null) {
            $this->write($pending['context'], $entity, $pending['type'], $pending['changes']);
        }
    }

    /**
     * Configuration is soft-deleted: a hard delete would lose the row.
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event The event.
     * @param \Cake\Datasource\EntityInterface $entity The row.
     * @param \ArrayObject<string, mixed> $options Delete options.
     * @return void
     */
    public function beforeDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        throw new LogicException(sprintf(
            'A %s is never hard-deleted: set deleted_at (soft delete) so the row and its history stay.',
            $this->getConfig('entityType'),
        ));
    }

    /**
     * Record a deferred change (saved with `auditDefer`), merged with the
     * changes of its child rows, as one change. Call inside the transaction
     * that saved them.
     *
     * @param \Cake\Datasource\EntityInterface $entity The saved row.
     * @param array<string, array{before: mixed, after: mixed}> $childChanges Child rows' before/after (e.g. `options`).
     * @param array<string, string> $childImpacts Impact per child key.
     * @param bool $refuseNoop Refuse when nothing at all changed.
     * @return void
     */
    public function auditDeferred(
        EntityInterface $entity,
        array $childChanges = [],
        array $childImpacts = [],
        bool $refuseNoop = false,
    ): void {
        $pending = $this->pending[$entity] ?? null;
        unset($this->pending[$entity]);
        if ($pending === null) {
            throw new LogicException('Nothing was deferred for this row (save it with auditDefer first).');
        }
        $childChanges = array_filter($childChanges, fn($c) => $c['before'] !== $c['after']);
        $type = $pending['type'];
        $changes = $pending['changes'];
        if ($type === ConfigChangesTable::UPDATED || ($type === null && $childChanges !== [])) {
            $type = ConfigChangesTable::UPDATED;
            $changes += $childChanges;
        } elseif ($type === ConfigChangesTable::CREATED) {
            foreach ($childChanges as $key => $change) {
                $changes['after'][$key] = $change['after'];
            }
        }
        if ($type === null) {
            if ($refuseNoop) {
                throw new BadRequestException('Nothing to change: it already reads like this.');
            }

            return;
        }
        $this->write($pending['context'], $entity, $type, $changes, $childImpacts);
    }

    /**
     * Record a row as it stands now, for the baseline import (step 9, C7):
     * `baseline_recorded` with its current values, at the context's moment
     * (import time), no actor. Call inside a transaction.
     *
     * @param \App\Event\EventContext $context An import context.
     * @param \Cake\Datasource\EntityInterface $entity The row, as stored.
     * @return void
     */
    public function recordBaseline(EventContext $context, EntityInterface $entity): void
    {
        $this->write(
            $context,
            $entity,
            ConfigChangesTable::BASELINE_RECORDED,
            ['after' => $this->valuesOf($entity, false)],
        );
    }

    /**
     * `[type, changes]`: created (`{after}`), deleted (`{before}`), updated
     * (`{field: {before, after}}`), or `[null, []]` when no audited field changed.
     *
     * @return array{0: string|null, 1: array<string, mixed>}
     */
    private function changeOf(EntityInterface $entity): array
    {
        if ($entity->isNew()) {
            return [ConfigChangesTable::CREATED, ['after' => $this->valuesOf($entity, false)]];
        }
        $deleting = $entity->isDirty('deleted_at')
            && $entity->getOriginal('deleted_at') === null
            && $entity->get('deleted_at') !== null;
        if ($deleting) {
            return [ConfigChangesTable::DELETED, ['before' => $this->valuesOf($entity, true)]];
        }
        $changes = [];
        foreach ($entity->getDirty() as $field) {
            if ($this->ignored($field)) {
                continue;
            }
            $before = $this->normalize($field, $entity->getOriginal($field));
            $after = $this->normalize($field, $entity->get($field));
            if ($before !== $after) {
                $changes[$field] = ['before' => $before, 'after' => $after];
            }
        }

        return $changes === [] ? [null, []] : [ConfigChangesTable::UPDATED, $changes];
    }

    /**
     * Write the change and its index row (in the caller's transaction).
     *
     * @param array<string, mixed> $changes What changed.
     * @param array<string, string> $extraImpacts Impact per non-column key (child rows).
     */
    private function write(
        EventContext $context,
        EntityInterface $entity,
        string $type,
        array $changes,
        array $extraImpacts = [],
    ): void {
        $impact = $this->impactOf($type, $changes, $extraImpacts);
        $priceChange = $type === ConfigChangesTable::UPDATED && $impact === ConfigChangesTable::IMPACT_PRICE;
        if ($priceChange && $context->reason === null) {
            throw new ReasonRequiredException('Changing a price needs a reason.');
        }
        if ($type === ConfigChangesTable::DELETED && $context->reason === null) {
            throw new ReasonRequiredException('Deleting needs a reason.');
        }

        /** @var \App\Model\Table\ConfigChangesTable $ledger */
        $ledger = TableRegistry::getTableLocator()->get('ConfigChanges');
        $row = $ledger->newEntity([], ['validate' => false]);
        $row->patch([
            'entity_type' => $this->getConfig('entityType'),
            'entity_id' => (int)$entity->get('id'),
            'impact' => $impact,
            'changes' => $changes,
        ], ['guard' => false]);
        // The ledger needs a subject with an id and a property: a property
        // record is its own property.
        $subject = new Entity([
            'id' => (int)$entity->get('id'),
            'property_id' => (int)$entity->get($this->getConfig('propertyField')),
        ]);
        /** @var \App\Model\Behavior\EventLedgerBehavior $ledgerBehavior */
        $ledgerBehavior = $ledger->getBehavior('EventLedger');
        $ledgerBehavior->write($context, $type, $row, $subject, [
            'snapshot' => ['label' => $this->labelOf($entity)],
            'subjectType' => $this->getConfig('entityType'),
        ]);
    }

    /**
     * The highest impact among the changed fields; a creation or deletion
     * takes the entity's default.
     *
     * @param array<string, mixed> $changes What changed.
     * @param array<string, string> $extraImpacts Impact per child key.
     */
    private function impactOf(string $type, array $changes, array $extraImpacts): string
    {
        $default = (string)$this->getConfig('defaultImpact');
        if ($type !== ConfigChangesTable::UPDATED) {
            return $default;
        }
        $impacts = (array)$this->getConfig('impacts') + $extraImpacts;
        $best = ConfigChangesTable::IMPACT_ADMINISTRATIVE;
        foreach (array_keys($changes) as $field) {
            $impact = $impacts[$field] ?? $default;
            if (self::RANK[$impact] > self::RANK[$best]) {
                $best = $impact;
            }
        }

        return $best;
    }

    /**
     * Every audited value of the row, normalized; `$original` reads the
     * values before this save's changes.
     *
     * @return array<string, mixed>
     */
    private function valuesOf(EntityInterface $entity, bool $original): array
    {
        $values = [];
        foreach ($this->table()->getSchema()->columns() as $field) {
            if ($this->ignored($field)) {
                continue;
            }
            $values[$field] = $this->normalize($field, $original ? $entity->getOriginal($field) : $entity->get($field));
        }

        return $values;
    }

    /**
     * Whether a field stays out of the audit.
     */
    private function ignored(string $field): bool
    {
        return in_array($field, self::ALWAYS_IGNORED, true)
            || in_array($field, (array)$this->getConfig('ignore'), true)
            || !$this->table()->getSchema()->hasColumn($field);
    }

    /**
     * One form per column type, so "1000.00" and 1000 compare equal and the
     * stored before/after read naturally.
     */
    private function normalize(string $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        $type = $this->table()->getSchema()->getColumnType($field);
        if ($type === 'boolean') {
            return (bool)$value;
        }
        if (in_array($type, ['integer', 'biginteger', 'smallinteger', 'tinyinteger'], true)) {
            return (int)$value;
        }
        if (in_array($type, ['decimal', 'float'], true) && is_numeric($value)) {
            $number = round((float)$value, 4);

            return $number == (int)$number ? (int)$number : $number;
        }
        if ($value instanceof Date || $value instanceof DateTimeInterface) {
            return $type === 'date' ? $value->format('Y-m-d') : $value->format('Y-m-d H:i:s');
        }

        return is_scalar($value) ? (string)$value : $value;
    }

    /**
     * What the change log names the row by.
     */
    private function labelOf(EntityInterface $entity): ?string
    {
        foreach ((array)$this->getConfig('labelFields') as $field) {
            $value = $entity->get($field);
            if ($value !== null && $value !== '') {
                return (string)$value;
            }
        }

        return null;
    }
}
