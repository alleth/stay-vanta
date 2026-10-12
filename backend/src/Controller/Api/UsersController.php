<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Permissions;
use App\Auth\StaffHierarchy;
use App\Model\Entity\User;
use App\Model\Table\AccessEventsTable;
use Cake\Event\EventInterface;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;

/**
 * Staff management.
 *
 * Who may manage whom (Permissions Phase 3a, PH-D3): App\Auth\StaffHierarchy,
 * by permissions, never role codes. You manage someone only if their role's
 * permissions are a strict subset of your own role's (a Manager manages Front
 * Desk Staff, never another Manager), at your own property; the Platform Owner
 * manages every property's staff. Your own account: rename, change your
 * password, see your history. Platform Owners are never created or edited
 * through this controller.
 *
 * Every change is recorded in access_events (build step 10) under a lock on
 * the user row, in the same transaction: created, renamed, deactivated and
 * reactivated (with a reason), password reset (someone else's, with a
 * reason) or changed (your own, with your current password). Deactivating
 * and setting a password end the person's session at once (A8), so
 * reactivating an account never brings an old session back (F1).
 *
 * Access is staff.account.view / staff.account.manage (beforeFilter); who may
 * manage whom is the hierarchy above (docs/PERMISSIONS.md, "Rules that stay
 * outside permissions").
 */
class UsersController extends AppController
{
    /**
     * Gate the whole controller here, before allowMethod(), as it always has
     * been: listing staff needs staff.account.view, everything else
     * staff.account.manage. Who may manage whom is a separate rule below.
     *
     * @param \Cake\Event\EventInterface $event The beforeFilter event.
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        $permission = match ($this->request->getParam('action')) {
            'index' => Permissions::STAFF_ACCOUNT_VIEW,
            'accessHistory' => Permissions::STAFF_ACCESS_HISTORY_VIEW,
            default => Permissions::STAFF_ACCOUNT_MANAGE,
        };
        $this->authorize($permission, 'Only Managers can manage staff.');
    }

    /**
     * GET /api/users  — staff list, property-scoped.
     *
     * → { users: [{…, role, role_name, manageable}], assignable_roles: [{code, name}] }
     * Each person's role is their membership's (Phase 3a); `manageable` says
     * whether you may change, reset or see the history of that account
     * (StaffHierarchy), and `assignable_roles` which roles you may give a new
     * account. Screens offer exactly these; the server checks them again.
     */
    public function index(): void
    {
        $users = $this->fetchTable('Users')->find()
            ->where(['Users.is_platform' => false])
            ->contain(['Properties'])
            ->orderBy(['Users.name' => 'ASC']);
        $list = $this->scopeToProperty($users)->all()->toList();
        $roles = StaffHierarchy::rolesOf(array_map(fn($u) => (int)$u->id, $list), $this->boundPropertyId());
        $staff = [];
        foreach ($list as $user) {
            $role = $roles[(int)$user->id] ?? null;
            if ($role === null) {
                // An ended membership: no role here, not on the staff list.
                continue;
            }
            $user->set('role', $role['code']);
            $user->set('role_id', $role['id']);
            $user->set('role_name', $role['name']);
            $user->set('manageable', StaffHierarchy::manages($this->access(), $role['id']));
            $staff[] = $user;
        }
        // Higher roles first (more permissions), then by name.
        $weight = [];
        foreach (array_unique(array_map(fn($u) => $u->get('role_id'), $staff)) as $roleId) {
            $weight[$roleId] = count(StaffHierarchy::grants($roleId));
        }
        usort($staff, fn($a, $b) => [$weight[$b->get('role_id')] ?? 0, $a->name]
            <=> [$weight[$a->get('role_id')] ?? 0, $b->name]);

        $this->set('users', $staff);
        // What the role picker shows (Phase 3c-2): built-ins first, then
        // custom roles (the Platform Owner's alone), with what each grants and
        // how many people hold it.
        $assignable = StaffHierarchy::assignable($this->access());
        $held = $this->holderCounts(array_column($assignable, 'id'));
        $this->set('assignable_roles', array_map(fn($r) => [
            'id' => $r['id'],
            'code' => $r['code'],
            'name' => $r['name'],
            'kind' => $r['kind'],
            'description' => $r['description'],
            'permission_count' => count(StaffHierarchy::grants($r['id'])),
            'holders' => $held[$r['id']] ?? 0,
        ], $assignable));
        $this->viewBuilder()->setOption('serialize', ['users', 'assignable_roles']);
    }

