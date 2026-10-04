<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Permissions;
use App\Model\BusinessTime;
use App\Model\Finance\DuplicateRefundException;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ConflictException;
use Cake\ORM\Query\SelectQuery;
use InvalidArgumentException;
use RuntimeException;

/**
 * Food orders. Receptionists take orders for guests; placing one decrements
 * Food Stock and (if charge-to-room) appends to the guest invoice.
 */
class FoodOrdersController extends AppController
{
    /** What a sale is answered with: its lines, discounts, guest, room and who placed it (id and name). */
    private const ORDER_CONTAIN = [
        'Guests',
        'Rooms',
        'Receptionist' => self::USER_BRIEF,
        'FoodOrderItems' => ['FoodMenuItems'],
        'FoodOrderDiscounts',
    ];

    /**
     * GET /api/food-orders[?status=open]
     */
    public function index(): void
    {
        $this->authorize(Permissions::POS_SALE_VIEW);
        $orders = $this->fetchTable('FoodOrders');
        $query = $this->scopeToProperty(
            $orders->find()
                ->contain(self::ORDER_CONTAIN)
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

        // Whether money went back for each sale (a `refunded` event, step 7c),
        // so a cancelled paid sale with no refund on record can offer one.
        $list = $query->all()->toList();
        $ids = array_map(fn($o) => (int)$o->id, $list);
        $refunded = $ids === [] ? [] : array_flip($this->fetchTable('FoodOrderEvents')->find()
            ->select(['food_order_id'])
            ->where(['food_order_id IN' => $ids, 'event_type' => 'refunded'])
            ->all()->extract('food_order_id')->map(fn($id) => (int)$id)->toList());
        foreach ($list as $order) {
            $order->set('refunded', isset($refunded[(int)$order->id]));
        }

        $this->set([
            'orders' => $list,
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
            ->contain(self::ORDER_CONTAIN)
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
     *
     * Optional `refund: {returned: bool, method}` (build step 7c, D4): for a
     * paid sale, whether money went back to the guest. `returned: true`
     * records a `refunded` event (cash out today; needs `reason` and a
     * method); false or absent keeps the sale collected. Cancelling and
     * refunding stay separate.
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
            $orders->cancelOrder($order, $this->eventContext(), $this->refundRequest());
        } catch (DuplicateRefundException $e) {
            throw new ConflictException($e->getMessage());
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
        $order = $orders->get($orderId, contain: self::ORDER_CONTAIN);

        $this->response = $this->response->withStatus($status);
        $this->set('order', $order);
        $this->viewBuilder()->setOption('serialize', ['order']);
    }

    /**
     * POST /api/food-orders/{id}/refund  { method, reason, refund_key? }
     *
     * Money returned for a paid sale that was cancelled earlier with no
     * refund on record (build step 7c): a Manager (pos.sale.cancel_paid) with
     * a reason, recorded today, never backdated, once per sale; a repeated
     * `refund_key` is 409.
     */
    public function refund(int $id): void
    {
        $this->request->allowMethod('post');
        $this->authorizeElevated(
            Permissions::POS_SALE_CANCEL_PAID,
            'Only a Manager can record a refund for a paid sale.',
        );
        /** @var \App\Model\Table\FoodOrdersTable $orders */
        $orders = $this->fetchTable('FoodOrders');
        $order = $this->scopeToProperty($orders->find()->where(['FoodOrders.id' => $id]))->firstOrFail();

        try {
            $orders->refundCancelled($order, $this->eventContext(), [
                'method' => (string)$this->request->getData('method'),
                'key' => $this->refundKey(),
            ]);
        } catch (DuplicateRefundException $e) {
            throw new ConflictException($e->getMessage());
        } catch (RuntimeException $e) {
            throw new BadRequestException($e->getMessage());
        }

        $this->respondWith($order->id, 200);
    }

    /**
     * The cancel request's `refund`, when money went back: the method and
     * request key; null when it didn't (or the screen didn't say).
     *
     * @return array{method: string, key: string|null}|null
     */
    private function refundRequest(): ?array
    {
        $refund = $this->request->getData('refund');
        if (!is_array($refund) || !filter_var($refund['returned'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        return ['method' => (string)($refund['method'] ?? ''), 'key' => $this->refundKey()];
    }

    /**
     * The request's `refund_key` (made when the refund dialog opened), if any.
     */
    private function refundKey(): ?string
    {
        $key = $this->request->getData('refund_key');
        if ($key === null || $key === '') {
            return null;
        }
        if (!is_string($key) || strlen($key) > 64) {
            throw new BadRequestException('refund_key must be a string of up to 64 characters.');
        }

        return $key;
    }
}
