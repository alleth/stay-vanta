<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Http\Exception\BadRequestException;
use RuntimeException;

/**
 * Stock movements — the accountability ledger and the only way to change an
 * item's on-hand quantity.
 */
class StockMovementsController extends AppController
{
    /**
     * GET /api/stock-movements          (whole-property ledger)
     * GET /api/stock-movements?inventory_item_id=NN
     */
    public function index(): void
    {
        $movements = $this->fetchTable('StockMovements');
        $query = $this->scopeToProperty(
            $movements->find()
                ->contain(['InventoryItems', 'Receptionist'])
                ->orderBy(['StockMovements.created' => 'DESC'])
                ->limit(200)
        );

        $itemId = $this->request->getQuery('inventory_item_id');
        if ($itemId !== null) {
            $query->where(['StockMovements.inventory_item_id' => (int)$itemId]);
        }

        $this->set('movements', $query->all());
        $this->viewBuilder()->setOption('serialize', ['movements']);
    }

    /**
     * POST /api/stock-movements
     * { inventory_item_id, direction: in|out, quantity, reason?, note?, affects_total? }
     *
     * The acting user (the authenticated receptionist) is recorded on both the
     * movement and the item — this is the core accountability stamp. Owners and
     * admins may also move stock; whoever is authenticated is recorded.
     *
     * For reusable items, `affects_total` distinguishes acquiring/retiring units
     * (owned total moves too) from merely issuing/returning them (only the
     * available count moves). Ignored for consumables.
     */
    public function add(): void
    {
        $this->request->allowMethod('post');

        $itemId = (int)$this->request->getData('inventory_item_id');
        $direction = (string)$this->request->getData('direction');
        $quantity = (float)$this->request->getData('quantity');

        if ($itemId <= 0 || $quantity <= 0) {
            throw new BadRequestException('inventory_item_id and a positive quantity are required.');
        }

        $items = $this->fetchTable('InventoryItems');
        $item = $this->scopeToProperty($items->find()->where(['InventoryItems.id' => $itemId]))->firstOrFail();

        try {
            $movement = $this->fetchTable('StockMovements')->record(
                $item,
                $direction,
                $quantity,
                (int)$this->currentUser->id,
                [
                    'reason' => $this->request->getData('reason'),
                    'note' => $this->request->getData('note'),
                ],
                (bool)$this->request->getData('affects_total')
            );
        } catch (RuntimeException $e) {
            throw new BadRequestException($e->getMessage());
        }

        $this->response = $this->response->withStatus(201);
        $this->set([
            'movement' => $movement,
            'item' => $item, // reflects the new quantity & last_receptionist_id
        ]);
        $this->viewBuilder()->setOption('serialize', ['movement', 'item']);
    }
}
