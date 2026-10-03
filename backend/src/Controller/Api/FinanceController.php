<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\Permissions;
use App\Model\BusinessTime;
use App\Model\Finance\Collections;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;

/**
 * Finance: money the property has collected and is still owed.
 *
 * GET /api/finance/collections, /api/finance/summary, /api/finance/seasonality.
 * The old /api/reports/daily-collection, /admin-dashboard and /monthly-summary
 * routes point here too until they're removed (build step 3 compatibility
 * window). Every figure comes from App\Model\Finance\Collections, the one
 * definition of Collected and Outstanding (build step 7a).
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
            'collected' => $this->money($propertyId)->byPeriod(),
            'outstanding' => $this->money($propertyId)->outstanding(),
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
            'revenue' => $this->money($propertyId)->byPeriod(),
            'outstanding' => $this->money($propertyId)->outstanding(),
        ]);
        $this->viewBuilder()->setOption('serialize', ['dashboard']);
    }

    /**
     * The property's money figures (Collected, Outstanding): one definition
     * for every report.
     */
    private function money(int $propertyId): Collections
    {
        return new Collections($propertyId);
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

        $money = $this->money($propertyId);
        $collected = $money->collected($from, $to);

        $this->set('collection', [
            'scope' => $scope,
            'label' => $label,
            'invoices' => $collected['invoices'],
            'food_orders' => $collected['pos'],
            'total' => $collected['total'],
            // Not part of the window: what's still owed right now.
            'outstanding' => $money->outstanding(),
        ]);
        $this->viewBuilder()->setOption('serialize', ['collection']);
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
     * - `revenue` — collected in the month (Collections, the same definition
     *   as every other report).
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
        $money = $this->money($propertyId);
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

            $months[] = [
                'month' => $m,
                'label' => self::MONTH_LABELS[$m],
                'count' => $count,
                'revenue' => $money->collected($from, $to)['total'],
            ];
        }

        $this->set('report', ['year' => $year, 'months' => $months]);
        $this->viewBuilder()->setOption('serialize', ['report']);
    }
}
