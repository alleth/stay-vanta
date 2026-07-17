<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\FoodOrder;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Validation\Validator;
use InvalidArgumentException;
use RuntimeException;

/**
 * FoodOrders model — the order lifecycle and its side effects.
 *
 * Placing an order decrements the linked Food Stock inventory through
 * StockMovementsTable::record() (so the deduction is stamped to the acting
 * receptionist) and, when charge-to-room, mirrors the total onto the guest's
 * invoice. Cancelling reverses both.
 *
 * @method \App\Model\Entity\FoodOrder newEmptyEntity()
 * @method \App\Model\Entity\FoodOrder get(mixed $primaryKey, array $options = [])
 */
class FoodOrdersTable extends Table
{
    public const STATUSES = ['open', 'served', 'cancelled'];
    public const PAYMENT_STATUSES = ['paid', 'charge_to_room', 'unpaid'];
    public const DISCOUNT_TYPES = ['none', 'senior', 'pwd'];

    /** Statutory Senior Citizen / PWD discount, applied to the items subtotal. */
    public const STATUTORY_DISCOUNT = 0.20;

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('food_orders');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Properties');
        $this->belongsTo('Guests');
        $this->belongsTo('Rooms');
        $this->belongsTo('Reservations');
        $this->belongsTo('Receptionist', [
            'className' => 'Users',
            'foreignKey' => 'receptionist_id',
        ]);
        $this->hasMany('FoodOrderItems');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->inList('status', self::STATUSES);
        $validator->inList('payment_status', self::PAYMENT_STATUSES);

