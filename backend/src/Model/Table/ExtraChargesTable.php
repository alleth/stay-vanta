<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\ExtraCharge;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * ExtraCharges model — admin-configurable surcharges per property.
 *
 * @method \App\Model\Entity\ExtraCharge newEmptyEntity()
 * @method \App\Model\Entity\ExtraCharge get(mixed $primaryKey, array $options = [])
 */
class ExtraChargesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('extra_charges');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Properties');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->requirePresence('property_id', 'create')
            ->integer('property_id');

        $validator
            ->scalar('name')
            ->maxLength('name', 100)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->numeric('amount')
            ->greaterThanOrEqual('amount', 0, 'Amount cannot be negative.');

        return $validator;
    }

    /**
     * The property's built-in early check-in fee row, created (at 0) on first
     * access so the Extra Charges tab and the check-in flow always have it.
     */
    public function earlyCheckInFor(int $propertyId): ExtraCharge
    {
        $charge = $this->find()
            ->where(['property_id' => $propertyId, 'code' => ExtraCharge::CODE_EARLY_CHECK_IN])
            ->first();

        if ($charge === null) {
            $charge = $this->newEntity([
                'property_id' => $propertyId,
                'code' => ExtraCharge::CODE_EARLY_CHECK_IN,
                'name' => 'Early check-in',
                'amount' => 0,
                'is_active' => true,
            ]);
            $this->saveOrFail($charge);
        }

        return $charge;
    }
}
