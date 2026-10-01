<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Model\BusinessTime;
use App\Model\Entity\Reservation;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;

/**
 * Role dashboards.
 *
 * The owner runs the StayVanta platform (no property of their own) and sees
 * subscription revenue + active-user counts. An admin runs one hotel/resort and
 * sees that property's operational figures + collected revenue.
 */
class ReportsController extends AppController
{
    /**
     * GET /api/reports/owner-dashboard  (owner only)
     *
     * Subscription revenue (from each subscriber's monthly fee) plus the
     * active-user counts: registered hotels/resorts and registered admins.
     */
    public function ownerDashboard(): void
    {
        if (!$this->userHasRole('owner')) {
            throw new ForbiddenException('Only the platform owner can view this dashboard.');
        }

        $properties = $this->fetchTable('Properties')->find()->all();
        $active = 0;
        $inactive = 0;
        $monthlyRecurring = 0.0;
        foreach ($properties as $property) {
            if ($property->subscription_active) {
                $active++;
                $monthlyRecurring += (float)$property->subscription_fee;
            } else {
                $inactive++;
            }
        }

        $admins = $this->fetchTable('Users')
            ->find()
            ->where(['role' => 'admin', 'is_active' => true])
            ->count();

        // Project the recurring monthly fee onto each period.
        $monthsElapsed = (int)BusinessTime::now()->format('n'); // Jan = 1 ... current month

        $this->set('dashboard', [
            'counts' => [
                'hotels' => $active + $inactive,
                'active_subscriptions' => $active,
                'inactive_subscriptions' => $inactive,
                'admins' => $admins,
            ],
            'revenue' => [
                'monthly_recurring' => round($monthlyRecurring, 2),
                'week' => round($monthlyRecurring * 12 / 52, 2),
                'month' => round($monthlyRecurring, 2),
                'ytd' => round($monthlyRecurring * $monthsElapsed, 2),
            ],
        ]);
        $this->viewBuilder()->setOption('serialize', ['dashboard']);
    }

    /**
     * GET /api/reports/admin-dashboard  (admin only)
     *
     * Operational cards + collected revenue for the admin's own property.
     */
    public function adminDashboard(): void
    {
        if (!$this->userHasRole('admin')) {
            throw new ForbiddenException('Only a hotel/resort admin can view this dashboard.');
        }
        $propertyId = (int)$this->currentUser->property_id;

        $inventoryItems = $this->fetchTable('InventoryItems')
            ->find()->where(['property_id' => $propertyId])->count();

        $occupiedRooms = $this->fetchTable('Rooms')
            ->find()->where(['property_id' => $propertyId, 'status' => 'occupied'])->count();

        $guestsToday = $this->countDistinct(
            $this->fetchTable('Reservations')
                ->find()
                ->where(['property_id' => $propertyId, 'status' => 'checked_in', 'guest_id IS NOT' => null]),
            'guest_id',
        );

        $openFoodOrders = $this->fetchTable('FoodOrders')
            ->find()->where(['property_id' => $propertyId, 'status' => 'open'])->count();

        // Week/month/year as the hotel counts them, not as UTC does.
        $now = BusinessTime::now();
        $ranges = [
            'week' => BusinessTime::stored($now->startOfWeek()),
            'month' => BusinessTime::stored($now->startOfMonth()),
            'ytd' => BusinessTime::stored($now->startOfYear()),
            'all_time' => null,
        ];

        $invoices = $this->fetchTable('Invoices');
        $foodOrders = $this->fetchTable('FoodOrders');
        $revenue = [];
        foreach ($ranges as $key => $from) {
            // Collected = settled invoices (room + charged food) + paid standalone
            // food orders. Charge-to-room food already lives inside invoices, so
            // only `paid` food orders are added here (no double counting).
            $inv = $invoices->find()
                ->where(['property_id' => $propertyId, 'status' => 'settled']);
            $food = $foodOrders->find()
                ->where(['property_id' => $propertyId, 'payment_status' => 'paid']);
            if ($from !== null) {
                $inv->where(['settled_at >=' => $from]);
                $food->where(['created >=' => $from]);
            }
            $invTotal = (float)$inv->select(['s' => $inv->func()->sum('total')])->first()->s;
            $foodTotal = (float)$food->select(['s' => $food->func()->sum('total')])->first()->s;
            $revenue[$key] = round($invTotal + $foodTotal, 2);
        }

        $this->set('dashboard', [
            'cards' => [
                'inventory_items' => $inventoryItems,
                'occupied_rooms' => $occupiedRooms,
                'guests_today' => $guestsToday,
                'open_food_orders' => $openFoodOrders,
            ],
            'revenue' => $revenue,
            'outstanding' => $this->outstanding($propertyId),
        ]);
        $this->viewBuilder()->setOption('serialize', ['dashboard']);
    }

