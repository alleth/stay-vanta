<?php
declare(strict_types=1);

namespace App\Model\Table;

use ArrayObject;
use Cake\Database\Expression\QueryExpression;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Closure;
use LogicException;

/**
 * Role definitions change only through App\Auth\RoleDefinitions, which
 * records each change in `role_events` (final review G7): a save or delete
 * without its `roleDefinitions` option, and any bulk update or delete, is
 * refused.
 */
trait RoleDefinitionGuardTrait
{
    /**
     * @param \Cake\Event\EventInterface $event The event.
     * @param \Cake\Datasource\EntityInterface $entity The row.
     * @param \ArrayObject $options Save options.
     * @return void
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        if (empty($options['roleDefinitions'])) {
            throw new LogicException('A role definition changes only through App\Auth\RoleDefinitions.');
        }
    }

    /**
     * @param \Cake\Event\EventInterface $event The event.
     * @param \Cake\Datasource\EntityInterface $entity The row.
     * @param \ArrayObject $options Delete options.
     * @return void
     */
    public function beforeDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        if (empty($options['roleDefinitions'])) {
            throw new LogicException('A role definition changes only through App\Auth\RoleDefinitions.');
        }
    }

    /**
     * @inheritDoc
     */
    public function updateAll(
        QueryExpression|Closure|array|string $fields,
        QueryExpression|Closure|array|string|null $conditions,
    ): int {
        throw new LogicException('A role definition changes only through App\Auth\RoleDefinitions.');
    }

    /**
     * @inheritDoc
     */
    public function deleteAll(QueryExpression|Closure|array|string|null $conditions): int
    {
        throw new LogicException('A role definition changes only through App\Auth\RoleDefinitions.');
    }
}
