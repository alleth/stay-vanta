<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Model\Table\FoodMenuItemsTable;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;
use Cake\I18n\DateTime;

/**
 * Food menu. Admins/owners manage items & prices; receptionists read them when
 * taking orders.
 */
class FoodMenuItemsController extends AppController
{
    /**
     * GET /api/food-menu-items[?available=1][?type=food|linen]
     */
    public function index(): void
    {
        $menu = $this->fetchTable('FoodMenuItems');
        $query = $this->scopeToProperty(
            $menu->find()
                ->where(['FoodMenuItems.deleted_at IS' => null])
                ->contain(['InventoryItems' => ['InventoryCategories']])
                ->orderBy(['FoodMenuItems.name' => 'ASC']),
        );

        if ($this->request->getQuery('available')) {
            $query->where(['FoodMenuItems.is_available' => true]);
        }
        $type = $this->request->getQuery('type');
        if (in_array($type, FoodMenuItemsTable::TYPES, true)) {
            $query->where(['FoodMenuItems.type' => $type]);
        }

        $this->set('menuItems', $query->all());
        $this->viewBuilder()->setOption('serialize', ['menuItems']);
    }

    /**
     * POST /api/food-menu-items  (owner/admin)
     *
     * `type` (food|linen) picks which Food & Orders tab the item lives on and
     * scopes the Linked Stock choices on the frontend. If the linked inventory
     * item is out of stock (quantity <= 0), the item is force-saved unavailable
     * regardless of what was requested — there's nothing to sell yet.
     */
    public function add(): void
    {
        $this->request->allowMethod('post');
        $this->requireManager();

        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        $inventoryItemId = $this->request->getData('inventory_item_id');
        $inventoryItemId = $inventoryItemId !== null && $inventoryItemId !== '' ? (int)$inventoryItemId : null;
        $type = $this->request->getData('type');

        $menu = $this->fetchTable('FoodMenuItems');
        $item = $menu->newEntity([
            'property_id' => $propertyId,
            'name' => $this->request->getData('name'),
            'type' => in_array($type, FoodMenuItemsTable::TYPES, true) ? $type : 'food',
            'price' => $this->request->getData('price'),
            'inventory_item_id' => $inventoryItemId,
            'is_available' => $this->resolveAvailability(
                $inventoryItemId,
                (bool)($this->request->getData('is_available') ?? true),
            ),
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
            $menu->find()->where(['FoodMenuItems.id' => $id, 'FoodMenuItems.deleted_at IS' => null]),
        )->firstOrFail();

        $inventoryItemId = $this->request->getData('inventory_item_id');
        $inventoryItemId = $inventoryItemId !== null && $inventoryItemId !== '' ? (int)$inventoryItemId : null;
        $type = $this->request->getData('type');

        $menu->patchEntity($item, [
            'name' => $this->request->getData('name'),
            'type' => in_array($type, FoodMenuItemsTable::TYPES, true) ? $type : $item->type,
            'price' => $this->request->getData('price'),
            'inventory_item_id' => $inventoryItemId,
            'is_available' => $this->resolveAvailability(
                $inventoryItemId,
                (bool)($this->request->getData('is_available') ?? $item->is_available),
            ),
        ], ['accessibleFields' => ['property_id' => false]]);

        if (!$menu->save($item)) {
            $this->validationFailed($item->getErrors());

            return;
        }

        $this->set('menuItem', $item);
        $this->viewBuilder()->setOption('serialize', ['menuItem']);
    }

    /**
     * An item linked to out-of-stock inventory (quantity <= 0) is forced
     * unavailable — there's nothing on the shelf to sell yet, regardless of
     * what the admin picked.
     */
    private function resolveAvailability(?int $inventoryItemId, bool $requested): bool
    {
        if ($inventoryItemId === null || !$requested) {
            return $requested;
        }

        $item = $this->fetchTable('InventoryItems')->find()
            ->where(['InventoryItems.id' => $inventoryItemId])
            ->first();

        if ($item !== null && (float)$item->quantity <= 0) {
            return false;
        }

        return $requested;
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
            $menu->find()->where(['FoodMenuItems.id' => $id, 'FoodMenuItems.deleted_at IS' => null]),
        )->firstOrFail();

        $item->set('deleted_at', new DateTime());
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
