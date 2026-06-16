<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Property entity (a hotel or resort subscribing to the platform).
 *
 * @property int $id
 * @property string $name
 * @property string $type   hotel | resort
 * @property string|null $address
 * @property bool $is_active
 * @property string $subscription_status   active | inactive
 * @property \Cake\I18n\Date|null $subscription_expires_at
 * @property string $subscription_fee   monthly fee paid to the platform owner
 * @property bool $subscription_active   (virtual) status active AND not past expiry
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
        'subscription_status' => true,
        'subscription_expires_at' => true,
        'subscription_fee' => true,
        'users' => true,
        '*' => false,
    ];

    protected array $_virtual = ['subscription_active'];

    /**
     * A subscription counts as active when its status is `active` and it has
     * either no expiry or an expiry that has not yet passed.
     */
    protected function _getSubscriptionActive(): bool
    {
        if (($this->subscription_status ?? 'active') !== 'active') {
            return false;
        }

        $expires = $this->subscription_expires_at;

        return $expires === null || !$expires->isPast();
    }
}
