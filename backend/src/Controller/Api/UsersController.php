<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Permissions;
use App\Model\Entity\User;
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

        $permission = $this->request->getParam('action') === 'index'
            ? Permissions::STAFF_ACCOUNT_VIEW
            : Permissions::STAFF_ACCOUNT_MANAGE;
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

        if (!$users->save($user)) {
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
        $user = $this->findManageable($id);

        $data = [];
        if ($this->request->getData('name') !== null) {
            $data['name'] = $this->request->getData('name');
        }
        if ($this->request->getData('is_active') !== null) {
            $isActive = (bool)$this->request->getData('is_active');
            // You can't lock yourself out by deactivating your own account.
            if (!$isActive && $user->id === (int)$this->currentUser->id) {
                throw new ForbiddenException('You cannot deactivate your own account.');
            }
            $data['is_active'] = $isActive;
        }
        $users->patchEntity($user, $data);

        if (!$users->save($user)) {
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

        $user->set('password', $password);
        $user->set('api_token', null);
        $user->set('token_expires', null);
        $users->saveOrFail($user);

        $this->set('ok', true);
        $this->viewBuilder()->setOption('serialize', ['ok']);
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
