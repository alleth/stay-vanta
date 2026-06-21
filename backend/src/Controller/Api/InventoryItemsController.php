<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;

/**
 * Inventory items. Quantities are read here but only ever changed through
 * StockMovementsController so the ledger stays authoritative.
 */
class InventoryItemsController extends AppController
{
    /**
     * GET /api/inventory-items
     */
    public function index(): void
    {
        $items = $this->fetchTable('InventoryItems');
        $query = $this->scopeToProperty(
            $items->find()
                ->contain(['InventoryCategories', 'LastReceptionist'])
                ->orderBy(['InventoryItems.name' => 'ASC'])
        );

        // Optional low-stock filter: ?low_stock=1
        if ($this->request->getQuery('low_stock')) {
            $query->where(['InventoryItems.quantity <=' => $query->identifier('InventoryItems.reorder_level')]);
        }

        $this->set('items', $query->all());
        $this->viewBuilder()->setOption('serialize', ['items']);
    }

    /**
     * GET /api/inventory-items/{id}
     */
    public function view(int $id): void
    {
        $items = $this->fetchTable('InventoryItems');
        $item = $this->scopeToProperty($items->find()->where(['InventoryItems.id' => $id]))
            ->contain(['InventoryCategories', 'LastReceptionist'])
            ->firstOrFail();

        $this->set('item', $item);
        $this->viewBuilder()->setOption('serialize', ['item']);
    }

    /**
     * POST /api/inventory-items
     *
     * Creates the item. An optional opening `quantity` is applied as an
     * initial 'in' stock movement so it is captured in the ledger.
     */
    public function add(): void
    {
        $this->request->allowMethod('post');

        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        $trackingType = $this->request->getData('tracking_type') === 'reusable' ? 'reusable' : 'consumable';

        $items = $this->fetchTable('InventoryItems');
        $item = $items->newEntity([
            'property_id' => $propertyId,
            'inventory_category_id' => $this->request->getData('inventory_category_id'),
            'name' => $this->request->getData('name'),
            'tracking_type' => $trackingType,
            'unit' => $this->request->getData('unit') ?? 'pcs',
            'reorder_level' => $this->request->getData('reorder_level') ?? 0,
        ]);
        // Reusables track owned units; start the total at zero so the opening
        // 'in' (below) can raise both available and owned together.
        if ($trackingType === 'reusable') {
            $item->set('total_quantity', 0);
        }

        if (!$items->save($item)) {
            $this->response = $this->response->withStatus(422);
            $this->set('errors', $item->getErrors());
            $this->viewBuilder()->setOption('serialize', ['errors']);

            return;
        }

        $opening = (float)($this->request->getData('quantity') ?? 0);
        if ($opening > 0) {
            $this->fetchTable('StockMovements')->record(
                $item,
                'in',
                $opening,
                (int)$this->currentUser->id,
                ['reason' => 'opening_balance'],
                $trackingType === 'reusable' // opening units are also owned units
            );
        }

        $this->set('item', $item);
        $this->viewBuilder()->setOption('serialize', ['item']);
    }

    /**
     * PATCH/PUT /api/inventory-items/{id}
     *
     * Edits descriptive fields only — never the quantity (use a movement).
     * Owner/admin only: this is how a mis-categorised item or a wrong
     * consumable/reusable type gets corrected.
     */
    public function edit(int $id): void
    {
        $this->request->allowMethod(['patch', 'put', 'post']);

        if (!$this->userHasRole('owner', 'admin')) {
            throw new ForbiddenException('Only owners and admins may edit inventory items.');
        }

        $items = $this->fetchTable('InventoryItems');
        $item = $this->scopeToProperty($items->find()->where(['InventoryItems.id' => $id]))->firstOrFail();

        $newTracking = $this->request->getData('tracking_type');
        $items->patchEntity($item, [
            'name' => $this->request->getData('name'),
            'unit' => $this->request->getData('unit'),
            'reorder_level' => $this->request->getData('reorder_level'),
            'inventory_category_id' => $this->request->getData('inventory_category_id'),
            'tracking_type' => in_array($newTracking, ['consumable', 'reusable'], true)
                ? $newTracking
                : $item->tracking_type,
        ]);

        // Keep total_quantity coherent when the type changes: a reusable needs an
        // owned total (assume current on-hand are owned & available); a consumable
        // doesn't track one.
        if ($item->tracking_type === 'reusable' && $item->total_quantity === null) {
            $item->set('total_quantity', $item->quantity);
        } elseif ($item->tracking_type === 'consumable') {
            $item->set('total_quantity', null);
        }

        if (!$items->save($item)) {
            $this->response = $this->response->withStatus(422);
            $this->set('errors', $item->getErrors());
            $this->viewBuilder()->setOption('serialize', ['errors']);

            return;
        }

        $this->set('item', $item);
        $this->viewBuilder()->setOption('serialize', ['item']);
    }
}
