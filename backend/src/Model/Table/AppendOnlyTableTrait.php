<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Database\Expression\QueryExpression;
use Cake\ORM\Query\DeleteQuery;
use Cake\ORM\Query\UpdateQuery;
use Closure;
use LogicException;

/**
 * For ledger tables (events, activity_index): rows are written once and never
 * changed or removed. A mistake is corrected by a new event that references
 * the original (`corrects_event_id`).
 *
 * AppendOnlyBehavior refuses save() of an existing row and delete(); this
 * closes the bulk paths that bypass behaviors (updateAll/deleteAll and their
 * query builders). The one sanctioned change, privacy redaction, will be a
 * dedicated routine that records its own `redacted` event.
 */
trait AppendOnlyTableTrait
{
    /**
     * @inheritDoc
     */
    public function updateAll(
        QueryExpression|Closure|array|string $fields,
        QueryExpression|Closure|array|string|null $conditions,
    ): int {
        throw new LogicException(static::class . ' is append-only: rows are never updated.');
    }

    /**
     * @inheritDoc
     */
    public function deleteAll(QueryExpression|Closure|array|string|null $conditions): int
    {
        throw new LogicException(static::class . ' is append-only: rows are never deleted.');
    }

    /**
     * @inheritDoc
     */
    public function updateQuery(): UpdateQuery
    {
        throw new LogicException(static::class . ' is append-only: rows are never updated.');
    }

    /**
     * @inheritDoc
     */
    public function deleteQuery(): DeleteQuery
    {
        throw new LogicException(static::class . ' is append-only: rows are never deleted.');
    }
}
