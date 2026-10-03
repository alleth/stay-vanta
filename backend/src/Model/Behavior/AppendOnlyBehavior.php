<?php
declare(strict_types=1);

namespace App\Model\Behavior;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Behavior;
use LogicException;

/**
 * Refuses to change or delete a stored row: save() only inserts, delete()
 * always fails. Pair with AppendOnlyTableTrait, which closes the bulk paths.
 */
class AppendOnlyBehavior extends Behavior
{
    /**
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event The event.
     * @param \Cake\Datasource\EntityInterface $entity The row being saved.
     * @param \ArrayObject<string, mixed> $options Save options.
     * @return void
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        if (!$entity->isNew()) {
            throw new LogicException($this->table()->getAlias() . ' is append-only: rows are never updated.');
        }
    }

    /**
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event The event.
     * @param \Cake\Datasource\EntityInterface $entity The row being deleted.
     * @param \ArrayObject<string, mixed> $options Delete options.
     * @return void
     */
    public function beforeDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        throw new LogicException($this->table()->getAlias() . ' is append-only: rows are never deleted.');
    }
}
