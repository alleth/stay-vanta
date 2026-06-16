<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Properties (hotels & resorts) model.
 *
 * @method \App\Model\Entity\Property newEmptyEntity()
 */
class PropertiesTable extends Table
{
    public const TYPES = ['hotel', 'resort'];

    public const SUBSCRIPTION_STATUSES = ['active', 'inactive'];

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('properties');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->hasMany('Users');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('name')
            ->maxLength('name', 150)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator->inList('type', self::TYPES);

        $validator
            ->inList('subscription_status', self::SUBSCRIPTION_STATUSES)
            ->allowEmptyString('subscription_status');

        $validator
            ->date('subscription_expires_at')
            ->allowEmptyDate('subscription_expires_at');

        return $validator;
    }
}
