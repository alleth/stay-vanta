<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Guest entity.
 *
 * @property int $id
 * @property int $property_id
 * @property string $full_name
 * @property string|null $nationality
 * @property string $guest_type   local | foreign
 */
class Guest extends Entity
{
    protected array $_accessible = [
        'property_id' => true,
        'full_name' => true,
        'nationality' => true,
        'guest_type' => true,
    ];
}
