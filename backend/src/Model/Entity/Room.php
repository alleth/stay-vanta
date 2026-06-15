<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Room entity.
 *
 * @property int $id
 * @property int $property_id
 * @property string $room_number
 * @property string|null $room_type
 * @property string $status   available | occupied | maintenance
 */
class Room extends Entity
{
    protected array $_accessible = [
        'property_id' => true,
        'room_number' => true,
        'room_type' => true,
        'status' => true,
    ];
}
