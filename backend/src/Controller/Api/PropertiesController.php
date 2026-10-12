<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Permissions;
use App\Auth\StaffHierarchy;
use App\Model\Subscription;

/**
 * Hotels & resorts. Owners manage these; admins/receptionists can read their
 * own property.
 */
class PropertiesController extends AppController
{
    /**
     * GET /api/properties
     */
    public function index(): void
    {
        $this->authorize(Permissions::SETTINGS_PROPERTY_VIEW);
        $properties = $this->fetchTable('Properties');
        $query = $properties->find()->orderBy(['Properties.name' => 'ASC']);

        // Non-owners only ever see their own property.
        $bound = $this->boundPropertyId() !== null;
        if ($bound) {
            $query->where(['Properties.id' => $this->boundPropertyId()]);
        }
        $managers = $bound ? [] : $this->managersByProperty();

        // Each subscriber's stage (A7, B5): the Platform list of who is in
        // grace, read-only or suspended, before and after enforcement.
        $list = $query->all()->map(function ($property) use ($bound, $managers) {
            $property->set('subscription', Subscription::of($property)->toArray());
            if (!$bound) {
                // Owner: each subscriber's Manager(s) for the Subscribers page.
                $property->set('users', $managers[(int)$property->id] ?? []);
            }

            return $property;
        })->toList();
        $this->set('properties', $list);
        $this->viewBuilder()->setOption('serialize', ['properties']);
    }

    /**
     * Who manages each property's staff (Phase 3c-2): people whose active
     * membership's role holds staff.account.manage, by permission, never by
     * users.role (empty for a custom-role holder). Oldest membership first.
     *
     * @return array<int, list<array<string, mixed>>> property id => people
     */
    private function managersByProperty(): array
    {
        $managing = array_column(array_filter(
            StaffHierarchy::propertyRoles(),
            fn($r) => in_array(Permissions::STAFF_ACCOUNT_MANAGE, StaffHierarchy::grants($r['id']), true),
        ), 'id');
        if ($managing === []) {
            return [];
        }
        $rows = $this->fetchTable('PropertyMemberships')->find('active')
            ->select([
                'property_id' => 'PropertyMemberships.property_id',
                'id' => 'Users.id', 'name' => 'Users.name', 'email' => 'Users.email', 'is_active' => 'Users.is_active',
            ])
            ->innerJoinWith('Users')
            ->where(['PropertyMemberships.role_id IN' => $managing])
            ->orderBy(['PropertyMemberships.id' => 'ASC'])
            ->disableHydration()->all();
        $byProperty = [];
        foreach ($rows as $r) {
            $byProperty[(int)$r['property_id']][] = [
                'id' => (int)$r['id'],
                'property_id' => (int)$r['property_id'],
                'name' => $r['name'],
                'email' => $r['email'],
                'is_active' => (bool)$r['is_active'],
            ];
        }

        return $byProperty;
    }

    /**
     * POST /api/properties  (owner only)
     */
    public function add(): void
    {
        $this->request->allowMethod('post');
        $this->authorize(Permissions::PLATFORM_PROPERTY_MANAGE, 'Only the Platform Owner can add properties.');

        $properties = $this->fetchTable('Properties');
        $property = $properties->newEntity([
            'name' => $this->request->getData('name'),
            'type' => $this->request->getData('type') ?? 'hotel',
            'address' => $this->request->getData('address'),
            'subscription_fee' => $this->request->getData('subscription_fee') ?? 0,
        ]);

        if (!$properties->save($property, $this->auditOptions())) {
            $this->response = $this->response->withStatus(422);
            $this->set('errors', $property->getErrors());
            $this->viewBuilder()->setOption('serialize', ['errors']);

            return;
        }

        $this->set('property', $property);
        $this->viewBuilder()->setOption('serialize', ['property']);
    }

    /**
     * PATCH|PUT /api/properties/{id}  (owner only)
     *
     * Edit a hotel/resort — including its subscription state (the active /
     * inactive flag the owner reports on).
     */
    public function edit(int $id): void
    {
        $this->request->allowMethod(['patch', 'put']);
        $this->authorize(Permissions::PLATFORM_PROPERTY_MANAGE, 'Only the Platform Owner can edit properties.');

        $properties = $this->fetchTable('Properties');
        $property = $properties->get($id);
        $properties->patchEntity($property, $this->request->getData(), [
            'fields' => ['name', 'type', 'address', 'is_active', 'subscription_status', 'subscription_expires_at', 'subscription_fee'],
        ]);

        if (!$properties->save($property, $this->auditOptions(true))) {
            $this->response = $this->response->withStatus(422);
            $this->set('errors', $property->getErrors());
            $this->viewBuilder()->setOption('serialize', ['errors']);

            return;
        }

        $this->set('property', $property);
        $this->viewBuilder()->setOption('serialize', ['property']);
    }
}
