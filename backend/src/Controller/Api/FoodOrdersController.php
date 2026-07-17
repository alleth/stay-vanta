<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Food orders. Receptionists take orders for guests; placing one decrements
 * Food Stock and (if charge-to-room) appends to the guest invoice.
 */
class FoodOrdersController extends AppController
{
    /**
     * GET /api/food-orders[?status=open]
     */
    public function index(): void
    {
        $orders = $this->fetchTable('FoodOrders');
        $query = $this->scopeToProperty(
            $orders->find()
                ->contain(['Guests', 'Rooms', 'Receptionist', 'FoodOrderItems' => ['FoodMenuItems']])
                ->orderBy(['FoodOrders.created' => 'DESC'])
        );

        $status = $this->request->getQuery('status');
        if ($status !== null && $status !== 'all') {
            $query->where(['FoodOrders.status' => $status]);
        }

        // Day filter (default handled client-side): each day is a fresh start.
        $this->applyDateFilter($query, 'FoodOrders.created', $this->request->getQuery('date'));

        // Pagination — orders can grow large.
        $total = $query->count();
        $limit = min(100, max(5, (int)($this->request->getQuery('limit') ?? 20)));
        $page = max(1, (int)($this->request->getQuery('page') ?? 1));
        $query->limit($limit)->offset(($page - 1) * $limit);

        $this->set([
            'orders' => $query->all(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
        $this->viewBuilder()->setOption('serialize', ['orders', 'total', 'page', 'limit']);
    }

    /**
     * Restrict a query to a single calendar day on $column unless $date is empty
     * or 'all'. $date must be YYYY-MM-DD.
     */
    private function applyDateFilter(\Cake\ORM\Query\SelectQuery $query, string $column, ?string $date): void
    {
        if ($date === null || $date === 'all' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return;
        }
        $next = date('Y-m-d', strtotime($date . ' +1 day'));
        $query->where([$column . ' >=' => $date . ' 00:00:00', $column . ' <' => $next . ' 00:00:00']);
    }

    /**
     * GET /api/food-orders/{id}
     */
    public function view(int $id): void
    {
        $orders = $this->fetchTable('FoodOrders');
        $order = $this->scopeToProperty($orders->find()->where(['FoodOrders.id' => $id]))
            ->contain(['Guests', 'Rooms', 'Receptionist', 'FoodOrderItems' => ['FoodMenuItems']])
            ->firstOrFail();

        $this->set('order', $order);
        $this->viewBuilder()->setOption('serialize', ['order']);
    }

    /**
     * POST /api/food-orders
     * { items:[{food_menu_item_id, quantity}], payment_status,
     *   guest_id?, room_id?, reservation_id? }
     */
    public function add(): void
    {
        $this->request->allowMethod('post');

        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        $orders = $this->fetchTable('FoodOrders');
        try {
            $order = $orders->place(
                [
                    'items' => $this->request->getData('items') ?? [],
                    'payment_status' => $this->request->getData('payment_status') ?? 'unpaid',
                    'guest_id' => $this->request->getData('guest_id'),
                    'room_id' => $this->request->getData('room_id'),
                    'reservation_id' => $this->request->getData('reservation_id'),
                    'discount_type' => $this->request->getData('discount_type') ?? 'none',
                    'discount_name' => $this->request->getData('discount_name'),
                    'discount_id_number' => $this->request->getData('discount_id_number'),
                    'cooking_charge' => $this->request->getData('cooking_charge') ?? 0,
                ],
                $propertyId,
                (int)$this->currentUser->id
            );
        } catch (InvalidArgumentException | RuntimeException $e) {
            // e.g. no items, charge-to-room without guest, or insufficient stock.
            throw new BadRequestException($e->getMessage());
        }

        $this->respondWith($order->id, 201);
    }

    /**
     * POST /api/food-orders/{id}/serve
     */
    public function serve(int $id): void
    {
        $this->request->allowMethod('post');
        $orders = $this->fetchTable('FoodOrders');
        $order = $this->scopeToProperty($orders->find()->where(['FoodOrders.id' => $id]))->firstOrFail();

        if ($order->status !== 'open') {
            throw new BadRequestException('Only open orders can be served.');
        }
        $order->set('status', 'served');
        $orders->saveOrFail($order);

        $this->respondWith($order->id, 200);
    }

    /**
     * POST /api/food-orders/{id}/cancel — restocks inventory and reverses any
     * charge-to-room invoice lines.
     */
    public function cancel(int $id): void
    {
        $this->request->allowMethod('post');
        $orders = $this->fetchTable('FoodOrders');
        $order = $this->scopeToProperty($orders->find()->where(['FoodOrders.id' => $id]))->firstOrFail();

        // A receptionist can't reverse a settled transaction: an order that has
        // been both served and paid is closed business. Owners/admins still may.
        if (
            $this->userHasRole('receptionist')
            && $order->status === 'served'
            && $order->payment_status === 'paid'
        ) {
            throw new ForbiddenException('A paid, served order can only be cancelled by an admin.');
        }

        try {
            $orders->cancelOrder($order, (int)$this->currentUser->id);
        } catch (RuntimeException $e) {
            throw new BadRequestException($e->getMessage());
        }

        $this->respondWith($order->id, 200);
    }

    private function respondWith(int $orderId, int $status): void
    {
        $orders = $this->fetchTable('FoodOrders');
        $order = $orders->get($orderId, contain: ['Guests', 'Rooms', 'Receptionist', 'FoodOrderItems' => ['FoodMenuItems']]);

        $this->response = $this->response->withStatus($status);
        $this->set('order', $order);
        $this->viewBuilder()->setOption('serialize', ['order']);
    }
}
