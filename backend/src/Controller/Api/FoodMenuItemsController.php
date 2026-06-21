<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;

/**
 * Food menu. Admins/owners manage items & prices; receptionists read them when
 * taking orders.
 */
class FoodMenuItemsController extends AppController
{
    /**
     * GET /api/food-menu-items[?available=1]
     */
    public function index(): void
    {
        $menu = $this->fetchTable('FoodMenuItems');
        $query = $this->scopeToProperty(
            $menu->find()
                ->where(['FoodMenuItems.deleted_at IS' => null])
                ->contain(['InventoryItems' => ['InventoryCategories']])
                ->orderBy(['FoodMenuItems.name' => 'ASC'])
        );

        if ($this->request->getQuery('available')) {
            $query->where(['FoodMenuItems.is_available' => true]);
        }

        $this->set('menuItems', $query->all());
        $this->viewBuilder()->setOption('serialize', ['menuItems']);
    }

    /**
     * POST /api/food-menu-items  (owner/admin)
     */
    public function add(): void
    {
        $this->request->allowMethod('post');
        $this->requireManager();

        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        $menu = $this->fetchTable('FoodMenuItems');
        $item = $menu->newEntity([
            'property_id' => $propertyId,
            'name' => $this->request->getData('name'),
            'price' => $this->request->getData('price'),
            'inventory_item_id' => $this->request->getData('inventory_item_id'),
            'is_available' => $this->request->getData('is_available') ?? true,
        ]);

        if (!$menu->save($item)) {
            $this->validationFailed($item->getErrors());

            return;
        }

        $this->response = $this->response->withStatus(201);
        $this->set('menuItem', $item);
        $this->viewBuilder()->setOption('serialize', ['menuItem']);
    }

    /**
     * PATCH /api/food-menu-items/{id}  (owner/admin)
     */
    public function edit(int $id): void
    {
        $this->request->allowMethod(['patch', 'put', 'post']);
        $this->requireManager();

        $menu = $this->fetchTable('FoodMenuItems');
        $item = $this->scopeToProperty(
            $menu->find()->where(['FoodMenuItems.id' => $id, 'FoodMenuItems.deleted_at IS' => null])
        )->firstOrFail();

        $menu->patchEntity($item, [
            'name' => $this->request->getData('name'),
            'price' => $this->request->getData('price'),
            'inventory_item_id' => $this->request->getData('inventory_item_id'),
            'is_available' => $this->request->getData('is_available'),
        ], ['accessibleFields' => ['property_id' => false]]);

        if (!$menu->save($item)) {
            $this->validationFailed($item->getErrors());

            return;
        }

        $this->set('menuItem', $item);
        $this->viewBuilder()->setOption('serialize', ['menuItem']);
    }

    /**
     * DELETE /api/food-menu-items/{id}  (owner/admin)
     *
     * Soft-delete: the item is hidden from the menu and from new orders, but the
     * row remains so past orders keep their reference (order history is intact).
     */
    public function delete(int $id): void
    {
        $this->request->allowMethod(['delete', 'post']);
        $this->requireManager();

        $menu = $this->fetchTable('FoodMenuItems');
        $item = $this->scopeToProperty(
            $menu->find()->where(['FoodMenuItems.id' => $id, 'FoodMenuItems.deleted_at IS' => null])
        )->firstOrFail();

        $item->set('deleted_at', new \Cake\I18n\DateTime());
        $item->set('is_available', false);
        $menu->saveOrFail($item);

        $this->set('ok', true);
        $this->viewBuilder()->setOption('serialize', ['ok']);
    }

    private function requireManager(): void
    {
        if (!$this->userHasRole('owner', 'admin')) {
            throw new ForbiddenException('Only owners and admins can manage the menu.');
        }
    }

    private function validationFailed(array $errors): void
    {
        $this->response = $this->response->withStatus(422);
        $this->set('errors', $errors);
        $this->viewBuilder()->setOption('serialize', ['errors']);
    }
}
