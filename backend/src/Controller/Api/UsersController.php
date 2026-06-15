<?php
declare(strict_types=1);

namespace App\Controller\Api;

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
 */
class UsersController extends AppController
{
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        // Auth has run in the parent; now gate the whole controller by role.
        if (!$this->userHasRole('owner', 'admin')) {
            throw new ForbiddenException('Staff management is restricted to owners and admins.');
        }
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
                ->orderBy(['Users.role' => 'ASC', 'Users.name' => 'ASC'])
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
            $data['is_active'] = (bool)$this->request->getData('is_active');
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
     */
    public function resetPassword(int $id): void
    {
        $this->request->allowMethod('post');
        $users = $this->fetchTable('Users');
        $user = $this->findManageable($id);

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
                throw new ForbiddenException('Admins may only create receptionist accounts.');
            }

            return (int)$this->currentUser->property_id;
        }

        // Owner.
        if (!in_array($role, ['admin', 'receptionist'], true)) {
            throw new ForbiddenException('Owners may create admin or receptionist accounts.');
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
    private function findManageable(int $id): \App\Model\Entity\User
    {
        $users = $this->fetchTable('Users');
        $query = $users->find()
            ->where(['Users.id' => $id, 'Users.role IN' => ['admin', 'receptionist']]);

        // Admins are confined to their own property.
        if ($this->currentUser->property_id !== null) {
            $query->where(['Users.property_id' => $this->currentUser->property_id]);
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
