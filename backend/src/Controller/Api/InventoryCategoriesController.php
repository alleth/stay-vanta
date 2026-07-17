<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;

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

        // Receptionists operate the catalogue; only owners/admins define it.
        if (!$this->userHasRole('owner', 'admin')) {
            throw new ForbiddenException('Only owners and admins may add categories.');
        }

        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        $categories = $this->fetchTable('InventoryCategories');

        // Block duplicate names within the property (case-insensitive) — done
        // in PHP rather than SQL LOWER()/TRIM() for MariaDB/MySQL portability;
        // a property's category list is small so this is cheap.
        $name = trim((string)$this->request->getData('name'));
        $existingNames = $categories->find()
            ->select(['name'])
            ->where(['InventoryCategories.property_id' => $propertyId])
            ->all()
            ->extract('name');
        foreach ($existingNames as $existingName) {
            if (mb_strtolower(trim((string)$existingName)) === mb_strtolower($name)) {
                throw new BadRequestException(sprintf('A category named "%s" already exists.', $name));
            }
        }

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

    /**
     * DELETE /api/inventory-categories/{id}  (owner/admin)
     *
     * Refused if any item still uses the category — move/delete those first.
     * Sub-categories (parent_id) are detached so they don't dangle.
     */
    public function delete(int $id): void
    {
        $this->request->allowMethod(['delete', 'post']);

        if (!$this->userHasRole('owner', 'admin')) {
            throw new ForbiddenException('Only owners and admins may delete categories.');
        }

        $categories = $this->fetchTable('InventoryCategories');
        $category = $this->scopeToProperty($categories->find()->where(['InventoryCategories.id' => $id]))
            ->firstOrFail();

        $items = $this->fetchTable('InventoryItems');
        $inUse = $items->find()->where(['InventoryItems.inventory_category_id' => $id])->count() > 0;
        if ($inUse) {
            throw new BadRequestException('This category still has items. Move or delete them first.');
        }

        $categories->getConnection()->transactional(function () use ($categories, $category, $id): void {
            $categories->updateAll(['parent_id' => null], ['parent_id' => $id]);
            $categories->deleteOrFail($category);
        });

        $this->set('ok', true);
        $this->viewBuilder()->setOption('serialize', ['ok']);
    }
}
