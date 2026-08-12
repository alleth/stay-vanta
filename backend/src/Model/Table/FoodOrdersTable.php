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
 * Placing an order decrements the linked Food Stock inventory, every recipe
 * ingredient (FoodMenuItemIngredients), and every selected option-group pick
 * (FoodMenuItemOptionGroups/Options — guest-facing choices, e.g. a free drink
 * choice or a paid add-on) through StockMovementsTable::record() (so the
 * deduction is stamped to the acting receptionist) and, when charge-to-room,
 * mirrors the total onto the guest's invoice. Cancelling reverses all of it.
 *
 * @method \App\Model\Entity\FoodOrder newEmptyEntity()
 * @method \App\Model\Entity\FoodOrder get(mixed $primaryKey, array $options = [])
 */
class FoodOrdersTable extends Table
{
    public const STATUSES = ['open', 'served', 'cancelled'];
    public const PAYMENT_STATUSES = ['paid', 'charge_to_room', 'unpaid'];
    public const PAYMENT_METHODS = ['cash', 'gcash', 'maya', 'gotyme'];
    public const BENEFICIARY_TYPES = ['senior', 'pwd'];

    /**
     * Statutory Senior Citizen / PWD discount. Legally it only covers a
     * qualified diner's own share of the bill, not the whole table — see
     * place()'s discount math.
     */
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
        $this->hasMany('FoodOrderDiscounts', ['dependent' => true]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->inList('status', self::STATUSES);
        $validator->inList('payment_status', self::PAYMENT_STATUSES);
        $validator
            ->inList('payment_method', self::PAYMENT_METHODS)
            ->allowEmptyString('payment_method');

        return $validator;
    }

    /**
     * Place an order. $payload:
     *   items[]: {food_menu_item_id, quantity, selected_options?} for menu lines,
     *            where selected_options[] is {option_id, quantity} — exactly one
     *            option per `choice` option group defined on the item is required,
     *            `addon` group options are optional and may repeat any number of
     *            units; OR {description, price, quantity} for custom lines (e.g.
     *            cooking of guest-brought food — no menu item, no stock deduction);
     *   payment_status, payment_method? (required when payment_status is
     *   'paid' — cash|gcash|maya|gotyme), guest_id?, room_id?, reservation_id?;
     *   total_diners? (default 1); discount_beneficiaries? ([{discount_type:
     *   senior|pwd, name, id_number}, ...] — zero or more Senior/PWD diners on
     *   this order, e.g. two seniors at the same table; each needs a name +
     *   ID number and the statutory 20% only covers that beneficiary's own
     *   even share of the items subtotal — subtotal * (beneficiaries /
     *   total_diners) * 20%, capped at the full subtotal since beneficiaries
     *   can never exceed total_diners); cooking_charge? (added after the
     *   discount — it's a service fee, not food).
     *
     * @throws \InvalidArgumentException On bad items/discount/payment/option input.
     * @throws \RuntimeException On charge-to-room without a guest (or a guest who
     *   isn't currently checked in), or short stock.
     */
    public function place(array $payload, int $propertyId, int $receptionistId): FoodOrder
    {
        $items = $payload['items'] ?? [];
        if (empty($items)) {
            throw new InvalidArgumentException('An order needs at least one item.');
        }

        $paymentStatus = $payload['payment_status'] ?? 'unpaid';
        $guestId = $payload['guest_id'] ?? null;
        if ($paymentStatus === 'charge_to_room') {
            if (!$guestId) {
                throw new RuntimeException('Charge-to-room requires a guest.');
            }
            // A checked-out (or never checked-in) guest has no room to charge —
            // reject rather than silently open a stray tab for them.
            $hasActiveStay = TableRegistry::getTableLocator()->get('Reservations')->exists([
                'guest_id' => $guestId,
                'property_id' => $propertyId,
                'status' => 'checked_in',
            ]);
            if (!$hasActiveStay) {
                throw new RuntimeException('Charge-to-room requires the guest to be currently checked in.');
            }
        }

        $paymentMethod = $payload['payment_method'] ?? null;
        if ($paymentStatus === 'paid') {
            if (!in_array($paymentMethod, self::PAYMENT_METHODS, true)) {
                throw new InvalidArgumentException('Pick how the order was paid (cash, GCash, Maya, or GoTyme).');
            }
        } else {
            $paymentMethod = null;
        }

        $totalDiners = max(1, (int)($payload['total_diners'] ?? 1));
        $beneficiaries = [];
        foreach ((array)($payload['discount_beneficiaries'] ?? []) as $raw) {
            $type = $raw['discount_type'] ?? null;
            if (!in_array($type, self::BENEFICIARY_TYPES, true)) {
                throw new InvalidArgumentException('Unknown discount type.');
            }
            $name = trim((string)($raw['name'] ?? ''));
            $idNumber = trim((string)($raw['id_number'] ?? ''));
            if ($name === '' || $idNumber === '') {
                throw new InvalidArgumentException(
                    'Each Senior/PWD discount needs the beneficiary name and ID number.',
                );
            }
            $beneficiaries[] = ['discount_type' => $type, 'name' => $name, 'id_number' => $idNumber];
        }
        if (count($beneficiaries) > $totalDiners) {
            throw new InvalidArgumentException('The number of discount beneficiaries cannot exceed the total diners.');
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
                $paymentMethod,
                $guestId,
                $totalDiners,
                $beneficiaries,
                $cookingCharge,
                $propertyId,
                $receptionistId,
            ): FoodOrder {
                $menus = TableRegistry::getTableLocator()->get('FoodMenuItems');
                $orderItems = TableRegistry::getTableLocator()->get('FoodOrderItems');
                $orderItemOptions = TableRegistry::getTableLocator()->get('FoodOrderItemOptions');
                $orderDiscounts = TableRegistry::getTableLocator()->get('FoodOrderDiscounts');
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
                    'payment_method' => $paymentMethod,
                    'total_diners' => $totalDiners,
                    'cooking_charge' => $cookingCharge,
                    'total' => 0,
                ]);
                $this->saveOrFail($order);

