<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * ExtraCharge entity.
 *
 * @property int $id
 * @property int $property_id
 * @property string|null $code
 * @property string $name
 * @property string $amount
 * @property bool $is_active
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 */
class ExtraCharge extends Entity
{
    protected array $_accessible = [
        'property_id' => true,
        'code' => true,
        'name' => true,
        'amount' => true,
        'is_active' => true,
    ];

    /** The built-in early check-in fee row carries this fixed code. */
    public const CODE_EARLY_CHECK_IN = 'early_check_in';
}