    /** How many rows each dashboard list shows; the module behind it has the rest. */
    private const LIST_LIMIT = 8;

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
     * GET /api/reports/operations  (admin + receptionist, own property)
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
    public function operations(): void
    {
        if (!$this->userHasRole('admin', 'receptionist')) {
            throw new ForbiddenException('Only hotel/resort staff can view the operations dashboard.');
        }
        $propertyId = (int)$this->currentUser->property_id;
        $isAdmin = $this->userHasRole('admin');
        $today = BusinessTime::todayString();
        $dayStart = BusinessTime::startOf($today);
        $dayEnd = BusinessTime::endOf($today);
        $trendFrom = BusinessTime::startOf(
            BusinessTime::today()->subDays(self::TREND_DAYS - 1)->format('Y-m-d'),
        );

        [$rooms, $guests, $reservationAttention] = $this->roomsAndGuests($propertyId, $today, $dayStart, $dayEnd);
        [$pos, $todaysOrders] = $this->posSummary($propertyId, $dayStart, $dayEnd, $trendFrom);
        $inventory = $this->inventorySummary($propertyId, $trendFrom);

        $unpaid = $this->fetchTable('Reservations')->find()->where([
            'property_id' => $propertyId,
            'status !=' => 'cancelled',
            'payment_status' => 'unpaid',
        ])->count();

        $this->set('operations', [
            'date' => $today,
            'rooms' => $rooms,
            'guests' => $guests,
            'pos' => $pos,
            'inventory' => $inventory,
            'staff' => $isAdmin ? $this->staffSummary($propertyId, $dayStart, $dayEnd) : null,
            'activity' => $isAdmin ? $this->recentActivity($propertyId) : null,
            'attention' => $reservationAttention + [
                'unpaid_reservations' => $unpaid,
                'open_invoices' => $this->outstanding($propertyId),
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

        // One query per day, oldest first (no GROUP BY DATE(...)).
        $trend = [];
        for ($i = self::TREND_DAYS - 1; $i >= 0; $i--) {
            $date = BusinessTime::today()->subDays($i)->format('Y-m-d');
            $q = $orders->find()->where([
                'property_id' => $propertyId,
                'payment_status' => 'paid',
                'created >=' => BusinessTime::startOf($date),
                'created <' => BusinessTime::endOf($date),
            ]);
            $trend[] = [
                'date' => $date,
                'total' => round((float)$q->select(['s' => $q->func()->sum('total')])->first()->s, 2),
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
     * Active staff accounts and how many ledgered actions each took today.
     * Only rows that carry a true per-action actor count: stock movements and
     * food orders. (A reservation's receptionist_id is re-stamped on every
     * edit and transition, so it can't say who did what, when.)
     */
    private function staffSummary(int $propertyId, string $dayStart, string $dayEnd): array
    {
        $users = $this->fetchTable('Users')->find()
            ->select(['id', 'name', 'role'])
            ->where(['property_id' => $propertyId, 'is_active' => true, 'role IN' => ['admin', 'receptionist']])
            ->orderBy(['role' => 'ASC', 'name' => 'ASC'])
            ->disableHydration()
            ->all();

        $actions = [];
        foreach (['StockMovements', 'FoodOrders'] as $table) {
            $rows = $this->fetchTable($table)->find()
                ->select(['receptionist_id'])
                ->where(['property_id' => $propertyId, 'created >=' => $dayStart, 'created <' => $dayEnd])
                ->disableHydration()
                ->all();
            foreach ($rows as $row) {
                $id = (int)$row['receptionist_id'];
                $actions[$id] = ($actions[$id] ?? 0) + 1;
            }
        }

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

    /** Page size and deepest page for GET /reports/activity. */
    private const ACTIVITY_PAGE_SIZE = 25;
    private const ACTIVITY_MAX_PAGE = 40;

    /**
     * GET /api/reports/activity[?page=N]  (admin only, own property)
     *
     * The whole activity feed behind the Dashboard's Staff card ("View all
     * activity"), newest first, 25 per page → {activity, page, has_more}.
     *
     * Two ledgers merged by time can't be paged with one OFFSET, so each is
     * read up to the end of the requested page and the merge is sliced — the
     * cost grows with depth, hence the page cap.
     */
    public function activity(): void
    {
        if (!$this->userHasRole('admin')) {
            throw new ForbiddenException('Only a hotel/resort admin can view staff activity.');
        }
        $page = (int)($this->request->getQuery('page') ?? 1);
        if ($page < 1 || $page > self::ACTIVITY_MAX_PAGE) {
            throw new BadRequestException(sprintf('page must be 1-%d.', self::ACTIVITY_MAX_PAGE));
        }
        $end = $page * self::ACTIVITY_PAGE_SIZE;
        // One past the page's end, so we know whether another page exists.
        $events = $this->recentActivity((int)$this->currentUser->property_id, $end + 1);

        $this->set([
            'activity' => array_slice($events, $end - self::ACTIVITY_PAGE_SIZE, self::ACTIVITY_PAGE_SIZE),
            'page' => $page,
            'has_more' => count($events) > $end,
        ]);
        $this->viewBuilder()->setOption('serialize', ['activity', 'page', 'has_more']);
    }

    /**
     * The latest `$limit` ledgered actions, newest first: stock movements and
     * food orders, each with the person who took it. Reading `$limit` from
     * each ledger guarantees the merged top `$limit` is exact.
     */
    private function recentActivity(int $propertyId, int $limit = self::LIST_LIMIT): array
    {
        $events = [];
        $movements = $this->fetchTable('StockMovements')->find()
            ->contain([
                'InventoryItems' => ['fields' => ['id', 'name', 'unit']],
                'Receptionist' => ['fields' => ['id', 'name']],
            ])
            ->where(['StockMovements.property_id' => $propertyId])
            ->orderBy(['StockMovements.created' => 'DESC', 'StockMovements.id' => 'DESC'])
            ->limit($limit)
            ->all();
        foreach ($movements as $m) {
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

        $orders = $this->fetchTable('FoodOrders')->find()
            ->contain(['Rooms' => ['fields' => ['id', 'room_number']], 'Receptionist' => ['fields' => ['id', 'name']]])
            ->where(['FoodOrders.property_id' => $propertyId])
            ->orderBy(['FoodOrders.created' => 'DESC', 'FoodOrders.id' => 'DESC'])
            ->limit($limit)
            ->all();
        foreach ($orders as $o) {
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

        usort($events, fn($a, $b) => (string)$b['at']?->format('c') <=> (string)$a['at']?->format('c'));

        return array_slice($events, 0, $limit);
    }

    /**
     * GET /api/reports/daily-collection[?date=YYYY-MM-DD | ?month=&year= | ?from=&to=]
     *
     * Money collected in the window: settled invoices (by settled_at — room
     * charges, downpayments net of refunds, charged food) + paid standalone
     * food orders. Defaults to today. The month+year and from/to forms are
     * owner/admin only — a receptionist may only view a single day's
     * collection.
     */
    public function dailyCollection(): void
    {
        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        $rangeFrom = $this->request->getQuery('from');
        $rangeTo = $this->request->getQuery('to');
        $month = $this->request->getQuery('month');
        $year = $this->request->getQuery('year');
        $isWideWindow = $rangeFrom !== null || $rangeTo !== null || $month !== null || $year !== null;

        if ($isWideWindow && !$this->userHasRole('owner', 'admin')) {
            throw new ForbiddenException('Receptionists can view the daily collection only.');
        }

        if ($rangeFrom !== null || $rangeTo !== null) {
            $rangeFrom = $this->assertDateFormat($rangeFrom, 'from');
            $rangeTo = $this->assertDateFormat($rangeTo, 'to');
            if ($rangeTo < $rangeFrom) {
                throw new BadRequestException('to must be on or after from.');
            }
            $from = BusinessTime::startOf($rangeFrom);
            $to = BusinessTime::endOf($rangeTo);
            $scope = 'range';
            $label = $rangeFrom . ' – ' . $rangeTo;
        } elseif ($month !== null || $year !== null) {
            if (!is_numeric($month) || !is_numeric($year) || (int)$month < 1 || (int)$month > 12) {
                throw new BadRequestException('Provide a valid month (1-12) and year.');
            }
            [$from, $to] = $this->monthBounds((int)$year, (int)$month);
            $scope = 'month';
            $label = sprintf('%04d-%02d', (int)$year, (int)$month);
        } else {
            $date = $this->assertDateFormat(
                $this->request->getQuery('date') ?: BusinessTime::todayString(),
                'date',
            );
            $from = BusinessTime::startOf($date);
            $to = BusinessTime::endOf($date);
            $scope = 'day';
            $label = $date;
        }

        $inv = $this->fetchTable('Invoices')->find()->where([
            'property_id' => $propertyId,
            'status' => 'settled',
            'settled_at >=' => $from,
            'settled_at <' => $to,
        ]);
        $invRow = $inv->select(['s' => $inv->func()->sum('total'), 'c' => $inv->func()->count('*')])
            ->disableHydration()->first();

        $food = $this->fetchTable('FoodOrders')->find()->where([
            'property_id' => $propertyId,
            'payment_status' => 'paid',
            'created >=' => $from,
            'created <' => $to,
        ]);
        $foodRow = $food->select(['s' => $food->func()->sum('total'), 'c' => $food->func()->count('*')])
            ->disableHydration()->first();

        $invTotal = round((float)($invRow['s'] ?? 0), 2);
        $foodTotal = round((float)($foodRow['s'] ?? 0), 2);

        $this->set('collection', [
            'scope' => $scope,
            'label' => $label,
            'invoices' => ['total' => $invTotal, 'count' => (int)($invRow['c'] ?? 0)],
            'food_orders' => ['total' => $foodTotal, 'count' => (int)($foodRow['c'] ?? 0)],
            'total' => round($invTotal + $foodTotal, 2),
            // Not part of the window: what's still owed right now.
            'outstanding' => $this->outstanding($propertyId),
        ]);
        $this->viewBuilder()->setOption('serialize', ['collection']);
    }

    /**
     * What's been charged but not yet collected: the total still on the
     * property's open invoices — room charges from Mark paid, food charged to
     * the room, early check-in fees — until someone settles them on Food &
     * Orders → Invoices. A snapshot of now, not tied to any date window.
     *
     * @return array{total: float, count: int}
     */
    private function outstanding(int $propertyId): array
    {
        $query = $this->fetchTable('Invoices')->find()
            ->where(['property_id' => $propertyId, 'status' => 'open']);
        $row = $query->select(['s' => $query->func()->sum('total'), 'c' => $query->func()->count('*')])
            ->disableHydration()->first();

        return ['total' => round((float)($row['s'] ?? 0), 2), 'count' => (int)($row['c'] ?? 0)];
    }

    /**
     * A calendar month on the hotel's clock, as stored-timezone bounds
     * `[first moment, first moment of the next month)`.
     *
     * @return array{0: string, 1: string}
     */
    private function monthBounds(int $year, int $month): array
    {
        $first = sprintf('%04d-%02d-01', $year, $month);
        $next = $month === 12 ? sprintf('%04d-01-01', $year + 1) : sprintf('%04d-%02d-01', $year, $month + 1);

        return [BusinessTime::startOf($first), BusinessTime::startOf($next)];
    }

    /**
     * Validate a YYYY-MM-DD query param shared by dailyCollection()'s three
     * window forms, returning it as a plain string once confirmed valid.
     */
    private function assertDateFormat(mixed $value, string $field): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$value)) {
            throw new BadRequestException(sprintf('%s must be YYYY-MM-DD.', $field));
        }

        return (string)$value;
    }

    private const MONTH_LABELS = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
    ];

    /**
     * GET /api/reports/monthly-summary[?year=YYYY]  (admin only)
     *
     * Seasonality, two ways, one year at a time (defaults to current year):
     * - `count` — non-cancelled reservations by the month of their check-in
     *   date (how busy the property was).
     * - `revenue` — collected revenue for the month: settled invoices (by
     *   `settled_at`) + paid standalone food orders (by `created`), the same
     *   definition adminDashboard() uses for its revenue buckets.
     * One query per bucket per month (rather than a `GROUP BY MONTH(...)`)
     * to sidestep the ONLY_FULL_GROUP_BY divergence between local MariaDB and
     * prod MySQL 8 (see CLAUDE.md) — the same pattern adminDashboard() uses.
     */
    public function monthlySummary(): void
    {
        if (!$this->userHasRole('admin')) {
            throw new ForbiddenException('Only a hotel/resort admin can view this report.');
        }
        $propertyId = (int)$this->currentUser->property_id;

        $year = (string)($this->request->getQuery('year') ?: BusinessTime::now()->format('Y'));
        if (!preg_match('/^\d{4}$/', $year)) {
            throw new BadRequestException('year must be a 4-digit number.');
        }
        $year = (int)$year;

        $reservations = $this->fetchTable('Reservations');
        $invoices = $this->fetchTable('Invoices');
        $foodOrders = $this->fetchTable('FoodOrders');
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            [$from, $to] = $this->monthBounds($year, $m);

            // check_in is already a local date, so it's bucketed by the plain
            // calendar month; only the timestamp columns below need converting.
            $firstDay = sprintf('%04d-%02d-01', $year, $m);
            $nextFirstDay = $m === 12 ? sprintf('%04d-01-01', $year + 1) : sprintf('%04d-%02d-01', $year, $m + 1);
            $count = $reservations->find()
                ->where([
                    'property_id' => $propertyId,
                    'status !=' => 'cancelled',
                    'check_in >=' => $firstDay,
                    'check_in <' => $nextFirstDay,
                ])
                ->count();

            $inv = $invoices->find()->where([
                'property_id' => $propertyId,
                'status' => 'settled',
                'settled_at >=' => $from,
                'settled_at <' => $to,
            ]);
            $invTotal = (float)$inv->select(['s' => $inv->func()->sum('total')])->first()->s;

            $food = $foodOrders->find()->where([
                'property_id' => $propertyId,
                'payment_status' => 'paid',
                'created >=' => $from,
                'created <' => $to,
            ]);
            $foodTotal = (float)$food->select(['s' => $food->func()->sum('total')])->first()->s;

            $months[] = [
                'month' => $m,
                'label' => self::MONTH_LABELS[$m],
                'count' => $count,
                'revenue' => round($invTotal + $foodTotal, 2),
            ];
        }

        $this->set('report', ['year' => $year, 'months' => $months]);
        $this->viewBuilder()->setOption('serialize', ['report']);
    }
}