    /**
     * POST /api/users — create a staff member.
     * { name, email, password, role_id (or, for one release, role: a code), property_id? }
     *
     * A custom role only from the Platform Owner (Phase 3c-2, C-D1).
     */
    public function add(): void
    {
        $this->request->allowMethod('post');
        // Read-only keeps account security (B2), not new accounts.
        $this->refuseWhenReadOnly();

        $role = $this->requestedRole();
        $propertyId = $this->resolveTargetProperty($role);

        $users = $this->fetchTable('Users');
        $user = $users->newEntity([
            'name' => $this->request->getData('name'),
            'email' => $this->request->getData('email'),
            'password' => $this->request->getData('password'),
            // Legacy column (C-D2): a built-in's code, else empty.
            'role' => $role['kind'] === 'system' ? $role['code'] : null,
            'property_id' => $propertyId,
            'is_active' => true,
        ]);

        $saved = $users->getConnection()->transactional(function () use ($users, $user, $role, $propertyId): bool {
            // The role as it stands now, locked: an archive can't slip in between.
            $role = $this->lockAssignableRole($role['id']);
            if (!$users->save($user, ['atomic' => false])) {
                return false;
            }
            $this->recordAccess($this->eventContext(), AccessEventsTable::ACCOUNT_CREATED, $user, [
                'changes' => ['after' => $this->accountValues($user, $role)],
            ]);
            $this->grantMembership($user, $role, $propertyId);

            return true;
        });
        if (!$saved) {
            $this->validationFailed($user->getErrors());

            return;
        }

        $this->response = $this->response->withStatus(201);
        $this->set('user', $user);
        $this->viewBuilder()->setOption('serialize', ['user']);
    }

    /**
     * PATCH /api/users/{id} — rename and/or activate/deactivate.
     */
    public function edit(int $id): void
    {
        $this->request->allowMethod(['patch', 'put', 'post']);
        $users = $this->fetchTable('Users');
        $target = $this->findManageable($id);
        if ((int)$target->id !== (int)$this->currentUser->id) {
            $this->requireManages($target, 'You can only change accounts whose role is below your own.');
        }
        $name = $this->request->getData('name');
        $active = $this->request->getData('is_active');
        $wantsRole = $this->request->getData('role_id') !== null || $this->request->getData('role') !== null;
        // Changing a role is the Platform Owner's alone (step 10b): a Manager
        // never grants or removes the Manager role.
        if ($wantsRole && !$this->access()->platform) {
            throw new ForbiddenException('Only the Platform Owner can change a role.');
        }
        $role = $wantsRole ? $this->requestedRole() : null;
        if ($role !== null && !StaffHierarchy::mayAssign($this->access(), $role)) {
            throw new ForbiddenException('Only the Platform Owner can give a custom role.');
        }

        $user = $users->getConnection()->transactional(function () use ($users, $id, $name, $active, $role): User {
            // Lock, then check against the locked row (a second identical
            // request finds nothing to change and records nothing).
            $user = $this->lockUser($id);
            $events = [];
            $data = [];
            if ($name !== null && (string)$name !== $user->name) {
                $data['name'] = (string)$name;
                $events[] = [
                    AccessEventsTable::ACCOUNT_RENAMED,
                    ['name' => ['before' => $user->name, 'after' => (string)$name]],
                ];
            }
            if ($active !== null && (bool)$active !== (bool)$user->is_active) {
                $isActive = (bool)$active;
                // You can't lock yourself out by deactivating your own account.
                if (!$isActive && $user->id === (int)$this->currentUser->id) {
                    throw new ForbiddenException('You cannot deactivate your own account.');
                }
                $data['is_active'] = $isActive;
                $events[] = [
                    $isActive ? AccessEventsTable::ACCOUNT_REACTIVATED : AccessEventsTable::ACCOUNT_DEACTIVATED,
                    ['is_active' => ['before' => !$isActive, 'after' => $isActive]],
                ];
            }
            // Compared with the role the person holds (their membership's),
            // never users.role, which a custom-role holder doesn't have (3c-2).
            $roleChange = $role !== null ? $this->roleChangeFor($user, $role['id']) : null;
            if ($roleChange !== null) {
                // Legacy column (C-D2): a built-in's code, else empty.
                $data['role'] = $roleChange['after']['kind'] === 'system' ? $roleChange['after']['code'] : null;
            }
            if ($events === [] && $roleChange === null) {
                throw new BadRequestException('Nothing to change.');
            }
            $users->patchEntity($user, $data);
            $sessionOpen = false;
            $endedBecause = null;
            if (($data['is_active'] ?? null) === false) {
                // A8: a switched-off account's session ends now, so switching
                // it back on can't revive it (F1).
                $sessionOpen = $this->endSession($user);
                $endedBecause = AccessEventsTable::ACCOUNT_DEACTIVATED;
            } elseif ($roleChange !== null) {
                // A8: a new role means new permissions; sign in again.
                $sessionOpen = $this->endSession($user);
                $endedBecause = AccessEventsTable::MEMBERSHIP_ROLE_CHANGED;
            }
            if (!$users->save($user, ['atomic' => false])) {
                return $user;
            }
            foreach ($events as [$type, $changes]) {
                $this->recordAccess($this->eventContext(), $type, $user, ['changes' => $changes]);
            }
            if ($roleChange !== null) {
                // C-D7: the membership is a timeline. The current one ends and
                // a new one starts at the same moment; neither is edited again.
                $memberships = $this->fetchTable('PropertyMemberships');
                $old = $roleChange['membership'];
                $now = $this->eventContext()->now;
                $old->set('ended_at', $now);
                $memberships->saveOrFail($old, ['atomic' => false]);
                $new = $memberships->saveOrFail($memberships->newEntity([
                    'user_id' => (int)$user->id,
                    'property_id' => (int)$old->get('property_id'),
                    'role_id' => $roleChange['after']['id'],
                    'started_at' => $now,
                ], ['accessibleFields' => ['*' => true]]), ['atomic' => false]);
                $this->recordAccess($this->eventContext(), AccessEventsTable::MEMBERSHIP_ROLE_CHANGED, $user, [
                    'changes' => [
                        'role' => [
                            'before' => $this->roleValues($roleChange['before']),
                            'after' => $this->roleValues($roleChange['after']),
                        ],
                        'membership' => ['before' => (int)$old->id, 'after' => (int)$new->id],
                    ],
                    'columns' => ['membership_id' => (int)$new->id, 'role_id' => $roleChange['after']['id']],
                ]);
            }
            if ($sessionOpen) {
                $this->recordAccess($this->eventContext(), AccessEventsTable::SESSION_ENDED, $user, [
                    'changes' => ['because' => $endedBecause],
                ]);
            }

            return $user;
        });
        if ($user->getErrors()) {
            $this->validationFailed($user->getErrors());

            return;
        }

        $this->set('user', $user);
        $this->viewBuilder()->setOption('serialize', ['user']);
    }

