<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Permissions;
use App\Model\Entity\User;
use App\Model\Table\AccessEventsTable;
use Cake\Event\EventInterface;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;

/**
 * Staff management.
 *
 * Role rules:
 * - owner  : may create/manage `admin` and `receptionist` users for any property.
 * - admin  : may create/manage `receptionist` users for their OWN property only.
 * - others : no access.
 *
 * Owners are never created or edited through this controller.
 *
 * Every change is recorded in access_events (build step 10) under a lock on
 * the user row, in the same transaction: created, renamed, deactivated and
 * reactivated (with a reason), password reset (someone else's, with a
 * reason) or changed (your own, with your current password). Deactivating
 * and setting a password end the person's session at once (A8), so
 * reactivating an account never brings an old session back (F1).
 *
 * Access is staff.account.view / staff.account.manage (beforeFilter). Who may
 * manage whom stays a role rule, deliberately not a permission (see "Rules
 * that stay outside permissions" in docs/PERMISSIONS.md); these are the only
 * userHasRole() calls left.
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
     */
    public function index(): void
    {
        $users = $this->fetchTable('Users');
        $query = $this->scopeToProperty(
            $users->find()
                ->where(['Users.role IN' => ['admin', 'receptionist']])
                ->contain(['Properties'])
                ->orderBy(['Users.role' => 'ASC', 'Users.name' => 'ASC']),
        );

        $this->set('users', $query->all());
        $this->viewBuilder()->setOption('serialize', ['users']);
    }

    /**
     * POST /api/users — create a staff member.
     * { name, email, password, role, property_id? }
     */
    public function add(): void
    {
        $this->request->allowMethod('post');

        $role = (string)$this->request->getData('role');
        $propertyId = $this->resolveTargetProperty($role);

        $users = $this->fetchTable('Users');
        $user = $users->newEntity([
            'name' => $this->request->getData('name'),
            'email' => $this->request->getData('email'),
            'password' => $this->request->getData('password'),
            'role' => $role,
            'property_id' => $propertyId,
            'is_active' => true,
        ]);

        $saved = $users->getConnection()->transactional(function () use ($users, $user): bool {
            if (!$users->save($user, ['atomic' => false])) {
                return false;
            }
            $this->recordAccess($this->eventContext(), AccessEventsTable::ACCOUNT_CREATED, $user, [
                'changes' => ['after' => $this->accountValues($user)],
            ]);

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
        $this->findManageable($id);
        $name = $this->request->getData('name');
        $active = $this->request->getData('is_active');

        $user = $users->getConnection()->transactional(function () use ($users, $id, $name, $active): User {
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
            if ($events === []) {
                throw new BadRequestException('Nothing to change.');
            }
            $users->patchEntity($user, $data);
            $sessionOpen = false;
            if (($data['is_active'] ?? null) === false) {
                // A8: a switched-off account's session ends now, so switching
                // it back on can't revive it (F1).
                $sessionOpen = $this->endSession($user);
            }
            if (!$users->save($user, ['atomic' => false])) {
                return $user;
            }
            foreach ($events as [$type, $changes]) {
                $this->recordAccess($this->eventContext(), $type, $user, ['changes' => $changes]);
            }
            if ($sessionOpen) {
                $this->recordAccess($this->eventContext(), AccessEventsTable::SESSION_ENDED, $user, [
                    'changes' => ['because' => AccessEventsTable::ACCOUNT_DEACTIVATED],
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

        if (
            $this->userHasRole('admin')
            && $user->id !== (int)$this->currentUser->id
            && $user->role !== 'receptionist'
        ) {
            throw new ForbiddenException(
                'Managers can only change their own password or reset Front Desk Staff passwords.',
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
        $this->respondWithAccessHistory((int)$this->findManageable($id)->id);
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
     * An account's values for its creation event: never the password or the
     * email address (contact details stay out of ledgers).
     *
     * @return array<string, mixed>
     */
    private function accountValues(User $user): array
    {
        return [
            'name' => $user->name,
            'role' => $user->role,
            'property_id' => $user->property_id !== null ? (int)$user->property_id : null,
            'is_active' => (bool)$user->is_active,
        ];
    }

    /**
     * Determine and authorise the property a new user belongs to.
     */
    private function resolveTargetProperty(string $role): int
    {
        if ($this->userHasRole('admin')) {
            // Admins can only create receptionists, in their own property.
            if ($role !== 'receptionist') {
                throw new ForbiddenException('Managers can only create Front Desk Staff accounts.');
            }

            return (int)$this->boundPropertyId();
        }

        // Owner.
        if (!in_array($role, ['admin', 'receptionist'], true)) {
            throw new ForbiddenException('The Platform Owner can create Manager or Front Desk Staff accounts.');
        }
        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        return $propertyId;
    }

    /**
     * Load a staff user the current actor is allowed to manage, or 404.
     */
    private function findManageable(int $id): User
    {
        $users = $this->fetchTable('Users');
        $query = $users->find()
            ->where(['Users.id' => $id, 'Users.role IN' => ['admin', 'receptionist']]);

        // Admins are confined to their own property.
        if ($this->boundPropertyId() !== null) {
            $query->where(['Users.property_id' => $this->boundPropertyId()]);
        }

        return $query->firstOrFail();
    }

    private function validationFailed(array $errors): void
    {
        $this->response = $this->response->withStatus(422);
        $this->set('errors', $errors);
        $this->viewBuilder()->setOption('serialize', ['errors']);
    }
}
