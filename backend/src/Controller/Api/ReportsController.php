<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;
use Cake\I18n\DateTime;

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
        $now = DateTime::now();
        $monthsElapsed = (int)$now->format('n'); // Jan = 1 ... current month

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
            'guest_id'
        );

        $openFoodOrders = $this->fetchTable('FoodOrders')
            ->find()->where(['property_id' => $propertyId, 'status' => 'open'])->count();

        $now = DateTime::now();
        $ranges = [
            'week' => $now->startOfWeek(),
            'month' => $now->startOfMonth(),
            'ytd' => $now->startOfYear(),
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
        ]);
        $this->viewBuilder()->setOption('serialize', ['dashboard']);
    }

    /**
     * GET /api/reports/daily-collection[?date=YYYY-MM-DD | ?month=&year=]
     *
     * Money collected in the window: settled invoices (by settled_at — room
     * charges, downpayments net of refunds, charged food) + paid standalone
     * food orders. Defaults to today. The month+year form (a whole month) is
     * owner/admin only — a receptionist may only view a single day's
     * collection.
     */
    public function dailyCollection(): void
    {
        $propertyId = $this->effectivePropertyId();
        if ($propertyId === null) {
            throw new BadRequestException('property_id is required.');
        }

        $month = $this->request->getQuery('month');
        $year = $this->request->getQuery('year');

        if ($month !== null || $year !== null) {
            if (!$this->userHasRole('owner', 'admin')) {
                throw new ForbiddenException('Receptionists can view the daily collection only.');
            }
            if (!is_numeric($month) || !is_numeric($year) || (int)$month < 1 || (int)$month > 12) {
                throw new BadRequestException('Provide a valid month (1-12) and year.');
            }
            $from = DateTime::create((int)$year, (int)$month, 1, 0, 0, 0);
            $to = $from->addMonths(1);
            $scope = 'month';
            $label = $from->format('Y-m');
        } else {
            $date = (string)($this->request->getQuery('date') ?: date('Y-m-d'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new BadRequestException('date must be YYYY-MM-DD.');
            }
            $from = DateTime::parse($date . ' 00:00:00');
            $to = $from->addDays(1);
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
        ]);
        $this->viewBuilder()->setOption('serialize', ['collection']);
    }
}
