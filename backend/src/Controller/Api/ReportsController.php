<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Http\Exception\ForbiddenException;

/**
 * Platform-owner reporting.
 *
 * The owner runs the StayVanta platform itself (no property of their own), so
 * these reports are intentionally platform-wide and owner-only.
 */
class ReportsController extends AppController
{
    /**
     * GET /api/reports/overview  (owner only)
     *
     * The two reports the owner cares about:
     *  - how many hotels/resorts are registered (with the list), and
     *  - how many subscriptions are active vs inactive.
     */
    public function overview(): void
    {
        if (!$this->userHasRole('owner')) {
            throw new ForbiddenException('Only the platform owner can view platform reports.');
        }

        $properties = $this->fetchTable('Properties')
            ->find()
            ->orderBy(['Properties.name' => 'ASC'])
            ->all();

        $active = 0;
        $inactive = 0;
        foreach ($properties as $property) {
            $property->subscription_active ? $active++ : $inactive++;
        }

        $this->set('overview', [
            'total_properties' => $active + $inactive,
            'active_subscriptions' => $active,
            'inactive_subscriptions' => $inactive,
            'properties' => $properties,
        ]);
        $this->viewBuilder()->setOption('serialize', ['overview']);
    }
}
