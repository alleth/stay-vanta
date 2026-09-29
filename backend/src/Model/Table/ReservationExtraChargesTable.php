<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * ReservationExtraCharges model — the admin-configured extra charges picked
 * on a booking, each with a quantity. Name and unit amount are snapshots of
 * the charge at the time it was picked (see the migration), and
 * ReservationsTable::quote() adds them on top of the room after its
 * discounts: they're services, not the room, so no discount covers them.
 *
 * @method \App\Model\Entity\ReservationExtraCharge newEmptyEntity()
 */
class ReservationExtraChargesTable extends Table
{
    /** A quantity above this is almost certainly a typo, not a request. */
    public const MAX_QUANTITY = 99;

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('reservation_extra_charges');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Reservations');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->notEmptyString('name');
        $validator->decimal('amount')->greaterThanOrEqual('amount', 0);
        $validator->integer('quantity')->range('quantity', [1, self::MAX_QUANTITY]);

        return $validator;
    }
}
