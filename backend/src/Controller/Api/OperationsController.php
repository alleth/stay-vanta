<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Permissions;
use App\Model\BusinessTime;
use App\Model\Entity\Reservation;
use App\Model\Finance\Collections;
use App\Model\Table\InvoiceEventsTable;
use App\Model\Table\ReservationEventsTable;
use Cake\Http\Exception\BadRequestException;

/**
 * Operations: what is happening at the property right now (display only).
 *
 * GET /api/operations/today and /api/operations/activity. The old
 * /api/reports/operations and /api/reports/activity routes point here too
 * until they're removed (build step 3 compatibility window).
 */
class OperationsController extends AppController
{
    /** How many rows each dashboard list shows; the module behind it has the rest. */
    private const LIST_LIMIT = 8;

    /**
     * Invoice events shown in the activity feed as their own lines (step 6).
     * `opened` and `line_added` stay out: every sale and stay posts them, and
     * the sale lines already show.
     */
    private const FEED_INVOICE_EVENTS = [
        InvoiceEventsTable::SETTLED,
        InvoiceEventsTable::SETTLED_ON_CREATION,
        InvoiceEventsTable::LINE_REVERSED,
        InvoiceEventsTable::LINE_REVERSED_ON_CANCEL,
        InvoiceEventsTable::REFUND_RECORDED,
        InvoiceEventsTable::REFUNDED,
        InvoiceEventsTable::REFUNDED_ON_CANCEL,
    ];

    /**
     * Reservation events shown in the activity feed (build step 8): the
     * lifecycle and discount changes. A plain `edited` stays in the
     * reservation's own history (R5).
     */
    private const FEED_RESERVATION_EVENTS = [
        ReservationEventsTable::BOOKED,
        ReservationEventsTable::WALKED_IN,
        ReservationEventsTable::BACKDATED,
        ReservationEventsTable::CORRECTED,
        ReservationEventsTable::DISCOUNT_CHANGED,
        ReservationEventsTable::CHECKED_IN,
        ReservationEventsTable::CHECKED_OUT,
        ReservationEventsTable::CANCELLED,
        ReservationEventsTable::CANCELLED_AFTER_PAYMENT,
        ReservationEventsTable::DELETED,
    ];

    /** The trailing window for the sales trend, top sellers and most-used stock. */
    private const TREND_DAYS = 7;

    /**
     * What a room needs today, for the Hotel Status map; when a room has
     * several (a guest leaving, the next arriving), the higher rank wins.
     */
    private const ROOM_FLAG_RANK = [
        'arriving' => 1,
        'departing' => 2,
        'late_arrival' => 3,
        'overdue_checkout' => 4,
    ];

    /**
     * GET /api/operations/today  (Manager + Front Desk Staff, own property; old path /api/reports/operations)
     *
     * The operational Dashboard in one call: room status, today's arrivals and
     * departures, POS (food & orders) activity, stock alerts, and the counts
     * behind the computed "Needs attention" panel. `staff` and `activity` are
     * admin-only (null for a receptionist — Staff is an admin module).
     *
     * Everything is aggregated in PHP from plain row fetches, one query per
     * bucket — never `GROUP BY` (ONLY_FULL_GROUP_BY on prod MySQL 8, see
     * CLAUDE.md). A property's daily volume is small enough for that.
     */
    public function today(): void
    {
        $this->authorize(Permissions::OPERATIONS_TODAY_VIEW, 'Only Managers and Front Desk Staff can view Operations.');
        $propertyId = (int)$this->effectivePropertyId();
        $seesStaff = $this->can(Permissions::OPERATIONS_STAFF_VIEW);
        $today = BusinessTime::todayString();
        $dayStart = BusinessTime::startOf($today);
        $dayEnd = BusinessTime::endOf($today);
        $trendFrom = BusinessTime::startOf(
            BusinessTime::today()->subDays(self::TREND_DAYS - 1)->format('Y-m-d'),
        );

        [$rooms, $guests, $reservationAttention] = $this->roomsAndGuests($propertyId, $today, $dayStart, $dayEnd);
        [$pos, $todaysOrders] = $this->posSummary($propertyId, $dayStart, $dayEnd, $trendFrom);
        $inventory = $this->inventorySummary($propertyId, $trendFrom);

        // The one money figure Operations keeps: today's cash movement, from
        // the shared definition (Collections). Everything else about money
        // lives in Finance.
        $today = (new Collections($propertyId))->figures($dayStart, $dayEnd);

        $this->set('operations', [
            'date' => $today,
            'rooms' => $rooms,
            'guests' => $guests,
            'pos' => $pos,
            'revenue_today' => [
                // Cash in, by part; then cash out and Net Collected (step 7c).
                'collected' => $today['collected']['total'],
                'invoices' => $today['collected']['invoices']['total'],
                'pos' => $today['collected']['pos']['total'],
                'refunded' => $today['refunded']['total'],
                'net' => $today['net'],
            ],
            'inventory' => $inventory,
            'staff' => $seesStaff ? $this->staffSummary($propertyId, $dayStart, $dayEnd) : null,
            'activity' => $seesStaff ? $this->recentActivity($propertyId) : null,
            // Operational only — unpaid stays and open invoices are the
            // Revenue page's "To collect".
            'attention' => $reservationAttention + [
                'open_food_orders' => count(array_filter(
                    $todaysOrders,
                    fn($o) => $o->status === 'open',
                )),
                'out_of_stock' => $inventory['out_of_stock_count'],
                'low_stock' => $inventory['low_stock_count'],
                'maintenance_rooms' => $rooms['maintenance'],
            ],
        ]);
        $this->viewBuilder()->setOption('serialize', ['operations']);
    }

