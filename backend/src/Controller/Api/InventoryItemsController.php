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
     * GET /api/inventory-items[?tracking_type=][?q=][?top_level=1][?page=&limit=]
     *
     * Paginated (mirrors GuestsController::index): `total`/`page`/`limit` are
     * always returned, but `limit` only enforces the 5-100 window when a
     * caller opts in by passing it — callers that don't (Food & Orders'
     * stock-linking pickers, this controller's own parent-item lookups) get
     * the same wide, effectively-unpaginated window (1000) the endpoint
     * always returned before pagination existed.
     *
     * Consumables can nest one level deep (parent + sub-items). While
     * browsing without a search (`top_level=1`, what the Inventory tab's
     * Consumables table sends), the query pages over top-level items only,
     * and each returned parent's own sub-items ride along in `children`
     * (keyed by parent id) so the table's expand/collapse still works without
     * a second request per row. A text search instead flattens to a direct
     * name match across both levels — a matching sub-item's parent might not
     * be on the same page, so nesting doesn't apply — and `children` is
     * empty. Every returned item also carries `has_children` (a correlated
     * EXISTS, not a GROUP BY, so it's safe under MySQL 8's
     * ONLY_FULL_GROUP_BY) regardless of mode, since the edit form needs to
     * know this for any item it's showing, search results included.
     */
    public function index(): void
    {
        $items = $this->fetchTable('InventoryItems');
        $query = $this->scopeToProperty(
            $items->find()
                ->where(['InventoryItems.deleted_at IS' => null])
                ->contain(['InventoryCategories', 'LastReceptionist'])
                ->orderBy(['InventoryItems.name' => 'ASC'])
        );
        // select() with explicit fields turns off the query's normal
        // implicit "all columns" selection, so it has to be turned back on
        // alongside the computed field, or every real column disappears.
        $query->select([
            'has_children' => $query->expr(
                'EXISTS (SELECT 1 FROM inventory_items ci'
                    . ' WHERE ci.parent_id = InventoryItems.id AND ci.deleted_at IS NULL)'
            ),
        ])->enableAutoFields();

        // Optional low-stock filter: ?low_stock=1
        if ($this->request->getQuery('low_stock')) {
            $query->where(['InventoryItems.quantity <=' => $query->identifier('InventoryItems.reorder_level')]);
        }

        $trackingType = $this->request->getQuery('tracking_type');
        if (in_array($trackingType, ['consumable', 'reusable'], true)) {
            $query->where(['InventoryItems.tracking_type' => $trackingType]);
        }

        $search = trim((string)$this->request->getQuery('q'));
        if ($search !== '') {
            $query->where(['InventoryItems.name LIKE' => '%' . $search . '%']);
        }

        $topLevelOnly = (bool)$this->request->getQuery('top_level') && $search === '';
        if ($topLevelOnly) {
            $query->where(['InventoryItems.parent_id IS' => null]);
        }

        $total = $query->count();
        $requestedLimit = $this->request->getQuery('limit');
        $limit = $requestedLimit !== null ? min(100, max(5, (int)$requestedLimit)) : 1000;
        $page = max(1, (int)($this->request->getQuery('page') ?? 1));
        $query->limit($limit)->offset(($page - 1) * $limit);

        $pageItems = $query->all();

        $children = [];
        if ($topLevelOnly) {
            $parentIds = array_map(fn ($it) => $it->id, $pageItems->toArray());
            if ($parentIds) {
                $kids = $this->scopeToProperty(
                    $items->find()
                        ->where(['InventoryItems.parent_id IN' => $parentIds, 'InventoryItems.deleted_at IS' => null])
                        ->contain(['InventoryCategories', 'LastReceptionist'])
                        ->orderBy(['InventoryItems.name' => 'ASC'])
                )->all();
                foreach ($kids as $kid) {
                    $children[$kid->parent_id][] = $kid;
                }
            }
        }

        $this->set([
            'items' => $pageItems,
            'children' => $children,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
        $this->viewBuilder()->setOption('serialize', ['items', 'children', 'total', 'page', 'limit']);
    }

    /**
     * GET /api/inventory-items/{id}
     */
    public function view(int $id): void
    {
        $items = $this->fetchTable('InventoryItems');
        $item = $this->scopeToProperty(
            $items->find()->where(['InventoryItems.id' => $id, 'InventoryItems.deleted_at IS' => null])
        )
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

        // Receptionists operate the catalogue; only owners/admins define it.
        if (!$this->userHasRole('owner', 'admin')) {
            throw new ForbiddenException('Only owners and admins may add inventory items.');
        }

        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        $trackingType = $this->request->getData('tracking_type') === 'reusable' ? 'reusable' : 'consumable';

        $items = $this->fetchTable('InventoryItems');

        $parentId = $this->request->getData('parent_id') ? (int)$this->request->getData('parent_id') : null;
        if ($parentId !== null) {
            $this->assertValidParent($parentId, $propertyId, $trackingType);
        }

        $item = $items->newEntity([
            'property_id' => $propertyId,
            'inventory_category_id' => $this->request->getData('inventory_category_id'),
            'parent_id' => $parentId,
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
        $item = $this->scopeToProperty(
            $items->find()->where(['InventoryItems.id' => $id, 'InventoryItems.deleted_at IS' => null])
        )->firstOrFail();

        $newTracking = $this->request->getData('tracking_type');
        $tracking = in_array($newTracking, ['consumable', 'reusable'], true)
            ? $newTracking
            : $item->tracking_type;

        // Sub-item rules: one level deep, consumables only, no self-nesting.
        $parentId = $this->request->getData('parent_id') ? (int)$this->request->getData('parent_id') : null;
        if ($parentId !== null) {
            if ($parentId === $id) {
                throw new BadRequestException('An item cannot be its own parent.');
            }
            $hasChildren = $items->exists(['parent_id' => $id, 'deleted_at IS' => null]);
            if ($hasChildren) {
                throw new BadRequestException('This item has sub-items; it cannot be nested under another item.');
            }
            $this->assertValidParent($parentId, (int)$item->property_id, $tracking);
        }

        $items->patchEntity($item, [
            'name' => $this->request->getData('name'),
            'unit' => $this->request->getData('unit'),
            'reorder_level' => $this->request->getData('reorder_level'),
            'inventory_category_id' => $this->request->getData('inventory_category_id'),
            'parent_id' => $parentId,
            'tracking_type' => $tracking,
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

    /**
     * DELETE /api/inventory-items/{id}  (owner/admin)
     *
     * Soft-delete: the item is hidden from inventory and from menu linking, but
     * the row stays so its stock_movements (the accountability ledger) remain
     * intact. Any menu items pointing at it are unlinked.
     */
    public function delete(int $id): void
    {
        $this->request->allowMethod(['delete', 'post']);

        if (!$this->userHasRole('owner', 'admin')) {
            throw new ForbiddenException('Only owners and admins may delete inventory items.');
        }

        $items = $this->fetchTable('InventoryItems');
        $item = $this->scopeToProperty(
            $items->find()->where(['InventoryItems.id' => $id, 'InventoryItems.deleted_at IS' => null])
        )->firstOrFail();

        $items->getConnection()->transactional(function () use ($items, $item, $id): void {
            $this->fetchTable('FoodMenuItems')->updateAll(
                ['inventory_item_id' => null],
                ['inventory_item_id' => $id]
            );
            // Its sub-items become top-level again (they keep their own stock).
            $items->updateAll(['parent_id' => null], ['parent_id' => $id]);
            $item->set('deleted_at', new \Cake\I18n\DateTime());
            $items->saveOrFail($item);
        });

        $this->set('ok', true);
        $this->viewBuilder()->setOption('serialize', ['ok']);
    }

    /**
     * A valid parent for a sub-item: same property, not deleted, consumable,
     * and itself top-level (nesting is one level deep). Only consumables can
     * be itemized this way.
     */
    private function assertValidParent(int $parentId, int $propertyId, string $trackingType): void
    {
        if ($trackingType !== 'consumable') {
            throw new BadRequestException('Only consumable items can be nested under a parent item.');
        }

        $items = $this->fetchTable('InventoryItems');
        $parent = $items->find()
            ->where([
                'InventoryItems.id' => $parentId,
                'InventoryItems.property_id' => $propertyId,
                'InventoryItems.deleted_at IS' => null,
            ])
            ->first();

        if ($parent === null) {
            throw new BadRequestException('Parent item not found.');
        }
        if ($parent->tracking_type !== 'consumable') {
            throw new BadRequestException('The parent must be a consumable item.');
        }
        if ($parent->parent_id) {
            throw new BadRequestException('Sub-items can only go one level deep.');
        }
    }
}
