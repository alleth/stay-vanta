<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Http\Exception\BadRequestException;

/**
 * Inventory categories (Food Stocks, Hygiene Kit, Linens, Utensils, ...).
 */
class InventoryCategoriesController extends AppController
{
    /**
     * GET /api/inventory-categories
     */
    public function index(): void
    {
        $categories = $this->fetchTable('InventoryCategories');
        $query = $this->scopeToProperty(
            $categories->find()->contain(['ParentCategories'])->orderBy(['InventoryCategories.name' => 'ASC'])
        );

        $this->set('categories', $query->all());
        $this->viewBuilder()->setOption('serialize', ['categories']);
    }

    /**
     * POST /api/inventory-categories
     */
    public function add(): void
    {
        $this->request->allowMethod('post');

        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        $categories = $this->fetchTable('InventoryCategories');
        $category = $categories->newEntity([
            'property_id' => $propertyId,
            'name' => $this->request->getData('name'),
            'kind' => $this->request->getData('kind') ?? 'other',
            'parent_id' => $this->request->getData('parent_id'),
        ]);

        if (!$categories->save($category)) {
            $this->response = $this->response->withStatus(422);
            $this->set('errors', $category->getErrors());
            $this->viewBuilder()->setOption('serialize', ['errors']);

            return;
        }

        $this->set('category', $category);
        $this->viewBuilder()->setOption('serialize', ['category']);
    }
}
