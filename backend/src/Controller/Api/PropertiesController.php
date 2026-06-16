<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Http\Exception\ForbiddenException;

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
        $properties = $this->fetchTable('Properties');
        $query = $properties->find()->orderBy(['Properties.name' => 'ASC']);

        // Non-owners only ever see their own property.
        if ($this->currentUser->property_id !== null) {
            $query->where(['Properties.id' => $this->currentUser->property_id]);
        } else {
            // Owner: include each subscriber's admin(s) for the Subscribers page.
            $query->contain(['Users' => function ($q) {
                return $q->where(['Users.role' => 'admin'])
                    ->select(['Users.id', 'Users.property_id', 'Users.name', 'Users.email', 'Users.is_active']);
            }]);
        }

        $this->set('properties', $query->all());
        $this->viewBuilder()->setOption('serialize', ['properties']);
    }

    /**
     * POST /api/properties  (owner only)
     */
    public function add(): void
    {
        $this->request->allowMethod('post');
        if (!$this->userHasRole('owner')) {
            throw new ForbiddenException('Only the platform owner can add properties.');
        }

        $properties = $this->fetchTable('Properties');
        $property = $properties->newEntity([
            'name' => $this->request->getData('name'),
            'type' => $this->request->getData('type') ?? 'hotel',
            'address' => $this->request->getData('address'),
            'subscription_fee' => $this->request->getData('subscription_fee') ?? 0,
        ]);

        if (!$properties->save($property)) {
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
        if (!$this->userHasRole('owner')) {
            throw new ForbiddenException('Only the platform owner can edit properties.');
        }

        $properties = $this->fetchTable('Properties');
        $property = $properties->get($id);
        $properties->patchEntity($property, $this->request->getData(), [
            'fields' => ['name', 'type', 'address', 'is_active', 'subscription_status', 'subscription_expires_at', 'subscription_fee'],
        ]);

        if (!$properties->save($property)) {
            $this->response = $this->response->withStatus(422);
            $this->set('errors', $property->getErrors());
            $this->viewBuilder()->setOption('serialize', ['errors']);

            return;
        }

        $this->set('property', $property);
        $this->viewBuilder()->setOption('serialize', ['property']);
    }
}
