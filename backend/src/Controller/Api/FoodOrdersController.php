<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Permissions;
use App\Model\BusinessTime;
use Cake\Http\Exception\BadRequestException;
use Cake\ORM\Query\SelectQuery;
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
        $this->authorize(Permissions::POS_SALE_VIEW);
        $orders = $this->fetchTable('FoodOrders');
        $query = $this->scopeToProperty(
            $orders->find()
                ->contain([
                    'Guests', 'Rooms', 'Receptionist', 'FoodOrderItems' => ['FoodMenuItems'], 'FoodOrderDiscounts',
                ])
                ->orderBy(['FoodOrders.created' => 'DESC']),
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
    private function applyDateFilter(SelectQuery $query, string $column, ?string $date): void
    {
        if ($date === null || $date === 'all' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return;
        }
        // The hotel's day, not UTC's (see BusinessTime).
        $query->where([$column . ' >=' => BusinessTime::startOf($date), $column . ' <' => BusinessTime::endOf($date)]);
    }

    /**
     * GET /api/food-orders/{id}
     */
    public function view(int $id): void
    {
        $this->authorize(Permissions::POS_SALE_VIEW);
        $orders = $this->fetchTable('FoodOrders');
        $order = $this->scopeToProperty($orders->find()->where(['FoodOrders.id' => $id]))
            ->contain(['Guests', 'Rooms', 'Receptionist', 'FoodOrderItems' => ['FoodMenuItems'], 'FoodOrderDiscounts'])
            ->firstOrFail();

        $this->set('order', $order);
        $this->viewBuilder()->setOption('serialize', ['order']);
    }

    /**
     * POST /api/food-orders
     * { items:[{food_menu_item_id, quantity}], payment_status,
     *   guest_id?, room_id?, reservation_id?, total_diners?, discount_beneficiaries? }
     */
    public function add(): void
    {
        $this->request->allowMethod('post');
        $this->authorize(Permissions::POS_SALE_MANAGE);

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
                    'payment_method' => $this->request->getData('payment_method'),
                    'guest_id' => $this->request->getData('guest_id'),
                    'room_id' => $this->request->getData('room_id'),
                    'reservation_id' => $this->request->getData('reservation_id'),
                    'total_diners' => $this->request->getData('total_diners') ?? 1,
                    'discount_beneficiaries' => $this->request->getData('discount_beneficiaries') ?? [],
                    'cooking_charge' => $this->request->getData('cooking_charge') ?? 0,
                ],
                $propertyId,
                $this->eventContext(),
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
        $this->authorize(Permissions::POS_SALE_MANAGE);
        $orders = $this->fetchTable('FoodOrders');
        $order = $this->scopeToProperty($orders->find()->where(['FoodOrders.id' => $id]))->firstOrFail();

        try {
            $orders->serve($order, $this->eventContext());
        } catch (RuntimeException $e) {
            throw new BadRequestException($e->getMessage());
        }

        $this->respondWith($order->id, 200);
    }

    /**
     * POST /api/food-orders/{id}/cancel — restocks inventory and reverses any
     * charge-to-room invoice lines.
     */
    public function cancel(int $id): void
    {
        $this->request->allowMethod('post');
        $this->authorize(Permissions::POS_SALE_MANAGE);
        $orders = $this->fetchTable('FoodOrders');
        $order = $this->scopeToProperty($orders->find()->where(['FoodOrders.id' => $id]))->firstOrFail();

        // An order that has been both served and paid is closed business:
        // reversing it takes pos.sale.cancel_paid (a Manager), not just
        // pos.sale.manage, and a reason. The reason is accepted but not yet
        // required until the POS that sends one has replaced the old one
        // (FoodOrderEventsTable::REASON_GRACE; required from the cleanup release).
        if ($order->status === 'served' && $order->payment_status === 'paid') {
            $this->authorizeElevated(
                Permissions::POS_SALE_CANCEL_PAID,
                'A paid, served order can only be cancelled by a Manager.',
                reasonOptional: true,
            );
        }

        try {
            $orders->cancelOrder($order, $this->eventContext());
        } catch (RuntimeException $e) {
            throw new BadRequestException($e->getMessage());
        }

        $this->respondWith($order->id, 200);
    }

    /**
     * Answer with the order as it now stands.
     */
    private function respondWith(int $orderId, int $status): void
    {
        $orders = $this->fetchTable('FoodOrders');
        $order = $orders->get($orderId, contain: [
            'Guests', 'Rooms', 'Receptionist', 'FoodOrderItems' => ['FoodMenuItems'], 'FoodOrderDiscounts',
        ]);

        $this->response = $this->response->withStatus($status);
        $this->set('order', $order);
        $this->viewBuilder()->setOption('serialize', ['order']);
    }
}
