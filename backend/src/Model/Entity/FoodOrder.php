<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * FoodOrder entity.
 *
 * `receptionist_id` is the accountability stamp (who took the order).
 * `payment_status`: paid | charge_to_room | unpaid. charge_to_room orders are
 * mirrored onto the guest's invoice. `payment_method` records how a `paid`
 * order was actually settled (cash/e-wallet); null otherwise.
 *
 * @property int $id
 * @property int $property_id
 * @property int|null $guest_id
 * @property int|null $room_id
 * @property int|null $reservation_id
 * @property int $receptionist_id
 * @property string $status         open | served | cancelled
 * @property string $payment_status paid | charge_to_room | unpaid
 * @property string|null $payment_method cash | gcash | maya | gotyme
 * @property string $total
 */
class FoodOrder extends Entity
{
    protected array $_accessible = [
        'property_id' => true,
        'guest_id' => true,
        'room_id' => true,
        'reservation_id' => true,
        'receptionist_id' => true,
        'status' => true,
        'payment_status' => true,
        'payment_method' => true,
        'total' => true,
        'discount_type' => true,
        'discount_name' => true,
        'discount_id_number' => true,
        'cooking_charge' => true,
        'food_order_items' => true,
    ];
}
