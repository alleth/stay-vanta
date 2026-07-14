<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * PromoRate entity.
 *
 * @property int $id
 * @property int $property_id
 * @property int|null $room_id
 * @property string $source
 * @property string $rate
 */
class PromoRate extends Entity
{
    protected array $_accessible = [
        'property_id' => true,
        'room_id' => true,
        'source' => true,
        'rate' => true,
    ];
}
