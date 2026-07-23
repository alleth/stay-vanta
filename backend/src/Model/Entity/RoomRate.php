<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * RoomRate entity.
 *
 * @property int $id
 * @property int $property_id
 * @property int|null $room_id
 * @property string|null $description
 * @property string $base_rate
 */
class RoomRate extends Entity
{
    protected array $_accessible = [
        'property_id' => true,
        'room_id' => true,
        'description' => true,
        'base_rate' => true,
    ];
}
