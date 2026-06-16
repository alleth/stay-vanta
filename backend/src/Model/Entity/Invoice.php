<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Invoice entity — a guest's running tab. Charge-to-room food orders append
 * lines here.
 *
 * @property int $id
 * @property int $property_id
 * @property int|null $guest_id
 * @property int|null $reservation_id
 * @property string $total
 * @property string $status   open | settled
 * @property \Cake\I18n\DateTime|null $settled_at
 */
class Invoice extends Entity
{
    protected array $_accessible = [
        'property_id' => true,
        'guest_id' => true,
        'reservation_id' => true,
        'total' => true,
        'status' => true,
    ];
}