    /**
     * Room status counts + today's guest movements.
     *
     * "Reserved" isn't a room status: it's an available room held by a
     * `booked` reservation whose stay covers today (arriving, or a late
     * arrival not yet checked in). `available` excludes those.
     *
     * @return array{0: array, 1: array, 2: array}
     */
    private function roomsAndGuests(int $propertyId, string $today, string $dayStart, string $dayEnd): array
    {
        $roomRows = $this->fetchTable('Rooms')->find()
            ->select(['id', 'room_number', 'room_type', 'status'])
            ->where(['property_id' => $propertyId])
            ->disableHydration()
            ->all();

        $reservations = $this->fetchTable('Reservations');
        $fields = ['Reservations.id', 'Reservations.room_id', 'Reservations.check_in', 'Reservations.check_out',
            'Reservations.status', 'Reservations.source', 'Reservations.total_guests',
            'Reservations.checked_in_at', 'Reservations.checked_out_at',
            'Rooms.id', 'Rooms.room_number', 'Guests.id', 'Guests.full_name'];

        // Everything that holds a room today: in-house stays, and bookings due
        // in by today (future bookings aren't today's business).
        $holding = $reservations->find()
            ->select($fields)
            ->contain(['Rooms', 'Guests'])
            ->where(['Reservations.property_id' => $propertyId])
            ->where(['OR' => [
                ['Reservations.status' => 'checked_in'],
                ['Reservations.status' => 'booked', 'Reservations.check_in <=' => $today],
            ]])
            ->orderBy(['Reservations.check_in' => 'ASC', 'Reservations.id' => 'ASC'])
            ->all()
            ->toList();

        $departedToday = $reservations->find()
            ->select($fields)
            ->contain(['Rooms', 'Guests'])
            ->where([
                'Reservations.property_id' => $propertyId,
                'Reservations.status' => 'checked_out',
                'Reservations.checked_out_at >=' => $dayStart,
                'Reservations.checked_out_at <' => $dayEnd,
            ])
            ->orderBy(['Reservations.checked_out_at' => 'DESC'])
            ->all()
            ->toList();

        $dateOf = fn($d): ?string => $d?->format('Y-m-d');
        $inToday = fn($moment): bool => $moment !== null
            && BusinessTime::stored($moment) >= $dayStart && BusinessTime::stored($moment) < $dayEnd;

        $reservedRoomIds = [];
        // room id → what needs doing there today, most urgent kept.
        $roomFlags = [];
        $flag = function (?int $roomId, string $what) use (&$roomFlags): void {
            $current = $roomFlags[(int)$roomId] ?? null;
            if (
                $roomId !== null
                && ($current === null || self::ROOM_FLAG_RANK[$what] > self::ROOM_FLAG_RANK[$current])
            ) {
                $roomFlags[$roomId] = $what;
            }
        };
        $arrivals = [];
        $departures = [];
        $inHouse = [];
        $lateArrivals = 0;
        $overdueDepartures = 0;
        foreach ($holding as $r) {
            $checkIn = $dateOf($r->check_in);
            $checkOut = $dateOf($r->check_out);
            if ($r->status === 'booked') {
                if ($checkOut === null || $checkOut > $today) {
                    $reservedRoomIds[(int)$r->room_id] = true;
                }
                $late = $checkIn < $today;
                $lateArrivals += $late ? 1 : 0;
                $arrivals[] = $this->guestRow($r, $late ? 'late' : 'due');
                $flag($r->room_id, $late ? 'late_arrival' : 'arriving');
                continue;
            }
            // checked_in
            $inHouse[] = $this->guestRow($r, 'in_house');
            if ($inToday($r->checked_in_at)) {
                $arrivals[] = $this->guestRow($r, 'arrived');
            }
            if ($checkOut !== null && $checkOut <= $today) {
                $overdue = $checkOut < $today;
                $overdueDepartures += $overdue ? 1 : 0;
                $departures[] = $this->guestRow($r, $overdue ? 'overdue' : 'due');
                $flag($r->room_id, $overdue ? 'overdue_checkout' : 'departing');
            }
        }
        foreach ($departedToday as $r) {
            $departures[] = $this->guestRow($r, 'departed');
        }

        $count = ['total' => 0, 'occupied' => 0, 'maintenance' => 0, 'reserved' => 0, 'available' => 0];
        $map = [];
        foreach ($roomRows as $room) {
            if ($room['status'] === 'occupied' || $room['status'] === 'maintenance') {
                $status = $room['status'];
            } elseif (isset($reservedRoomIds[(int)$room['id']])) {
                $status = 'reserved';
            } else {
                $status = 'available';
            }
            $count['total']++;
            $count[$status]++;
            $map[] = [
                'id' => (int)$room['id'],
                'number' => (string)$room['room_number'],
                'type' => $room['room_type'],
                'status' => $status,
                'flag' => $roomFlags[(int)$room['id']] ?? null,
            ];
        }
        usort($map, fn($a, $b) => strnatcasecmp($a['number'], $b['number']));
        $count['map'] = $map;
        // Same definition as Front Desk's Rooms card: occupied over all rooms.
        $count['occupancy_rate'] = $count['total'] > 0
            ? round($count['occupied'] / $count['total'] * 100, 1)
            : 0.0;

        $pending = fn(array $rows, array $states) => count(array_filter(
            $rows,
            fn($row) => in_array($row['state'], $states, true),
        ));

        $newBookings = $this->fetchTable('Reservations')->find()->where([
            'property_id' => $propertyId,
            'source !=' => 'walk_in',
            'created >=' => $dayStart,
            'created <' => $dayEnd,
        ])->count();

        $guests = [
            'arrivals' => [
                'total' => count($arrivals),
                'done' => $pending($arrivals, ['arrived']),
                'rows' => array_slice($arrivals, 0, self::LIST_LIMIT),
            ],
            'departures' => [
                'total' => count($departures),
                'done' => $pending($departures, ['departed']),
                'rows' => array_slice($departures, 0, self::LIST_LIMIT),
            ],
            'in_house' => [
                'total' => count($inHouse),
                'guests' => array_sum(array_map(fn($r) => $r['guests'], $inHouse)),
                'rows' => array_slice($inHouse, 0, self::LIST_LIMIT),
            ],
        ];

        $attention = [
            'arrivals_pending' => $pending($arrivals, ['due', 'late']),
            'late_arrivals' => $lateArrivals,
            'departures_pending' => $pending($departures, ['due', 'overdue']),
            'overdue_departures' => $overdueDepartures,
            'new_bookings' => $newBookings,
        ];

        return [$count, $guests, $attention];
    }

