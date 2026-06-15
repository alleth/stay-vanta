<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Users model.
 *
 * @method \App\Model\Entity\User newEmptyEntity()
 * @method \App\Model\Entity\User get(mixed $primaryKey, array $options = [])
 */
class UsersTable extends Table
{
    public const ROLES = ['owner', 'admin', 'receptionist'];

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('users');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Properties');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('name')
            ->maxLength('name', 150)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->email('email')
            ->requirePresence('email', 'create')
            ->notEmptyString('email')
            ->add('email', 'unique', [
                'rule' => 'validateUnique',
                'provider' => 'table',
                'message' => 'This email is already in use.',
            ]);

        $validator
            ->scalar('password')
            ->minLength('password', 8)
            ->requirePresence('password', 'create')
            ->notEmptyString('password');

        $validator
            ->inList('role', self::ROLES)
            ->requirePresence('role', 'create');

        return $validator;
    }
}
