<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Permissions;
use App\Model\BusinessTime;
use App\Model\Subscription;
use App\Platform\Enforcement;

/**
 * The platform owner's view: subscription revenue and subscriber counts
 * (GET /api/platform/dashboard), and subscription enforcement (G6,
 * GET|POST /api/platform/enforcement).
 */
class PlatformController extends AppController
{
    /**
     * GET /api/platform/dashboard  (Platform Owner only)
     *
     * Subscription revenue (from each subscriber's monthly fee) plus the
     * active-user counts: registered hotels/resorts and registered admins.
     */
    public function dashboard(): void
    {
        $this->authorize(
            Permissions::PLATFORM_DASHBOARD_VIEW,
            'Only the Platform Owner can view the platform dashboard.',
        );

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

    /**
     * GET /api/platform/enforcement  (Platform Owner)
     *
     * The subscription-enforcement phase (G6): the phase set, the emergency
     * ceiling, the phase in force, the preview (properties per stage by
     * date, and what each phase would enforce: E-D6) and the history, newest
     * first, with who, why and the request id.
     */
    public function enforcement(): void
    {
        $this->request->allowMethod('get');
        $this->authorize(
            Permissions::PLATFORM_DASHBOARD_VIEW,
            'Only the Platform Owner can view subscription enforcement.',
        );
        $this->respondWithEnforcement();
    }

    /**
     * POST /api/platform/enforcement {phase, reason}  (Platform Owner, elevated)
     *
     * One phase forward or any phase back, with a reason (E-D3); recorded as
     * `enforcement_phase_raised` / `_lowered`. Answers like GET.
     */
    public function changeEnforcement(): void
    {
        $this->request->allowMethod('post');
        $this->authorizeElevated(
            Permissions::PLATFORM_ENFORCEMENT_MANAGE,
            'Only the Platform Owner can change subscription enforcement.',
        );
        Enforcement::change($this->eventContext(), (string)$this->request->getData('phase'));
        $this->respondWithEnforcement();
    }

    /**
     * The phase, its preview and its history.
     */
    private function respondWithEnforcement(): void
    {
        $setting = $this->fetchTable('PlatformSettings')->find()
            ->where(['setting_key' => Enforcement::KEY])->first();
        $ceilingSetting = $this->fetchTable('PlatformSettings')->find()
            ->where(['setting_key' => Enforcement::CEILING_KEY])->first();
        $ids = array_filter([$setting?->get('id'), $ceilingSetting?->get('id')]);
        $rows = $ids === [] ? [] : $this->fetchTable('PlatformSettingEvents')->find()
            ->where(['platform_setting_id IN' => $ids])
            ->orderBy(['id' => 'DESC'])
            ->limit(100)
            ->all()->toList();
        $actorIds = array_values(array_unique(array_filter(array_map(fn($r) => $r->actor_id, $rows))));
        $names = $actorIds === [] ? [] : $this->fetchTable('Users')->find()
            ->select(['id', 'name'])->where(['id IN' => $actorIds])->all()->combine('id', 'name')->toArray();

        $this->set('enforcement', [
            'phase' => Enforcement::phase(),
            'ceiling' => Enforcement::ceiling(),
            'effective' => Enforcement::effective(),
            'phases' => Subscription::MODES,
            'preview' => Enforcement::preview(),
            'history' => array_map(fn($r) => [
                'id' => (int)$r->id,
                'event' => $r->event_type,
                'before' => $r->value_before,
                'after' => $r->value_after,
                'actor' => $r->actor_id !== null ? ($names[$r->actor_id] ?? null) : null,
                'actor_role' => $r->actor_role,
                'source' => $r->source,
                'reason' => $r->reason,
                'changes' => $r->changes,
                'snapshot' => $r->snapshot,
                'request_id' => $r->correlation_id,
                'at' => $r->occurred_at,
            ], $rows),
        ]);
        $this->viewBuilder()->setOption('serialize', ['enforcement']);
    }
}