    /**
     * One reservation as a dashboard list row.
     */
    private function guestRow(Reservation $r, string $state): array
    {
        return [
            'id' => (int)$r->id,
            'room' => $r->room?->room_number,
            'guest' => $r->guest?->full_name,
            'guests' => (int)$r->total_guests,
            'source' => (string)$r->source,
            'check_in' => $r->check_in?->format('Y-m-d'),
            'check_out' => $r->check_out?->format('Y-m-d'),
            'state' => $state,
        ];
    }

    /**
     * Food & Orders (the POS) for today plus a trailing trend. "Sales" here is
     * the same as the collection report's food figure: `paid` orders. Food
     * charged to a room is shown beside it — it's collected when the invoice
     * is settled, so it isn't added in.
     *
     * @return array{0: array, 1: list<\App\Model\Entity\FoodOrder>}
     */
    private function posSummary(
        int $propertyId,
        string $dayStart,
        string $dayEnd,
        string $trendFrom,
    ): array {
        $orders = $this->fetchTable('FoodOrders');
        $todaysOrders = $orders->find()
            ->select(['id', 'status', 'payment_status', 'total'])
            ->where([
                'property_id' => $propertyId,
                'status !=' => 'cancelled',
                'created >=' => $dayStart,
                'created <' => $dayEnd,
            ])
            ->all()
            ->toList();

        $sum = fn(array $rows, string $payment) => round(array_sum(array_map(
            fn($o) => $o->payment_status === $payment ? (float)$o->total : 0.0,
            $rows,
        )), 2);

        // Paid sales per day net of POS refunds, oldest first (Collections'
        // POS parts; a few queries per day, no GROUP BY DATE(...)).
        $money = new Collections($propertyId);
        $trend = [];
        for ($i = self::TREND_DAYS - 1; $i >= 0; $i--) {
            $date = BusinessTime::today()->subDays($i)->format('Y-m-d');
            $figures = $money->figuresOn($date);
            $trend[] = [
                'date' => $date,
                'total' => round($figures['collected']['pos']['total'] - $figures['refunded']['pos']['total'], 2),
            ];
        }

        // Top sellers over the trend window: menu items only — a custom line
        // is one-off by definition, so it can't be a best seller.
        $lines = $this->fetchTable('FoodOrderItems')->find()
            ->select(['FoodOrderItems.food_menu_item_id', 'FoodOrderItems.quantity',
                'FoodOrderItems.line_total', 'FoodMenuItems.id', 'FoodMenuItems.name'])
            ->contain(['FoodMenuItems'])
            ->innerJoinWith('FoodOrders')
            ->where([
                'FoodOrders.property_id' => $propertyId,
                'FoodOrders.status !=' => 'cancelled',
                'FoodOrders.created >=' => $trendFrom,
                'FoodOrderItems.food_menu_item_id IS NOT' => null,
            ])
            ->all();
        $sellers = [];
        foreach ($lines as $line) {
            $id = (int)$line->food_menu_item_id;
            $sellers[$id] ??= [
                'name' => $line->food_menu_item?->name ?? 'Removed item',
                'quantity' => 0,
                'total' => 0.0,
            ];
            $sellers[$id]['quantity'] += (int)$line->quantity;
            $sellers[$id]['total'] += (float)$line->line_total;
        }
        usort($sellers, fn($a, $b) => [$b['quantity'], $b['total']] <=> [$a['quantity'], $a['total']]);
        $topSellers = array_map(
            fn($s) => ['name' => $s['name'], 'quantity' => $s['quantity'], 'total' => round($s['total'], 2)],
            array_slice($sellers, 0, 5),
        );

        $recent = $orders->find()
            ->select(['FoodOrders.id', 'FoodOrders.status', 'FoodOrders.payment_status',
                'FoodOrders.total', 'FoodOrders.created',
                'Rooms.id', 'Rooms.room_number', 'Guests.id', 'Guests.full_name'])
            ->contain(['Rooms', 'Guests'])
            ->where(['FoodOrders.property_id' => $propertyId])
            ->orderBy(['FoodOrders.created' => 'DESC', 'FoodOrders.id' => 'DESC'])
            ->limit(6)
            ->all()
            ->map(fn($o) => [
                'id' => (int)$o->id,
                'status' => $o->status,
                'payment_status' => $o->payment_status,
                'total' => round((float)$o->total, 2),
                'created' => $o->created,
                'room' => $o->room?->room_number,
                'guest' => $o->guest?->full_name,
            ])
            ->toList();

        $yesterday = $trend[count($trend) - 2]['total'] ?? 0.0;

        return [[
            'today' => [
                'orders' => count($todaysOrders),
                // The trend's today, not $sum(): every order paid today, as
                // the collection report counts it, so the two never disagree.
                'paid' => $trend[count($trend) - 1]['total'],
                'charged_to_room' => $sum($todaysOrders, 'charge_to_room'),
                'unpaid' => $sum($todaysOrders, 'unpaid'),
            ],
            'yesterday_paid' => $yesterday,
            'trend' => $trend,
            'top_sellers' => $topSellers,
            'recent' => $recent,
        ], $todaysOrders];
    }

