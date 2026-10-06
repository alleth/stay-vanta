<?php
declare(strict_types=1);

namespace App\Auth;

use App\Model\Entity\User;
use App\Model\Table\RolesTable;
use Cake\Log\Log;
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
 * Fallback for one release (CLAUDE.md, Permission strategy): an account that
 * has never had a membership (made outside the API and the import) is read
 * from `users.role` / `users.property_id`, and every use is logged, so the
 * fallback can be removed once those lines stop appearing. An account whose
 * membership was ended gets nothing.
 */
final class AccessResolver
{
    /**
     * @param \App\Model\Entity\User $user The signed-in person.
     */
    public function resolve(User $user): Access
    {
        if ($user->get('is_platform') || ($user->role === 'owner' && $user->property_id === null)) {
            if (!$user->get('is_platform')) {
                Log::warning(sprintf('platform flag fallback used for user %d (role owner, no flag)', (int)$user->id));
            }

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

            return new Access(
                new PermissionSet($granted),
                (int)$membership->get('property_id'),
                $membership->get('role')?->get('code'),
                (int)$membership->get('id'),
                false,
            );
        }

        $everHad = $memberships->exists(['user_id' => $user->id]);
        if (
            !$everHad
            && $user->property_id !== null
            && array_key_exists((string)$user->role, RolesTable::PRESETS)
        ) {
            Log::warning(sprintf('membership fallback used for user %d (no membership on record)', (int)$user->id));

            return new Access(Permissions::forRole($user->role), (int)$user->property_id, $user->role, null, false);
        }

        return Access::none();
    }
}
