<?php
declare(strict_types=1);

namespace App\Auth;

use App\Model\Entity\User;
use App\Model\Subscription;
use Cake\ORM\TableRegistry;

/**
 * Who may do what, and where (Permissions Phase 2, build step 10b): read from
 * the person's active membership and its role's grants, or from the platform
 * flag. AppController and sign-in both ask here, so the screens and the server
 * always agree.
 *
 * One property per person today: the oldest active membership is the one used
 * (a property switcher is Phase 3). The Platform Owner holds the platform's
 * grants, or, while a support session is open, read-only access to that one
 * property (Permissions::supportGrants()).
 *
 * There is no other way in (G8 removed the one-release fallbacks): no active
 * membership and no platform flag means no access. `users.role` /
 * `users.property_id` are never read for access.
 */
final class AccessResolver
{
    /**
     * @param \App\Model\Entity\User $user The signed-in person.
     */
    public function resolve(User $user): Access
    {
        if ($user->get('is_platform')) {
            // An open support session (A6): that one property, read-only.
            $session = TableRegistry::getTableLocator()->get('SupportSessions')->find('open')
                ->where(['SupportSessions.user_id' => $user->id])
                ->orderBy(['SupportSessions.id' => 'DESC'])
                ->first();
            if ($session !== null) {
                return new Access(
                    new PermissionSet(Permissions::supportGrants()),
                    (int)$session->get('property_id'),
                    'owner',
                    null,
                    false,
                    $session,
                );
            }

            return new Access(Permissions::forRole('owner'), null, 'owner', null, true);
        }

        $locator = TableRegistry::getTableLocator();
        $memberships = $locator->get('PropertyMemberships');
        $membership = $memberships->find('active')
            ->contain(['Roles'])
            ->innerJoinWith('Properties')
            ->where(['PropertyMemberships.user_id' => $user->id])
            ->orderBy(['PropertyMemberships.id' => 'ASC'])
            ->first();
        if ($membership !== null) {
            $roleId = (int)$membership->get('role_id');
            $granted = $locator->get('RolePermissions')->find()
                ->select(['permission'])
                ->where(['role_id' => $roleId])
                ->disableHydration()
                ->all()
                ->extract('permission')
                ->toList();

            return $this->underSubscription(
                $granted,
                (int)$membership->get('property_id'),
                $membership->get('role')?->get('code'),
                (int)$membership->get('id'),
            );
        }

        return Access::none();
    }

    /**
     * A property person's access as their subscription allows (A7): all of
     * their grants while it runs or in grace; in the read-only period, view
     * permissions and Permissions::READ_ONLY_KEEPS, plus the wind-down ones
     * for named actions; once suspended, nothing. Only the stage the
     * configured rollout phase enforces applies (B5).
     *
     * @param list<string> $granted The role's grants.
     */
    private function underSubscription(array $granted, int $propertyId, ?string $roleCode, ?int $membershipId): Access
    {
        $property = TableRegistry::getTableLocator()->get('Properties')->find()
            ->where(['Properties.id' => $propertyId])->first();
        $subscription = $property !== null ? Subscription::of($property) : null;
        $windDown = [];
        $all = $granted;
        if ($subscription?->isSuspended()) {
            $granted = [];
        } elseif ($subscription?->isReadOnly()) {
            $windDown = array_values(array_intersect($granted, Permissions::WIND_DOWN));
            $granted = array_values(array_filter(
                $granted,
                fn($p) => Permissions::isView($p) || in_array($p, Permissions::READ_ONLY_KEEPS, true),
            ));
        }

        return new Access(
            new PermissionSet($granted),
            $propertyId,
            $roleCode,
            $membershipId,
            false,
            null,
            $subscription,
            $windDown,
            array_values(array_diff($all, $granted)),
        );
    }
}