    /**
     * Stock alerts + what's moving. Out of stock = nothing on the shelf; low
     * = at or under its reorder level (the Inventory page's rule). A parent
     * item with sub-items is a container — its sub-items carry the stock — so
     * it's left out of the alerts.
     */
    private function inventorySummary(int $propertyId, string $trendFrom): array
    {
        $items = $this->fetchTable('InventoryItems');
        $query = $items->find();
        $rows = $query
            ->select([
                'InventoryItems.id', 'InventoryItems.name', 'InventoryItems.unit',
                'InventoryItems.quantity', 'InventoryItems.reorder_level',
                'has_children' => $query->expr(
                    'EXISTS (SELECT 1 FROM inventory_items ci'
                    . ' WHERE ci.parent_id = InventoryItems.id AND ci.deleted_at IS NULL)',
                ),
            ])
            ->where(['InventoryItems.property_id' => $propertyId, 'InventoryItems.deleted_at IS' => null])
            ->disableHydration()
            ->all();

        $out = [];
        $low = [];
        foreach ($rows as $row) {
            if ((bool)$row['has_children']) {
                continue;
            }
            $qty = (float)$row['quantity'];
            $reorder = (float)$row['reorder_level'];
            $entry = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'unit' => $row['unit'],
                'quantity' => $qty,
                'reorder_level' => $reorder,
            ];
            if ($qty <= 0) {
                $out[] = $entry;
            } elseif ($qty <= $reorder) {
                $low[] = $entry;
            }
        }
        // Closest to empty first, relative to what the item should hold.
        usort($low, fn($a, $b) => $a['quantity'] / $a['reorder_level'] <=> $b['quantity'] / $b['reorder_level']);
        usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));

        $movements = $this->fetchTable('StockMovements')->find()
            ->select(['StockMovements.inventory_item_id', 'StockMovements.quantity',
                'InventoryItems.name', 'InventoryItems.unit'])
            ->contain(['InventoryItems'])
            ->where([
                'StockMovements.property_id' => $propertyId,
                'StockMovements.direction' => 'out',
                'StockMovements.created >=' => $trendFrom,
            ])
            ->all();
        $used = [];
        foreach ($movements as $m) {
            $id = (int)$m->inventory_item_id;
            $used[$id] ??= [
                'name' => $m->inventory_item?->name,
                'unit' => $m->inventory_item?->unit,
                'quantity' => 0.0,
            ];
            $used[$id]['quantity'] += (float)$m->quantity;
        }
        usort($used, fn($a, $b) => $b['quantity'] <=> $a['quantity']);

        return [
            'items' => count($rows),
            'out_of_stock_count' => count($out),
            'low_stock_count' => count($low),
            'out_of_stock' => array_slice($out, 0, 5),
            'low_stock' => array_slice($low, 0, 5),
            'most_used' => array_slice($used, 0, 5),
        ];
    }

    /**
     * Active staff accounts and how many actions each took today: the
     * distinct requests they made that recorded anything in any ledger
     * (activity_index: stock, POS sales, invoices and, since step 8,
     * reservations). One action is one request, however many events it
     * wrote: a sale that moves three stock items, or a check-out that posts
     * its room charge, counts once. Imported history has no actor and never
     * counts.
     */
    private function staffSummary(int $propertyId, string $dayStart, string $dayEnd): array
    {
        $users = $this->fetchTable('Users')->find()
            ->select(['id', 'name', 'role'])
            ->where(['property_id' => $propertyId, 'is_active' => true, 'role IN' => ['admin', 'receptionist']])
            ->orderBy(['role' => 'ASC', 'name' => 'ASC'])
            ->disableHydration()
            ->all();

        $requests = [];
        $rows = $this->fetchTable('ActivityIndex')->find()
            ->select(['actor_id', 'correlation_id'])
            ->where([
                'property_id' => $propertyId,
                'actor_id IS NOT' => null,
                'occurred_at >=' => $dayStart,
                'occurred_at <' => $dayEnd,
            ])
            ->disableHydration()
            ->all();
        foreach ($rows as $row) {
            $requests[(int)$row['actor_id']][(string)$row['correlation_id']] = true;
        }
        $actions = array_map('count', $requests);

        $list = [];
        foreach ($users as $u) {
            $list[] = [
                'id' => (int)$u['id'],
                'name' => $u['name'],
                'role' => $u['role'],
                'actions_today' => $actions[(int)$u['id']] ?? 0,
            ];
        }

        return [
            'active' => count($list),
            'receptionists' => count(array_filter($list, fn($u) => $u['role'] === 'receptionist')),
            'active_today' => count(array_filter($list, fn($u) => $u['actions_today'] > 0)),
            'members' => $list,
        ];
    }

    /** Page size and deepest page for GET /operations/activity. */
    private const ACTIVITY_PAGE_SIZE = 25;
    private const ACTIVITY_MAX_PAGE = 40;

    /**
     * GET /api/operations/activity[?page=N]  (Manager only, own property; old path /api/reports/activity)
     *
     * The whole activity feed behind the Dashboard's Staff card ("View all
     * activity"), newest first, 25 per page → {activity, page, has_more}.
     */
    public function activity(): void
    {
        $this->authorize(Permissions::OPERATIONS_STAFF_VIEW, 'Only a Manager can view staff activity.');
        $page = (int)($this->request->getQuery('page') ?? 1);
        if ($page < 1 || $page > self::ACTIVITY_MAX_PAGE) {
            throw new BadRequestException(sprintf('page must be 1-%d.', self::ACTIVITY_MAX_PAGE));
        }
        // One more than a page, so we know whether another page exists.
        $events = $this->recentActivity(
            (int)$this->effectivePropertyId(),
            self::ACTIVITY_PAGE_SIZE + 1,
            ($page - 1) * self::ACTIVITY_PAGE_SIZE,
        );

        $this->set([
            'activity' => array_slice($events, 0, self::ACTIVITY_PAGE_SIZE),
            'page' => $page,
            'has_more' => count($events) > self::ACTIVITY_PAGE_SIZE,
        ]);
        $this->viewBuilder()->setOption('serialize', ['activity', 'page', 'has_more']);
    }

    /**
     * Ledgered actions newest first, from activity_index (build step 5): every
     * stock movement, and each sale once (its `placed` event). The index
     * decides which lines exist and their order; what each line shows is read
     * now, as the feed always has (the item's and person's current names, the
     * sale's current status and total), so it looks exactly as before. The
     * moment-in-time summaries in the index are kept for the event feed that
     * steps 6-8 bring.
     *
     * At the same instant, stock lines come before sale lines and later ids
     * first, which is the order the earlier two-table merge produced.
     *
     * @return list<array<string, mixed>>
     */
    private function recentActivity(int $propertyId, int $limit = self::LIST_LIMIT, int $offset = 0): array
    {
        $rows = $this->fetchTable('ActivityIndex')->find()
            ->select(['event_table', 'event_type', 'event_id', 'subject_id'])
            ->where([
                'property_id' => $propertyId,
                'OR' => [
                    ['event_table' => 'stock_movements'],
                    ['event_table' => 'food_order_events', 'event_type IN' => ['placed', 'refunded']],
                    ['event_table' => 'invoice_events', 'event_type IN' => self::FEED_INVOICE_EVENTS],
                    ['event_table' => 'reservation_events', 'event_type IN' => self::FEED_RESERVATION_EVENTS],
                ],
            ])
            ->orderBy(['occurred_at' => 'DESC'])
            ->orderBy(fn($exp, $q) => $q->expr(
                "CASE event_table WHEN 'stock_movements' THEN 0 WHEN 'food_order_events' THEN 1 ELSE 2 END",
            ))
            // Later movement first, later sale first (by order id: a sale's
            // placed event may be backfilled after a newer sale recorded live).
            ->orderBy(fn($exp, $q) => $q->expr(
                "CASE event_table WHEN 'stock_movements' THEN event_id ELSE subject_id END DESC",
            ))
            ->orderBy(['id' => 'DESC'])
            ->limit($limit)
            ->offset($offset)
            ->disableHydration()
            ->all()
            ->toList();

        $movementIds = [];
        $orderIds = [];
        $invoiceEventIds = [];
        $saleRefundIds = [];
        $reservationEventIds = [];
        foreach ($rows as $row) {
            if ($row['event_table'] === 'reservation_events') {
                $reservationEventIds[] = (int)$row['event_id'];
                continue;
            }
            if ($row['event_table'] === 'stock_movements') {
                $movementIds[] = (int)$row['event_id'];
            } elseif ($row['event_table'] === 'food_order_events' && $row['event_type'] === 'refunded') {
                $saleRefundIds[] = (int)$row['event_id'];
            } elseif ($row['event_table'] === 'invoice_events') {
                $invoiceEventIds[] = (int)$row['event_id'];
            } else {
                $orderIds[] = (int)$row['subject_id'];
            }
        }
        $invoiceLines = $this->invoiceFeedLines($invoiceEventIds);
        $saleRefundLines = $this->saleRefundFeedLines($saleRefundIds);
        $reservationLines = $this->reservationFeedLines($reservationEventIds);
        $movements = $movementIds === [] ? [] : $this->fetchTable('StockMovements')->find()
            ->contain([
                'InventoryItems' => ['fields' => ['id', 'name', 'unit']],
                'Receptionist' => self::USER_BRIEF,
            ])
            ->where(['StockMovements.id IN' => $movementIds])
            ->all()
            ->indexBy('id')
            ->toArray();
        $orders = $orderIds === [] ? [] : $this->fetchTable('FoodOrders')->find()
            ->contain(['Rooms' => ['fields' => ['id', 'room_number']], 'Receptionist' => self::USER_BRIEF])
            ->where(['FoodOrders.id IN' => $orderIds])
            ->all()
            ->indexBy('id')
            ->toArray();

        $events = [];
        foreach ($rows as $row) {
            if ($row['event_table'] === 'stock_movements') {
                $m = $movements[(int)$row['event_id']] ?? null;
                if ($m !== null) {
                    $events[] = [
                        'type' => 'stock',
                        'id' => 'stock-' . $m->id,
                        'at' => $m->created,
                        'actor' => $m->receptionist?->name,
                        'direction' => $m->direction,
                        'quantity' => (float)$m->quantity,
                        'item' => $m->inventory_item?->name,
                        'unit' => $m->inventory_item?->unit,
                        'reason' => $m->reason,
                    ];
                }
                continue;
            }
            if ($row['event_table'] === 'reservation_events') {
                if (isset($reservationLines[(int)$row['event_id']])) {
                    $events[] = $reservationLines[(int)$row['event_id']];
                }
                continue;
            }
            if ($row['event_table'] === 'food_order_events' && $row['event_type'] === 'refunded') {
                if (isset($saleRefundLines[(int)$row['event_id']])) {
                    $events[] = $saleRefundLines[(int)$row['event_id']];
                }
                continue;
            }
            if ($row['event_table'] === 'invoice_events') {
                if (isset($invoiceLines[(int)$row['event_id']])) {
                    $events[] = $invoiceLines[(int)$row['event_id']];
                }
                continue;
            }
            $o = $orders[(int)$row['subject_id']] ?? null;
            if ($o !== null) {
                $events[] = [
                    'type' => 'order',
                    'id' => 'order-' . $o->id,
                    'at' => $o->created,
                    'actor' => $o->receptionist?->name,
                    'order_id' => (int)$o->id,
                    'total' => round((float)$o->total, 2),
                    'room' => $o->room?->room_number,
                    'payment_status' => $o->payment_status,
                    'status' => $o->status,
                ];
            }
        }

        return $events;
    }

    /**
     * Feed lines for invoice events (build step 6: the feed starts showing
     * business events as their own lines). Unlike stock and sale lines, these
     * show the event as it happened: its time (`occurred_at`), who did it,
     * its amount, receipt numbers and reason. An event imported from before
     * step 6 has no recorded actor: `actor` is null and `recorded` false, so
     * the screen can say so rather than guess. The guest and the invoice's
     * total are read now.
     *
     * @param list<int> $eventIds invoice_events ids on this page.
     * @return array<int, array<string, mixed>> Feed line per event id.
     */
    private function invoiceFeedLines(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $events = $this->fetchTable('InvoiceEvents')->find()->where(['id IN' => $eventIds])->all()->toList();
        $actorIds = array_values(array_unique(array_filter(array_map(fn($e) => $e->actor_id, $events))));
        $actors = $actorIds === [] ? [] : $this->fetchTable('Users')->find()
            ->select(['id', 'name'])->where(['id IN' => $actorIds])->all()->combine('id', 'name')->toArray();
        $invoiceIds = array_values(array_unique(array_map(fn($e) => (int)$e->invoice_id, $events)));
        $invoices = $this->fetchTable('Invoices')->find()
            ->contain(['Guests' => ['fields' => ['id', 'full_name']]])
            ->where(['Invoices.id IN' => $invoiceIds])->all()->indexBy('id')->toArray();

        $lines = [];
        foreach ($events as $e) {
            $invoice = $invoices[(int)$e->invoice_id] ?? null;
            $lines[(int)$e->id] = [
                'type' => 'invoice',
                'id' => 'invoice-event-' . $e->id,
                'at' => $e->occurred_at,
                'actor' => $e->actor_id !== null ? ($actors[$e->actor_id] ?? null) : null,
                'recorded' => $e->source !== 'import',
                'event' => $e->event_type,
                'invoice_id' => (int)$e->invoice_id,
                'amount' => $e->amount !== null ? round((float)$e->amount, 2) : null,
                'total' => $invoice !== null ? round((float)$invoice->total, 2) : null,
                'guest' => $invoice?->guest?->full_name,
                'invoice_number' => $e->invoice_number,
                'or_number' => $e->or_number,
                'line' => $e->changes['reverses']['description'] ?? null,
                'reason' => $e->reason,
                'method' => $e->method,
            ];
        }

        return $lines;
    }

    /**
     * Feed lines for POS refunds (build step 7c): money returned for a
     * cancelled paid sale, as it happened: when, who, how much, how, why.
     *
     * @param list<int> $eventIds food_order_events ids (refunded) on this page.
     * @return array<int, array<string, mixed>> Feed line per event id.
     */
    private function saleRefundFeedLines(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $events = $this->fetchTable('FoodOrderEvents')->find()->where(['id IN' => $eventIds])->all()->toList();
        $actorIds = array_values(array_unique(array_filter(array_map(fn($e) => $e->actor_id, $events))));
        $actors = $actorIds === [] ? [] : $this->fetchTable('Users')->find()
            ->select(['id', 'name'])->where(['id IN' => $actorIds])->all()->combine('id', 'name')->toArray();

        $lines = [];
        foreach ($events as $e) {
            $lines[(int)$e->id] = [
                'type' => 'sale_refund',
                'id' => 'sale-event-' . $e->id,
                'at' => $e->occurred_at,
                'actor' => $e->actor_id !== null ? ($actors[$e->actor_id] ?? null) : null,
                'order_id' => (int)$e->food_order_id,
                'amount' => round(-(float)$e->amount, 2),
                'method' => $e->method,
                'reason' => $e->reason,
            ];
        }

        return $lines;
    }

    /**
     * Feed lines for reservation events (build step 8): what happened, when,
     * who (null and `recorded: false` for history imported from before step
     * 8, never a guess), the guest and room as the event recorded them, the
     * reason, and for a discount change which discounts changed.
     *
     * @param list<int> $eventIds reservation_events ids on this page.
     * @return array<int, array<string, mixed>> Feed line per event id.
     */
    private function reservationFeedLines(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $events = $this->fetchTable('ReservationEvents')->find()->where(['id IN' => $eventIds])->all()->toList();
        $actorIds = array_values(array_unique(array_filter(array_map(fn($e) => $e->actor_id, $events))));
        $actors = $actorIds === [] ? [] : $this->fetchTable('Users')->find()
            ->select(['id', 'name'])->where(['id IN' => $actorIds])->all()->combine('id', 'name')->toArray();

        $lines = [];
        foreach ($events as $e) {
            $snapshot = (array)$e->snapshot;
            $changes = (array)$e->changes;
            $lines[(int)$e->id] = [
                'type' => 'reservation',
                'id' => 'reservation-event-' . $e->id,
                'at' => $e->occurred_at,
                'actor' => $e->actor_id !== null ? ($actors[$e->actor_id] ?? null) : null,
                'recorded' => $e->source !== 'import',
                'event' => $e->event_type,
                'reservation_id' => (int)$e->reservation_id,
                'guest' => $snapshot['guest_name'] ?? null,
                'room' => $snapshot['room'] ?? $snapshot['room_at_import'] ?? null,
                'check_in' => $snapshot['check_in'] ?? null,
                'check_out' => $snapshot['check_out'] ?? null,
                'reason' => $e->reason,
                'backdated_entry' => !empty($changes['backdated_entry']),
                'changed' => $e->event_type === ReservationEventsTable::DISCOUNT_CHANGED
                    ? array_keys($changes) : null,
            ];
        }

        return $lines;
    }
}
