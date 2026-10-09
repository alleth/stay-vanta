<?php
declare(strict_types=1);

namespace App\Model\Table;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Table;
use LogicException;

/**
 * Platform-wide settings (final review G6). A value changes only together
 * with its `platform_setting_events` row (App\Platform\Enforcement), so a
 * save without the `ledger` option, and any delete, is refused.
 */
class PlatformSettingsTable extends Table
{
    /**
     * @param array<string, mixed> $config Table config.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('platform_settings');
        $this->addBehavior('Timestamp');
    }

    /**
     * @param \Cake\Event\EventInterface $event The event.
     * @param \Cake\Datasource\EntityInterface $entity The setting.
     * @param \ArrayObject $options Save options.
     * @return void
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        if (empty($options['ledger'])) {
            throw new LogicException('A platform setting changes only with its event (App\Platform\Enforcement).');
        }
    }

    /**
     * @param \Cake\Event\EventInterface $event The event.
     * @param \Cake\Datasource\EntityInterface $entity The setting.
     * @param \ArrayObject $options Delete options.
     * @return void
     */
    public function beforeDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        throw new LogicException('Platform settings are never deleted.');
    }
}
