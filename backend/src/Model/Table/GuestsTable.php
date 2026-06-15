<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Guests model. Expanded by the Guests module; created here because
 * Reservations reference it (fallback table classes are disabled).
 *
 * @method \App\Model\Entity\Guest newEmptyEntity()
 * @method \App\Model\Entity\Guest get(mixed $primaryKey, array $options = [])
 */
class GuestsTable extends Table
{
    public const TYPES = ['local', 'foreign'];

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('guests');
        $this->setDisplayField('full_name');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Properties');
        $this->hasMany('Reservations');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->requirePresence('property_id', 'create')
            ->integer('property_id');

        $validator
            ->scalar('full_name')
            ->maxLength('full_name', 191)
            ->requirePresence('full_name', 'create')
            ->notEmptyString('full_name');

        $validator->inList('guest_type', self::TYPES);

        return $validator;
    }
}
