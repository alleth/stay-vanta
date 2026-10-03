<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Permissions;
use App\Model\BusinessTime;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;

/**
 * Finance: money the property has collected and is still owed.
 *
 * GET /api/finance/collections, /api/finance/summary, /api/finance/seasonality.
 * The old /api/reports/daily-collection, /admin-dashboard and /monthly-summary
 * routes point here too until they're removed (build step 3 compatibility
 * window). Collected = settled invoices (by settled_at) + paid POS orders (by
 * created); outstanding = open invoices right now.
 */
class FinanceController extends AppController
{
    /**
     * GET /api/finance/summary  (Manager only, own property)
     *
     * Collected by period (week / month / year to date / all time, on the
     * hotel's calendar) and what's outstanding right now.
     */
    public function summary(): void
    {
        $this->authorize(Permissions::FINANCE_ANALYTICS_VIEW, 'Only a Manager can view revenue by period.');
        $propertyId = (int)$this->effectivePropertyId();

        $this->set('summary', [
            'collected' => $this->collectedByPeriod($propertyId),
            'outstanding' => $this->outstanding($propertyId),
        ]);
        $this->viewBuilder()->setOption('serialize', ['summary']);
    }

    /**
     * GET /api/reports/admin-dashboard  (Manager only) — the old response
     * shape, kept until the old route is removed. New code calls summary().
     */
    public function adminDashboard(): void
    {
        $this->authorize(Permissions::FINANCE_ANALYTICS_VIEW, 'Only a Manager can view revenue by period.');
        $propertyId = (int)$this->effectivePropertyId();

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

        $this->set('dashboard', [
            'cards' => [
                'inventory_items' => $inventoryItems,
                'occupied_rooms' => $occupiedRooms,
                'guests_today' => $guestsToday,
                'open_food_orders' => $openFoodOrders,
            ],
            'revenue' => $this->collectedByPeriod($propertyId),
            'outstanding' => $this->outstanding($propertyId),
        ]);
        $this->viewBuilder()->setOption('serialize', ['dashboard']);
    }

    /**
     * Collected per period, on the hotel's calendar.
     *
     * @return array{week: float, month: float, ytd: float, all_time: float}
     */
    private function collectedByPeriod(int $propertyId): array
    {
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
        $collected = [];
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
            $collected[$key] = round($invTotal + $foodTotal, 2);
        }

        return $collected;
    }

    /**
     * GET /api/finance/collections[?date=YYYY-MM-DD | ?month=&year= | ?from=&to=]  (old path /api/reports/daily-collection)
     *
     * Money collected in the window: settled invoices (by settled_at — room
     * charges, downpayments net of refunds, charged food) + paid standalone
     * food orders. Defaults to today. The month+year and from/to forms are
     * owner/admin only — a receptionist may only view a single day's
     * collection.
     */
    public function collections(): void
    {
        $this->authorize(Permissions::FINANCE_COLLECTIONS_VIEW);
        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        $rangeFrom = $this->request->getQuery('from');
        $rangeTo = $this->request->getQuery('to');
        $month = $this->request->getQuery('month');
        $year = $this->request->getQuery('year');
        $isWideWindow = $rangeFrom !== null || $rangeTo !== null || $month !== null || $year !== null;

        if ($isWideWindow && !$this->can(Permissions::FINANCE_COLLECTIONS_VIEW_RANGE)) {
            throw new ForbiddenException("Front Desk Staff can view one day's collection only.");
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
     * GET /api/finance/seasonality[?year=YYYY]  (Manager only; old path /api/reports/monthly-summary)
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
    public function seasonality(): void
    {
        $this->authorize(Permissions::FINANCE_ANALYTICS_VIEW, 'Only a Manager can view seasonality.');
        $propertyId = (int)$this->effectivePropertyId();

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
