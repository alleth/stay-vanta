<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\StatutoryDiscount;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * ReservationDiscounts model — one row per Senior/PWD beneficiary on a
 * booking. A room can hold several qualified guests, and the statutory 20%
 * only covers each one's own share of it, so the count of these rows (against
 * `reservations.total_guests`) is what ReservationsTable::quote() prices from.
 *
 * @method \App\Model\Entity\ReservationDiscount newEmptyEntity()
 */
class ReservationDiscountsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('reservation_discounts');
        $this->setDisplayField('beneficiary_name');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Reservations');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->inList('discount_type', StatutoryDiscount::TYPES);
        // A beneficiary who can't be named is a discount nobody can account
        // for afterwards, which is the whole reason these rows exist.
        $validator->notEmptyString('beneficiary_name', 'Enter the beneficiary name.');
        $validator->notEmptyString('id_number', 'Enter the beneficiary ID number.');

        return $validator;
    }
}
