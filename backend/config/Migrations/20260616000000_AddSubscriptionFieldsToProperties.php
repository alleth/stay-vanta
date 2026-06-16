<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Subscription state for each registered hotel/resort.
 *
 * StayVanta is a subscription-based platform: the `owner` role is the platform
 * operator, each `properties` row is a subscribing hotel/resort, and `admin`/
 * `receptionist` are that hotel's staff. These columns let the owner report on
 * how many subscriptions are active vs inactive (see ReportsController).
 */
class AddSubscriptionFieldsToProperties extends BaseMigration
{
    public function change(): void
    {
        $this->table('properties')
            ->addColumn('subscription_status', 'string', [
                'limit' => 20,
                'default' => 'active', // active | inactive
                'after' => 'is_active',
            ])
            ->addColumn('subscription_expires_at', 'date', [
                'null' => true,
                'after' => 'subscription_status',
            ])
            ->update();
    }
}
