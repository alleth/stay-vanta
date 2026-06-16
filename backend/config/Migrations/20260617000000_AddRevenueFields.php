<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Revenue fields.
 *
 * - properties.subscription_fee — the monthly fee a hotel/resort pays the
 *   platform owner; the basis for the owner's revenue reporting.
 * - invoices.settled_at — when a tab was actually collected, so hotel revenue
 *   can be bucketed by week/month/YTD (created/modified are poor proxies).
 */
class AddRevenueFields extends BaseMigration
{
    public function change(): void
    {
        $this->table('properties')
            ->addColumn('subscription_fee', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'default' => 0,
                'after' => 'subscription_expires_at',
            ])
            ->update();

        $this->table('invoices')
            ->addColumn('settled_at', 'datetime', [
                'null' => true,
                'after' => 'status',
            ])
            ->update();
    }
}