        return $validator;
    }

    /**
     * Place an order. $payload:
     *   items[]: {food_menu_item_id, quantity} for menu lines, OR
     *            {description, price, quantity} for custom lines (e.g. cooking
     *            of guest-brought food — no menu item, no stock deduction);
     *   payment_status, guest_id?, room_id?, reservation_id?;
     *   discount_type? (senior|pwd → 20% off the items subtotal, requires
     *   discount_name + discount_id_number); cooking_charge? (added after the
     *   discount — it's a service fee, not food).
     *
     * @throws \InvalidArgumentException On bad items/discount input.
     * @throws \RuntimeException On charge-to-room without a guest, or short stock.
     */
    public function place(array $payload, int $propertyId, int $receptionistId): FoodOrder
    {
        $items = $payload['items'] ?? [];
        if (empty($items)) {
            throw new InvalidArgumentException('An order needs at least one item.');
        }

        $paymentStatus = $payload['payment_status'] ?? 'unpaid';
        $guestId = $payload['guest_id'] ?? null;
        if ($paymentStatus === 'charge_to_room' && !$guestId) {
            throw new RuntimeException('Charge-to-room requires a guest.');
        }

        $discountType = $payload['discount_type'] ?? 'none';
        if (!in_array($discountType, self::DISCOUNT_TYPES, true)) {
            throw new InvalidArgumentException('Unknown discount type.');
        }
        $discountName = trim((string)($payload['discount_name'] ?? ''));
        $discountIdNumber = trim((string)($payload['discount_id_number'] ?? ''));
        if ($discountType !== 'none' && ($discountName === '' || $discountIdNumber === '')) {
            throw new InvalidArgumentException('The Senior/PWD discount needs the beneficiary name and ID number.');
        }

        $cookingCharge = round((float)($payload['cooking_charge'] ?? 0), 2);
        if ($cookingCharge < 0) {
            throw new InvalidArgumentException('Cooking charge cannot be negative.');
        }

        return $this->getConnection()->transactional(
            function () use (
                $items,
                $payload,
                $paymentStatus,
                $guestId,
                $discountType,
                $discountName,
                $discountIdNumber,
                $cookingCharge,
                $propertyId,
                $receptionistId,
            ): FoodOrder {
                $menus = TableRegistry::getTableLocator()->get('FoodMenuItems');
                $orderItems = TableRegistry::getTableLocator()->get('FoodOrderItems');
                $stock = TableRegistry::getTableLocator()->get('StockMovements');
                $inventory = TableRegistry::getTableLocator()->get('InventoryItems');

                $order = $this->newEntity([
                    'property_id' => $propertyId,
                    'guest_id' => $guestId,
                    'room_id' => $payload['room_id'] ?? null,
                    'reservation_id' => $payload['reservation_id'] ?? null,
                    'receptionist_id' => $receptionistId,
                    'status' => 'open',
                    'payment_status' => $paymentStatus,
                    'discount_type' => $discountType,
                    'discount_name' => $discountType !== 'none' ? $discountName : null,
                    'discount_id_number' => $discountType !== 'none' ? $discountIdNumber : null,
                    'cooking_charge' => $cookingCharge,
                    'total' => 0,
                ]);
                $this->saveOrFail($order);

                $subtotal = 0.0;
                foreach ($items as $line) {
                    if (!empty($line['food_menu_item_id'])) {
                        // Menu line: price from the menu, stock from the link.
                        $menu = $menus->find()
                            ->where([
                                'FoodMenuItems.id' => (int)$line['food_menu_item_id'],
                                'FoodMenuItems.property_id' => $propertyId,
                            ])
                            ->firstOrFail();
                        $qty = max(1, (int)($line['quantity'] ?? 1));
                        $lineTotal = (float)$menu->price * $qty;
                        $subtotal += $lineTotal;

                        $orderItems->saveOrFail($orderItems->newEntity([
                            'food_order_id' => $order->id,
                            'food_menu_item_id' => $menu->id,
                            'quantity' => $qty,
                            'unit_price' => $menu->price,
                            'line_total' => $lineTotal,
                        ]));

                        // Decrement the linked Food Stock (stamped to this receptionist).
                        if ($menu->inventory_item_id) {
                            $item = $inventory->get($menu->inventory_item_id);
                            $stock->record($item, 'out', (float)$qty, $receptionistId, [
                                'reason' => 'food_order',
                                'reference_type' => 'food_order',
                                'reference_id' => $order->id,
                            ]);
                        }
                        continue;
                    }

                    // Custom line: typed description + price, no stock deduction.
                    $description = trim((string)($line['description'] ?? $line['name'] ?? ''));
                    if ($description === '') {
                        throw new InvalidArgumentException('A custom item needs a description.');
                    }
                    $price = round((float)($line['price'] ?? 0), 2);
                    if ($price < 0) {
                        throw new InvalidArgumentException('A custom item price cannot be negative.');
                    }
                    $qty = max(1, (int)($line['quantity'] ?? 1));
                    $lineTotal = $price * $qty;
                    $subtotal += $lineTotal;

                    $orderItems->saveOrFail($orderItems->newEntity([
                        'food_order_id' => $order->id,
                        'food_menu_item_id' => null,
                        'description' => $description,
                        'quantity' => $qty,
                        'unit_price' => $price,
                        'line_total' => $lineTotal,
                    ]));
                }

                $discount = $discountType !== 'none' ? round($subtotal * self::STATUTORY_DISCOUNT, 2) : 0.0;
                $total = $subtotal - $discount + $cookingCharge;

                $order->set('total', $total);
                $this->saveOrFail($order);

                if ($paymentStatus === 'charge_to_room') {
                    $invoices = TableRegistry::getTableLocator()->get('Invoices');
                    $invoice = $invoices->openInvoiceFor(
                        $propertyId,
                        (int)$guestId,
                        $payload['reservation_id'] ?? null,
                    );
                    $description = 'Food order #' . $order->id;
                    if ($discountType !== 'none') {
                        $description .= sprintf(
                            ' — %s 20%% off (%s, ID %s)',
                            $discountType === 'senior' ? 'Senior' : 'PWD',
                            $discountName,
                            $discountIdNumber,
                        );
                    }
                    $invoices->addLine($invoice, $description, $subtotal - $discount, 'food_order', (int)$order->id);
                    if ($cookingCharge > 0) {
                        $invoices->addLine(
                            $invoice,
                            'Cooking charge — food order #' . $order->id,
                            $cookingCharge,
                            'food_order',
                            (int)$order->id,
                        );
                    }
                }

                return $order;
            },
        );
    }

    /**
     * Cancel an order: restock the inventory it consumed and reverse any
     * charge-to-room invoice lines. Stamped to the cancelling receptionist.
     */
    public function cancelOrder(FoodOrder $order, int $receptionistId): FoodOrder
    {
        if ($order->status === 'cancelled') {
            throw new RuntimeException('Order is already cancelled.');
        }

        return $this->getConnection()->transactional(
            function () use ($order, $receptionistId): FoodOrder {
                $orderItems = TableRegistry::getTableLocator()->get('FoodOrderItems');
                $stock = TableRegistry::getTableLocator()->get('StockMovements');
                $inventory = TableRegistry::getTableLocator()->get('InventoryItems');

                $lines = $orderItems->find()
                    ->where(['FoodOrderItems.food_order_id' => $order->id])
                    ->contain(['FoodMenuItems'])
                    ->all();

                foreach ($lines as $line) {
                    if ($line->food_menu_item && $line->food_menu_item->inventory_item_id) {
                        $item = $inventory->get($line->food_menu_item->inventory_item_id);
                        $stock->record($item, 'in', (float)$line->quantity, $receptionistId, [
                            'reason' => 'food_order_cancel',
                            'reference_type' => 'food_order',
                            'reference_id' => $order->id,
                        ]);
                    }
                }

                if ($order->payment_status === 'charge_to_room') {
                    TableRegistry::getTableLocator()->get('Invoices')->removeLinesFor('food_order', (int)$order->id);
                }

                $order->set('status', 'cancelled');
                $this->saveOrFail($order);

                return $order;
            }
        );
    }
}
