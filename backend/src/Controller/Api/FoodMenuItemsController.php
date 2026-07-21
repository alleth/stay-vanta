<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Model\Entity\FoodMenuItem;
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
                ->contain([
                    'InventoryItems' => ['InventoryCategories'],
                    'FoodMenuItemIngredients' => ['InventoryItems' => ['InventoryCategories']],
                ])
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
     *
     * `ingredients[]` ({inventory_item_id, quantity}) is an optional recipe —
     * on top of (not instead of) the single `inventory_item_id` link — each row
     * naming how much of an inventory item one serving of this dish consumes;
     * ordering the item decrements every ingredient alongside the single link.
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
        $ingredients = $this->normalizeIngredients((array)$this->request->getData('ingredients'), $propertyId);

        $menu = $this->fetchTable('FoodMenuItems');
        $item = $menu->newEntity([
            'property_id' => $propertyId,
            'name' => $this->request->getData('name'),
            'type' => in_array($type, FoodMenuItemsTable::TYPES, true) ? $type : 'food',
            'price' => $this->request->getData('price'),
            'inventory_item_id' => $inventoryItemId,
            'is_available' => $this->resolveAvailability(
                $inventoryItemId,
                $ingredients,
                (bool)($this->request->getData('is_available') ?? true),
            ),
        ]);

        if (!$menu->save($item)) {
            $this->validationFailed($item->getErrors());

            return;
        }

        $this->saveIngredients($item, $ingredients);

        $this->response = $this->response->withStatus(201);
        $this->set('menuItem', $this->reloadWithIngredients($item->id));
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
        $rawIngredients = (array)$this->request->getData('ingredients');
        $ingredients = $this->normalizeIngredients($rawIngredients, (int)$item->property_id);

        $menu->patchEntity($item, [
            'name' => $this->request->getData('name'),
            'type' => in_array($type, FoodMenuItemsTable::TYPES, true) ? $type : $item->type,
            'price' => $this->request->getData('price'),
            'inventory_item_id' => $inventoryItemId,
            'is_available' => $this->resolveAvailability(
                $inventoryItemId,
                $ingredients,
                (bool)($this->request->getData('is_available') ?? $item->is_available),
            ),
        ], ['accessibleFields' => ['property_id' => false]]);

        if (!$menu->save($item)) {
            $this->validationFailed($item->getErrors());

            return;
        }

        $this->saveIngredients($item, $ingredients);

        $this->set('menuItem', $this->reloadWithIngredients($item->id));
        $this->viewBuilder()->setOption('serialize', ['menuItem']);
    }

    /**
     * An item linked to out-of-stock inventory (quantity <= 0), or missing any
     * ingredient's required-per-serving quantity, is forced unavailable —
     * there's nothing on the shelf to sell yet, regardless of what the admin
     * picked.
     *
     * @param array<int, array{inventory_item_id: int, quantity: float}> $ingredients
     */
    private function resolveAvailability(?int $inventoryItemId, array $ingredients, bool $requested): bool
    {
        if (!$requested || (!$inventoryItemId && !$ingredients)) {
            return $requested;
        }

        $inventoryItems = $this->fetchTable('InventoryItems');

        if ($inventoryItemId !== null) {
            $item = $inventoryItems->find()->where(['InventoryItems.id' => $inventoryItemId])->first();
            if ($item !== null && (float)$item->quantity <= 0) {
                return false;
            }
        }

        foreach ($ingredients as $ingredient) {
            $item = $inventoryItems->find()
                ->where(['InventoryItems.id' => $ingredient['inventory_item_id']])
                ->first();
            if ($item !== null && (float)$item->quantity < $ingredient['quantity']) {
                return false;
            }
        }

        return $requested;
    }

    /**
     * Parse+validate a raw `ingredients` request array into
     * {inventory_item_id, quantity} rows: each item must exist, belong to this
     * property, and not be soft-deleted; quantity must be > 0; duplicate
     * inventory items in the same recipe are rejected.
     *
     * @return array<int, array{inventory_item_id: int, quantity: float}>
     */
    private function normalizeIngredients(array $raw, int $propertyId): array
    {
        $inventoryItems = $this->fetchTable('InventoryItems');
        $seen = [];
        $ingredients = [];

        foreach ($raw as $row) {
            $inventoryItemId = (int)($row['inventory_item_id'] ?? 0);
            if ($inventoryItemId <= 0) {
                continue;
            }
            $quantity = (float)($row['quantity'] ?? 0);
            if ($quantity <= 0) {
                throw new BadRequestException('Each ingredient needs a quantity greater than zero.');
            }
            if (isset($seen[$inventoryItemId])) {
                throw new BadRequestException('The same ingredient was added more than once.');
            }
            $seen[$inventoryItemId] = true;

            $exists = $inventoryItems->exists([
                'InventoryItems.id' => $inventoryItemId,
                'InventoryItems.property_id' => $propertyId,
                'InventoryItems.deleted_at IS' => null,
            ]);
            if (!$exists) {
                throw new BadRequestException('One of the selected ingredients was not found.');
            }

            $ingredients[] = ['inventory_item_id' => $inventoryItemId, 'quantity' => $quantity];
        }

        return $ingredients;
    }

    /**
     * Replace a menu item's recipe with the given rows (delete-then-insert —
     * the list is small and always sent in full from the edit form).
     *
     * @param array<int, array{inventory_item_id: int, quantity: float}> $ingredients
     */
    private function saveIngredients(FoodMenuItem $item, array $ingredients): void
    {
        $ingredientsTable = $this->fetchTable('FoodMenuItemIngredients');
        $rebuild = function () use ($ingredientsTable, $item, $ingredients): void {
            $ingredientsTable->deleteAll(['food_menu_item_id' => $item->id]);
            foreach ($ingredients as $ingredient) {
                $ingredientsTable->saveOrFail($ingredientsTable->newEntity([
                    'food_menu_item_id' => $item->id,
                    'inventory_item_id' => $ingredient['inventory_item_id'],
                    'quantity' => $ingredient['quantity'],
                ]));
            }
        };
        $ingredientsTable->getConnection()->transactional($rebuild);
    }

    /**
     * Reload a saved menu item with its stock link + recipe contained, for
     * the response payload.
     */
    private function reloadWithIngredients(int $id): FoodMenuItem
    {
        return $this->fetchTable('FoodMenuItems')->find()
            ->where(['FoodMenuItems.id' => $id])
            ->contain([
                'InventoryItems' => ['InventoryCategories'],
                'FoodMenuItemIngredients' => ['InventoryItems' => ['InventoryCategories']],
            ])
            ->firstOrFail();
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