                $subtotal = 0.0;
                foreach ($items as $line) {
                    if (!empty($line['food_menu_item_id'])) {
                        // Menu line: price from the menu, stock from the link + recipe.
                        $menu = $menus->find()
                            ->where([
                                'FoodMenuItems.id' => (int)$line['food_menu_item_id'],
                                'FoodMenuItems.property_id' => $propertyId,
                            ])
                            ->contain([
                                'FoodMenuItemIngredients',
                                'FoodMenuItemOptionGroups' => ['FoodMenuItemOptions'],
                            ])
                            ->firstOrFail();
                        $qty = max(1, (int)($line['quantity'] ?? 1));

                        // Resolve+validate this line's selected options against the
                        // item's own option groups — every `choice` group must have
                        // exactly one pick, `addon` picks are optional (0+ units).
                        $selections = $this->resolveSelectedOptions(
                            $menu->food_menu_item_option_groups,
                            (array)($line['selected_options'] ?? []),
                        );
                        $optionsTotal = array_sum(array_map(
                            fn(array $s): float => (float)$s['option']->price_delta * $s['quantity'],
                            $selections,
                        ));

                        $lineTotal = (float)$menu->price * $qty + $optionsTotal;
                        $subtotal += $lineTotal;

                        $orderItem = $orderItems->newEntity([
                            'food_order_id' => $order->id,
                            'food_menu_item_id' => $menu->id,
                            'quantity' => $qty,
                            'unit_price' => $menu->price,
                            'line_total' => $lineTotal,
                        ]);
                        $orderItems->saveOrFail($orderItem);

                        // Decrement the linked Food Stock (stamped to this receptionist).
                        if ($menu->inventory_item_id) {
                            $item = $inventory->get($menu->inventory_item_id);
                            $stock->record($item, 'out', (float)$qty, $receptionistId, [
                                'reason' => 'food_order',
                                'reference_type' => 'food_order',
                                'reference_id' => $order->id,
                            ]);
                        }

                        // Decrement every recipe ingredient (per-serving qty × ordered qty).
                        foreach ($menu->food_menu_item_ingredients as $ingredient) {
                            $item = $inventory->get($ingredient->inventory_item_id);
                            $stock->record($item, 'out', (float)$ingredient->quantity * $qty, $receptionistId, [
                                'reason' => 'food_order',
                                'reference_type' => 'food_order',
                                'reference_id' => $order->id,
                            ]);
                        }

                        // Decrement each selected option's stock (applies once per
                        // line, not multiplied by $qty — see resolveSelectedOptions)
                        // and snapshot what was picked for the receipt + cancel-time restock.
                        foreach ($selections as $selection) {
                            $option = $selection['option'];
                            if ($option->inventory_item_id) {
                                $item = $inventory->get($option->inventory_item_id);
                                $stock->record($item, 'out', (float)$selection['quantity'], $receptionistId, [
                                    'reason' => 'food_order',
                                    'reference_type' => 'food_order',
                                    'reference_id' => $order->id,
                                ]);
                            }
                            $orderItemOptions->saveOrFail($orderItemOptions->newEntity([
                                'food_order_item_id' => $orderItem->id,
                                'option_id' => $option->id,
                                'label' => $option->label,
                                'price_delta' => $option->price_delta,
                                'quantity' => $selection['quantity'],
                                'inventory_item_id' => $option->inventory_item_id,
                            ]));
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

                // The statutory discount only covers each beneficiary's own
                // even share of the bill: subtotal * (beneficiaries /
                // total_diners) * 20%. Computed in whole cents up front, then
                // handed out evenly across beneficiaries (any leftover cent
                // to the first ones) so their individually-saved `amount`s
                // always sum to exactly this total — no off-by-a-cent gap
                // between the order total and its itemized invoice lines.
                $beneficiaryCount = count($beneficiaries);
                $totalDiscountCents = $beneficiaryCount > 0
                    ? (int)round($subtotal * $beneficiaryCount / $totalDiners * self::STATUTORY_DISCOUNT * 100)
                    : 0;
                $discount = $totalDiscountCents / 100;
                $total = $subtotal - $discount + $cookingCharge;

                $order->set('total', $total);
                $this->saveOrFail($order);

                $savedDiscounts = [];
                if ($beneficiaryCount > 0) {
                    $baseCents = intdiv($totalDiscountCents, $beneficiaryCount);
                    $remainderCents = $totalDiscountCents % $beneficiaryCount;
                    foreach ($beneficiaries as $i => $beneficiary) {
                        $amount = ($baseCents + ($i < $remainderCents ? 1 : 0)) / 100;
                        $savedDiscounts[] = $orderDiscounts->saveOrFail($orderDiscounts->newEntity([
                            'food_order_id' => $order->id,
                            'discount_type' => $beneficiary['discount_type'],
                            'beneficiary_name' => $beneficiary['name'],
                            'id_number' => $beneficiary['id_number'],
                            'amount' => $amount,
                        ]));
                    }
                }

                if ($paymentStatus === 'charge_to_room') {
                    $invoices = TableRegistry::getTableLocator()->get('Invoices');
                    $invoice = $invoices->openInvoiceFor(
                        $propertyId,
                        (int)$guestId,
                        $payload['reservation_id'] ?? null,
                    );
                    // Itemized: the subtotal, then each beneficiary's own
                    // discount as its own negative line, so the folio shows
                    // exactly what was charged and what was taken off for
                    // whom — not a single net figure.
                    $invoices->addLine($invoice, 'Food order #' . $order->id, $subtotal, 'food_order', (int)$order->id);
                    foreach ($savedDiscounts as $d) {
                        $label = $d->discount_type === 'senior' ? 'Senior' : 'PWD';
                        $invoices->addLine(
                            $invoice,
                            sprintf(
                                '%s discount (20%%, 1 of %d diner%s) — %s, ID %s',
                                $label,
                                $totalDiners,
                                $totalDiners === 1 ? '' : 's',
                                $d->beneficiary_name,
                                $d->id_number,
                            ),
                            -(float)$d->amount,
                            'food_order',
                            (int)$order->id,
                        );
                    }
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
     * Resolve+validate a menu line's raw `selected_options` against the item's own
     * option groups. Applies once for the whole order line (not multiplied by the
     * line's quantity) — see the `resolveSelectedOptions` docblock note in `place()`.
     *
     * - A `choice` group's option is forced to quantity 1 regardless of what the
     *   client sent (a "pick one" can't be doubled), and every `choice` group
     *   present on the item must have exactly one option selected.
     * - An `addon` group's options are optional; only positive quantities are kept.
     * - Any `option_id` not belonging to one of this item's own groups is rejected.
     *
     * @param iterable<\App\Model\Entity\FoodMenuItemOptionGroup> $groups
     * @param array<int, array{option_id?: mixed, quantity?: mixed}> $rawSelections
     * @return array<int, array{option: \App\Model\Entity\FoodMenuItemOption, quantity: int}>
     */
    private function resolveSelectedOptions(iterable $groups, array $rawSelections): array
    {
        $optionMeta = []; // optionId => ['option' => entity, 'groupId' => int, 'kind' => string]
        $groupHasOptions = [];
        foreach ($groups as $group) {
            $groupHasOptions[$group->id] = false;
            foreach ($group->food_menu_item_options as $option) {
                $optionMeta[$option->id] = ['option' => $option, 'groupId' => $group->id, 'kind' => $group->kind];
                $groupHasOptions[$group->id] = true;
            }
        }

        $pickedGroups = [];
        $resolved = [];
        foreach ($rawSelections as $raw) {
            $optionId = (int)($raw['option_id'] ?? 0);
            if (!isset($optionMeta[$optionId])) {
                throw new InvalidArgumentException('One of the selected options does not belong to this item.');
            }
            $meta = $optionMeta[$optionId];

            if ($meta['kind'] === 'choice') {
                if (isset($pickedGroups[$meta['groupId']])) {
                    throw new InvalidArgumentException('Only one option can be picked per choice group.');
                }
                $pickedGroups[$meta['groupId']] = true;
                $resolved[] = ['option' => $meta['option'], 'quantity' => 1];
                continue;
            }

            $qty = (int)($raw['quantity'] ?? 1);
            if ($qty < 1) {
                continue;
            }
            $resolved[] = ['option' => $meta['option'], 'quantity' => $qty];
        }

        foreach ($groups as $group) {
            if ($group->kind === 'choice' && $groupHasOptions[$group->id] && !isset($pickedGroups[$group->id])) {
                throw new InvalidArgumentException(sprintf('Please select an option for "%s".', $group->name));
            }
        }

        return $resolved;
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
                    ->contain(['FoodMenuItems' => ['FoodMenuItemIngredients'], 'FoodOrderItemOptions'])
                    ->all();

                foreach ($lines as $line) {
                    if ($line->food_menu_item) {
                        if ($line->food_menu_item->inventory_item_id) {
                            $item = $inventory->get($line->food_menu_item->inventory_item_id);
                            $stock->record($item, 'in', (float)$line->quantity, $receptionistId, [
                                'reason' => 'food_order_cancel',
                                'reference_type' => 'food_order',
                                'reference_id' => $order->id,
                            ]);
                        }
                        foreach ($line->food_menu_item->food_menu_item_ingredients as $ingredient) {
                            $item = $inventory->get($ingredient->inventory_item_id);
                            $restockQty = (float)$ingredient->quantity * (float)$line->quantity;
                            $stock->record($item, 'in', $restockQty, $receptionistId, [
                                'reason' => 'food_order_cancel',
                                'reference_type' => 'food_order',
                                'reference_id' => $order->id,
                            ]);
                        }
                    }

                    // Restock from each selected option's own snapshot — correct
                    // even if the live option/group config has since changed.
                    foreach ($line->food_order_item_options as $selectedOption) {
                        if ($selectedOption->inventory_item_id) {
                            $item = $inventory->get($selectedOption->inventory_item_id);
                            $stock->record($item, 'in', (float)$selectedOption->quantity, $receptionistId, [
                                'reason' => 'food_order_cancel',
                                'reference_type' => 'food_order',
                                'reference_id' => $order->id,
                            ]);
                        }
                    }
                }

                if ($order->payment_status === 'charge_to_room') {
                    TableRegistry::getTableLocator()->get('Invoices')->removeLinesFor('food_order', (int)$order->id);
                }

                $order->set('status', 'cancelled');
                $this->saveOrFail($order);

                return $order;
            },
        );
    }
}
