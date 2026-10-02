<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Model\BusinessTime;
use Cake\Http\Exception\ForbiddenException;

/**
 * The platform owner's view: subscription revenue and subscriber counts.
 *
 * GET /api/platform/dashboard. The old /api/reports/owner-dashboard route
 * points here too until it's removed.
 */
class PlatformController extends AppController
{
    /**
     * GET /api/platform/dashboard  (Platform Owner only; old path /api/reports/owner-dashboard)
     *
     * Subscription revenue (from each subscriber's monthly fee) plus the
     * active-user counts: registered hotels/resorts and registered admins.
     */
    public function dashboard(): void
    {
        if (!$this->userHasRole('owner')) {
            throw new ForbiddenException('Only the Platform Owner can view the platform dashboard.');
        }

        $properties = $this->fetchTable('Properties')->find()->all();
        $active = 0;
        $inactive = 0;
        $monthlyRecurring = 0.0;
        foreach ($properties as $property) {
            if ($property->subscription_active) {
                $active++;
                $monthlyRecurring += (float)$property->subscription_fee;
            } else {
                $inactive++;
            }
        }

        $admins = $this->fetchTable('Users')
            ->find()
            ->where(['role' => 'admin', 'is_active' => true])
            ->count();

        // Project the recurring monthly fee onto each period.
        $monthsElapsed = (int)BusinessTime::now()->format('n'); // Jan = 1 ... current month

        $this->set('dashboard', [
            'counts' => [
                'hotels' => $active + $inactive,
                'active_subscriptions' => $active,
                'inactive_subscriptions' => $inactive,
                'admins' => $admins,
            ],
            'revenue' => [
                'monthly_recurring' => round($monthlyRecurring, 2),
                'week' => round($monthlyRecurring * 12 / 52, 2),
                'month' => round($monthlyRecurring, 2),
                'ytd' => round($monthlyRecurring * $monthsElapsed, 2),
            ],
        ]);
        $this->viewBuilder()->setOption('serialize', ['dashboard']);
    }
}