    /**
     * POST /api/users/{id}/reset-password — set a new password and revoke
     * any active token so the user must sign in again.
     *
     * Owners may reset anyone they manage. Admins may change their OWN password
     * and reset their receptionists' passwords, but not reset a peer admin's.
     */
    public function resetPassword(int $id): void
    {
        $this->request->allowMethod('post');
        $users = $this->fetchTable('Users');
        $user = $this->findManageable($id);

        if ($user->id !== (int)$this->currentUser->id) {
            $this->requireManages(
                $user,
                'You can only change your own password or reset passwords of staff whose role is below your own.',
            );
        }

        $password = (string)$this->request->getData('password');
        if (strlen($password) < 8) {
            throw new BadRequestException('Password must be at least 8 characters.');
        }
        // Your own password: prove it's you first (A10). Someone else's: a
        // reset, which needs a reason (A5).
        $own = $user->id === (int)$this->currentUser->id;
        if ($own && !$user->verifyPassword((string)$this->request->getData('current_password'))) {
            throw new BadRequestException('Your current password is not correct.');
        }

        $users->getConnection()->transactional(function () use ($users, $id, $password, $own): void {
            $user = $this->lockUser($id);
            $user->set('password', $password);
            // A8: setting a password ends the session; sign in again with it.
            $sessionOpen = $this->endSession($user);
            $users->saveOrFail($user, ['atomic' => false]);
            $type = $own ? AccessEventsTable::PASSWORD_CHANGED : AccessEventsTable::PASSWORD_RESET;
            $this->recordAccess($this->eventContext(), $type, $user);
            if ($sessionOpen) {
                $this->recordAccess($this->eventContext(), AccessEventsTable::SESSION_ENDED, $user, [
                    'changes' => ['because' => $type],
                ]);
            }
        });

        $this->set('ok', true);
        $this->viewBuilder()->setOption('serialize', ['ok']);
    }

