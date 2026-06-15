<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Property entity (a hotel or resort).
 *
 * @property int $id
 * @property string $name
 * @property string $type   hotel | resort
 * @property string|null $address
 * @property bool $is_active
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 */
class Property extends Entity
{
    protected array $_accessible = [
        'name' => true,
        'type' => true,
        'address' => true,
        'is_active' => true,
        'users' => true,
        '*' => false,
    ];
}
