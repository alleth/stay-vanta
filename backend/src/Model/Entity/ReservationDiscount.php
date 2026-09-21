<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * ReservationDiscount entity — one Senior/PWD beneficiary on a booking (a
 * room can hold several, e.g. an elderly couple sharing it), recorded with
 * the name and ID number the discount was granted against.
 *
 * There is no `amount` here, unlike FoodOrderDiscount: a booking's price is
 * recomputed live by ReservationsTable::quote(), so the peso figure is only
 * ever fixed when ReservationsController::postRoomCharge() writes this
 * beneficiary's own invoice line.
 *
 * @property int $id
 * @property int $reservation_id
 * @property string $discount_type  senior | pwd
 * @property string $beneficiary_name
 * @property string $id_number
 */
class ReservationDiscount extends Entity
{
    protected array $_accessible = [
        'reservation_id' => true,
        'discount_type' => true,
        'beneficiary_name' => true,
        'id_number' => true,
    ];
}
