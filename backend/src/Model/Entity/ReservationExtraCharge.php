<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * ReservationExtraCharge entity — one admin-configured extra charge picked on
 * a booking. `name` and `amount` (per unit) are snapshotted from the charge
 * when it was picked; the line bills `amount × quantity`.
 *
 * @property int $id
 * @property int $reservation_id
 * @property int|null $extra_charge_id
 * @property string $name
 * @property string $amount
 * @property int $quantity
 */
class ReservationExtraCharge extends Entity
{
    protected array $_accessible = [
        'reservation_id' => true,
        'extra_charge_id' => true,
        'name' => true,
        'amount' => true,
        'quantity' => true,
    ];
}