    /**
     * GET /api/users/{id}/access-history[?page=] → {events, page, has_more}
     *
     * A staff member's access history (step 10): their account changes, who
     * made them and why, their sign-ins with device, failed attempts and
     * lockouts. A Manager's, for the staff they manage (staff.access_history.view).
     */
    public function accessHistory(int $id): void
    {
        $this->request->allowMethod('get');
        $user = $this->findManageable($id);
        if ((int)$user->id !== (int)$this->currentUser->id) {
            $this->requireManages($user, 'You can only see the history of staff whose role is below your own.');
        }
        $this->respondWithAccessHistory((int)$user->id);
    }

    /**
     * Re-read a user inside the transaction with a FOR UPDATE lock.
     */
    private function lockUser(int $id): User
    {
        /** @var \App\Model\Entity\User $user */
        $user = $this->fetchTable('Users')->find()->where(['Users.id' => $id])->epilog('FOR UPDATE')->firstOrFail();

        return $user;
    }

    /**
     * End a person's session (revoke their token). True when one was open.
     */
    private function endSession(User $user): bool
    {
        $open = $user->api_token !== null && ($user->token_expires === null || !$user->token_expires->isPast());
        $user->set('api_token', null);
        $user->set('token_expires', null);

        return $open;
    }

    /**
     * What changing a person's role takes (step 10b; Phase 3c-2): their
     * active membership and the new role, both locked, and the role held
     * before. Null when they already hold that role (nothing to change,
     * nothing recorded). The Platform Owner's change, with a reason (the
     * event refuses one without).
     *
     * A property's last active Manager may be demoted only this way: Managers
     * can't change roles or deactivate themselves, so they can never remove
     * the last Manager (A10).
     *
     * @return array{membership: \Cake\Datasource\EntityInterface, before: array<string, mixed>, after: array<string, mixed>}|null
     */
    private function roleChangeFor(User $user, int $roleId): ?array
    {
        $membership = $this->fetchTable('PropertyMemberships')->find('active')
            ->where(['PropertyMemberships.user_id' => $user->id])
            ->orderBy(['PropertyMemberships.id' => 'ASC'])
            ->epilog('FOR UPDATE')
            ->first();
        if ($membership === null) {
            throw new BadRequestException('This person has no active membership whose role could change.');
        }
        if ((int)$membership->get('role_id') === $roleId) {
            return null;
        }
        $after = $this->lockAssignableRole($roleId);
        $before = $this->fetchTable('Roles')->find()
            ->select(['id', 'code', 'name', 'kind'])->where(['id' => (int)$membership->get('role_id')])
            ->disableHydration()->firstOrFail();

        return ['membership' => $membership, 'before' => $before, 'after' => $after];
    }

    /**
     * The role a request names: `role_id`, or for one release `role` (a code,
     * C2-D1), among the roles a person can hold at a property.
     *
     * @return array{id: int, code: string, name: string, kind: string, description: string|null}
     */
    private function requestedRole(): array
    {
        $id = $this->request->getData('role_id');
        $role = $id !== null && $id !== ''
            ? StaffHierarchy::propertyRole((int)$id)
            : StaffHierarchy::propertyRole((string)$this->request->getData('role'));
        if ($role === null) {
            throw new BadRequestException('Choose a role a person can hold at a property.');
        }

        return $role;
    }

    /**
     * Re-read a role with FOR UPDATE inside the transaction and check it can
     * still be given (Phase 3c-2): property-scoped, assignable, not archived.
     * RoleDefinitions::archive() takes the same lock, so an archive and an
     * assignment can't both pass.
     *
     * @return array{id: int, code: string, name: string, kind: string}
     */
    private function lockAssignableRole(int $roleId): array
    {
        $role = $this->fetchTable('Roles')->find()
            ->select(['id', 'code', 'name', 'kind', 'scope', 'archived_at'])
            ->where(['id' => $roleId])->epilog('FOR UPDATE')->disableHydration()->first();
        if (
            $role === null || $role['scope'] !== 'property' || $role['archived_at'] !== null
            || !in_array($role['kind'], StaffHierarchy::ASSIGNABLE_KINDS, true)
        ) {
            throw new BadRequestException('This role can no longer be given. Choose another.');
        }

        return ['id' => (int)$role['id'], 'code' => $role['code'], 'name' => $role['name'], 'kind' => $role['kind']];
    }

