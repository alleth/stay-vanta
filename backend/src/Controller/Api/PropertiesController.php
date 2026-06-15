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
