<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * BookingSource entity — one admin-defined OTA booking source. `code` is the
 * stable value stored on reservations/promo rates (fixed at creation, never
 * edited); `name` is the editable display label.
 *
 * @property int $id
 * @property int $property_id
 * @property string $code
 * @property string $name
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 */
class BookingSource extends Entity
{
    protected array $_accessible = [
        'property_id' => true,
        'code' => true,
        'name' => true,
    ];
}