    /**
     * A role as an event records it: id, code and name at that moment.
     *
     * @param array<string, mixed> $role
     * @return array{id: int, code: string, name: string}
     */
    private function roleValues(array $role): array
    {
        return ['id' => (int)$role['id'], 'code' => (string)$role['code'], 'name' => (string)$role['name']];
    }

    /**
     * Active holders per role: role id => count.
     *
     * @param list<int> $roleIds
     * @return array<int, int>
     */
    private function holderCounts(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }
        $counts = [];
        foreach ($roleIds as $roleId) {
            $counts[$roleId] = $this->fetchTable('PropertyMemberships')->find('active')
                ->where(['PropertyMemberships.role_id' => $roleId])->count();
        }

        return $counts;
    }

    /**
     * Give a new account its role at its property (step 10b): the membership
     * its access is read from, recorded as `membership_granted` in the
     * caller's transaction. `users.role` / `users.property_id` are still
     * written (a built-in's code; empty for a custom role, C-D2) until 3d.
     *
     * @param array{id: int, code: string, name: string} $role The locked role.
     */
    private function grantMembership(User $user, array $role, int $propertyId): void
    {
        $memberships = $this->fetchTable('PropertyMemberships');
        $membership = $memberships->saveOrFail($memberships->newEntity([
            'user_id' => (int)$user->id,
            'property_id' => $propertyId,
            'role_id' => $role['id'],
            'started_at' => $this->eventContext()->now,
        ], ['accessibleFields' => ['*' => true]]), ['atomic' => false]);
        $this->recordAccess($this->eventContext(), AccessEventsTable::MEMBERSHIP_GRANTED, $user, [
            'changes' => ['after' => ['property_id' => $propertyId, 'role' => $this->roleValues($role)]],
            'columns' => ['membership_id' => (int)$membership->id, 'role_id' => $role['id']],
        ]);
    }

    /**
     * An account's values for its creation event: never the password or the
     * email address (contact details stay out of ledgers).
     *
     * @param array{id: int, code: string, name: string} $role The role it starts with.
     * @return array<string, mixed>
     */
    private function accountValues(User $user, array $role): array
    {
        return [
            'name' => $user->name,
            'role' => $this->roleValues($role),
            'property_id' => $user->property_id !== null ? (int)$user->property_id : null,
            'is_active' => (bool)$user->is_active,
        ];
    }

    /**
     * Determine and authorise the property a new user belongs to: a role you
     * may give (StaffHierarchy::mayAssign: a built-in below your own; the
     * Platform Owner, any property role, custom ones included), at your own
     * property, or the chosen one for the Platform Owner.
     *
     * @param array{id: int, kind: string} $role The requested role.
     */
    private function resolveTargetProperty(array $role): int
    {
        if (!StaffHierarchy::mayAssign($this->access(), $role)) {
            throw new ForbiddenException(match (true) {
                $role['kind'] === 'custom' => 'Only the Platform Owner can give a custom role.',
                $this->access()->platform => 'Choose a role a person can hold at a property.',
                default => 'You can only create accounts for roles with fewer permissions than your own.',
            });
        }
        if (!$this->access()->platform) {
            return (int)$this->boundPropertyId();
        }
        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        return $propertyId;
    }

    /**
     * Load a staff account at your property (the Platform Owner: any), or
     * 404. Whether you may act on it is requireManages().
     */
    private function findManageable(int $id): User
    {
        $users = $this->fetchTable('Users');
        $query = $users->find()->where(['Users.id' => $id, 'Users.is_platform' => false]);

        // Property staff are confined to their own property.
        if ($this->boundPropertyId() !== null) {
            $query->where(['Users.property_id' => $this->boundPropertyId()]);
        }

        return $query->firstOrFail();
    }

    /**
     * Refuse acting on someone else's account unless you manage their role
     * (StaffHierarchy): never a peer, never someone above you. Your own
     * account is the caller's to allow.
     */
    private function requireManages(User $user, string $message): void
    {
        $role = StaffHierarchy::rolesOf([(int)$user->id])[(int)$user->id] ?? null;
        if (!StaffHierarchy::manages($this->access(), $role['id'] ?? null)) {
            throw new ForbiddenException($message);
        }
    }

    private function validationFailed(array $errors): void
    {
        $this->response = $this->response->withStatus(422);
        $this->set('errors', $errors);
        $this->viewBuilder()->setOption('serialize', ['errors']);
    }
}
