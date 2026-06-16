<?php
declare(strict_types=1);

namespace App\Controller\Api;

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

        $guestsToday = $this->fetchTable('Reservations')
            ->find()
            ->where(['property_id' => $propertyId, 'status' => 'checked_in', 'guest_id IS NOT' => null])
            ->distinct(['guest_id'])
            ->count();

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
}
