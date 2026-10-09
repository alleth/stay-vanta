<?php
declare(strict_types=1);

namespace App\Auth;

/**
 * Every permission the platform knows, and (Phase 1) which role holds which.
 *
 * Names are `module.resource.action`, named after the process's owning module
 * (CLAUDE.md, "Process ownership") rather than the screen it sits on today, so
 * they stay valid when screens move and when Phase 2 stores grants as rows.
 * The approved catalog, with the reasoning and the endpoint map, is
 * docs/PERMISSIONS.md; PermissionsTest keeps the two identical.
 *
 * Since Phase 2 (build step 10b) a person's grants are read from data: their
 * membership's role in `role_permissions` (App\Auth\AccessResolver). ROLE_GRANTS
 * stays as the presets' definition: the seed (MembershipImport::seedRoles())
 * and the platform flag's grants (`owner`) come from it, and a test fails if
 * the data drifts. A role that isn't in the map holds nothing (deny by
 * default). The definitions stay here, because the code is what enforces them.
 */
final class Permissions
{
    // Platform (the Platform Owner, not a property)
    public const PLATFORM_DASHBOARD_VIEW = 'platform.dashboard.view';
    public const PLATFORM_PROPERTY_MANAGE = 'platform.property.manage';
    public const PLATFORM_SUPPORT_ACCESS_START = 'platform.support_access.start';

    // Operations
    public const OPERATIONS_TODAY_VIEW = 'operations.today.view';
    public const OPERATIONS_STAFF_VIEW = 'operations.staff.view';

    // Finance
    public const FINANCE_COLLECTIONS_VIEW = 'finance.collections.view';
    public const FINANCE_COLLECTIONS_VIEW_RANGE = 'finance.collections.view_range';
    public const FINANCE_ANALYTICS_VIEW = 'finance.analytics.view';
    public const FINANCE_INVOICE_VIEW = 'finance.invoice.view';
    public const FINANCE_INVOICE_SETTLE = 'finance.invoice.settle';
    public const FINANCE_INVOICE_REVERSE = 'finance.invoice.reverse';
    public const FINANCE_INVOICE_REFUND = 'finance.invoice.refund';
    public const FINANCE_RECEIPT_SERIES_MANAGE = 'finance.receipt_series.manage';
    public const FINANCE_COLLECTIONS_EXPORT = 'finance.collections.export';
    public const FINANCE_INVOICE_EXPORT = 'finance.invoice.export';

    // Front Desk
    public const FRONT_DESK_RESERVATION_VIEW = 'front_desk.reservation.view';
    public const FRONT_DESK_RESERVATION_MANAGE = 'front_desk.reservation.manage';
    public const FRONT_DESK_RESERVATION_BACKDATE = 'front_desk.reservation.backdate';
    public const FRONT_DESK_RESERVATION_CORRECT = 'front_desk.reservation.correct';
    public const FRONT_DESK_RESERVATION_DELETE = 'front_desk.reservation.delete';
    public const FRONT_DESK_RESERVATION_EXPORT = 'front_desk.reservation.export';

    // Guests
    public const GUESTS_GUEST_VIEW = 'guests.guest.view';
    public const GUESTS_GUEST_MANAGE = 'guests.guest.manage';
    public const GUESTS_GUEST_EXPORT = 'guests.guest.export';
    public const GUESTS_GUEST_VIEW_HISTORY = 'guests.guest.view_history';

    // POS
    public const POS_SALE_VIEW = 'pos.sale.view';
    public const POS_SALE_MANAGE = 'pos.sale.manage';
    public const POS_SALE_CANCEL_PAID = 'pos.sale.cancel_paid';
    public const POS_MENU_MANAGE = 'pos.menu.manage';

    // Rooms
    public const ROOMS_ROOM_VIEW = 'rooms.room.view';
    public const ROOMS_ROOM_UPDATE_STATUS = 'rooms.room.update_status';

    // Inventory
    public const INVENTORY_ITEM_VIEW = 'inventory.item.view';
    public const INVENTORY_ITEM_MANAGE = 'inventory.item.manage';
    public const INVENTORY_CATEGORY_MANAGE = 'inventory.category.manage';
    public const INVENTORY_STOCK_ADJUST = 'inventory.stock.adjust';

    // Settings
    public const SETTINGS_PROPERTY_VIEW = 'settings.property.view';
    public const SETTINGS_CONFIGURATION_VIEW = 'settings.configuration.view';
    public const SETTINGS_ROOM_MANAGE = 'settings.room.manage';
    public const SETTINGS_ROOM_RATE_MANAGE = 'settings.room_rate.manage';
    public const SETTINGS_PROMO_RATE_MANAGE = 'settings.promo_rate.manage';
    public const SETTINGS_EXTRA_CHARGE_MANAGE = 'settings.extra_charge.manage';
    public const SETTINGS_CHANGE_LOG_VIEW = 'settings.change_log.view';
    public const SETTINGS_ROLE_VIEW = 'settings.role.view';

    // Staff
    public const STAFF_ACCOUNT_VIEW = 'staff.account.view';
    public const STAFF_ACCOUNT_MANAGE = 'staff.account.manage';
    public const STAFF_ACCESS_HISTORY_VIEW = 'staff.access_history.view';

    /**
     * Every permission, in catalog order.
     */
    public const ALL = [
        self::PLATFORM_DASHBOARD_VIEW,
        self::PLATFORM_PROPERTY_MANAGE,
        self::PLATFORM_SUPPORT_ACCESS_START,
        self::OPERATIONS_TODAY_VIEW,
        self::OPERATIONS_STAFF_VIEW,
        self::FINANCE_COLLECTIONS_VIEW,
        self::FINANCE_COLLECTIONS_VIEW_RANGE,
        self::FINANCE_ANALYTICS_VIEW,
        self::FINANCE_INVOICE_VIEW,
        self::FINANCE_INVOICE_SETTLE,
        self::FINANCE_INVOICE_REVERSE,
        self::FINANCE_INVOICE_REFUND,
        self::FINANCE_RECEIPT_SERIES_MANAGE,
        self::FINANCE_COLLECTIONS_EXPORT,
        self::FINANCE_INVOICE_EXPORT,
        self::FRONT_DESK_RESERVATION_VIEW,
        self::FRONT_DESK_RESERVATION_MANAGE,
        self::FRONT_DESK_RESERVATION_BACKDATE,
        self::FRONT_DESK_RESERVATION_CORRECT,
        self::FRONT_DESK_RESERVATION_DELETE,
        self::FRONT_DESK_RESERVATION_EXPORT,
        self::GUESTS_GUEST_VIEW,
        self::GUESTS_GUEST_MANAGE,
        self::GUESTS_GUEST_EXPORT,
        self::GUESTS_GUEST_VIEW_HISTORY,
        self::POS_SALE_VIEW,
        self::POS_SALE_MANAGE,
        self::POS_SALE_CANCEL_PAID,
        self::POS_MENU_MANAGE,
        self::ROOMS_ROOM_VIEW,
        self::ROOMS_ROOM_UPDATE_STATUS,
        self::INVENTORY_ITEM_VIEW,
        self::INVENTORY_ITEM_MANAGE,
        self::INVENTORY_CATEGORY_MANAGE,
        self::INVENTORY_STOCK_ADJUST,
        self::SETTINGS_PROPERTY_VIEW,
        self::SETTINGS_CONFIGURATION_VIEW,
        self::SETTINGS_ROOM_MANAGE,
        self::SETTINGS_ROOM_RATE_MANAGE,
        self::SETTINGS_PROMO_RATE_MANAGE,
        self::SETTINGS_EXTRA_CHARGE_MANAGE,
        self::SETTINGS_CHANGE_LOG_VIEW,
        self::SETTINGS_ROLE_VIEW,
        self::STAFF_ACCOUNT_VIEW,
        self::STAFF_ACCOUNT_MANAGE,
        self::STAFF_ACCESS_HISTORY_VIEW,
    ];

    /**
     * Sensitive actions: using one requires a reason (authorizeElevated()).
     */
    public const ELEVATED = [
        self::PLATFORM_SUPPORT_ACCESS_START,
        self::FINANCE_INVOICE_REVERSE,
        self::FINANCE_INVOICE_REFUND,
        self::FRONT_DESK_RESERVATION_BACKDATE,
        self::FRONT_DESK_RESERVATION_CORRECT,
        self::FRONT_DESK_RESERVATION_DELETE,
        self::POS_SALE_CANCEL_PAID,
    ];

    /**
     * What every hotel staff role can do (Manager and Front Desk Staff).
     */
    private const PROPERTY_STAFF = [
        self::FINANCE_COLLECTIONS_VIEW,
        self::FINANCE_INVOICE_VIEW,
        self::FINANCE_INVOICE_SETTLE,
        self::FRONT_DESK_RESERVATION_VIEW,
        self::FRONT_DESK_RESERVATION_MANAGE,
        self::GUESTS_GUEST_VIEW,
        self::GUESTS_GUEST_MANAGE,
        self::POS_SALE_VIEW,
        self::POS_SALE_MANAGE,
        self::ROOMS_ROOM_VIEW,
        self::ROOMS_ROOM_UPDATE_STATUS,
        self::INVENTORY_ITEM_VIEW,
        self::SETTINGS_PROPERTY_VIEW,
        self::SETTINGS_CONFIGURATION_VIEW,
    ];

    /**
     * Configuration and control a Manager holds over their property.
     */
    private const PROPERTY_CONTROL = [
        self::FINANCE_COLLECTIONS_VIEW_RANGE,
        self::FINANCE_RECEIPT_SERIES_MANAGE,
        self::POS_SALE_CANCEL_PAID,
        self::POS_MENU_MANAGE,
        self::INVENTORY_ITEM_MANAGE,
        self::INVENTORY_CATEGORY_MANAGE,
        self::INVENTORY_STOCK_ADJUST,
        self::SETTINGS_ROOM_MANAGE,
        self::SETTINGS_ROOM_RATE_MANAGE,
        self::SETTINGS_PROMO_RATE_MANAGE,
        self::SETTINGS_EXTRA_CHARGE_MANAGE,
        self::STAFF_ACCOUNT_VIEW,
        self::STAFF_ACCOUNT_MANAGE,
    ];

    /**
     * Stored role value => what it holds (the presets, seeded into
     * role_permissions). Changing a grant is a behavior change: it goes
     * through docs/PERMISSIONS.md first, and ships as a migration.
     */
    public const ROLE_GRANTS = [
        // Platform Owner (the platform flag, step 10b): the platform, the
        // properties' records and their Managers, and support access. Hotel
        // data only through a read-only support session (supportGrants()).
        'owner' => [
            self::PLATFORM_DASHBOARD_VIEW,
            self::PLATFORM_PROPERTY_MANAGE,
            self::PLATFORM_SUPPORT_ACCESS_START,
            self::SETTINGS_PROPERTY_VIEW,
            self::STAFF_ACCOUNT_VIEW,
            self::STAFF_ACCOUNT_MANAGE,
        ],
        // Manager
        'admin' => [
            ...self::PROPERTY_STAFF,
            ...self::PROPERTY_CONTROL,
            self::OPERATIONS_TODAY_VIEW,
            self::OPERATIONS_STAFF_VIEW,
            self::FINANCE_ANALYTICS_VIEW,
            self::SETTINGS_CHANGE_LOG_VIEW,
            self::SETTINGS_ROLE_VIEW,
            self::STAFF_ACCESS_HISTORY_VIEW,
            self::FINANCE_INVOICE_REVERSE,
            self::FINANCE_INVOICE_REFUND,
            self::FRONT_DESK_RESERVATION_BACKDATE,
            self::FRONT_DESK_RESERVATION_CORRECT,
            self::FRONT_DESK_RESERVATION_DELETE,
            // Data exports (step 10c, X1): Managers only.
            self::FRONT_DESK_RESERVATION_EXPORT,
            self::GUESTS_GUEST_EXPORT,
            self::FINANCE_INVOICE_EXPORT,
            self::FINANCE_COLLECTIONS_EXPORT,
            // Who changed a guest, and why (G3, GU1): Managers only.
            self::GUESTS_GUEST_VIEW_HISTORY,
        ],
        // Front Desk Staff
        'receptionist' => [
            ...self::PROPERTY_STAFF,
            self::OPERATIONS_TODAY_VIEW,
        ],
    ];

    /**
     * What a property in its read-only period keeps besides every view
     * permission (A7, B1 and B2, approved 2026-10-06): exporting its data
     * (10c), settling invoices and recording refunds, so guests already in
     * house are billed and paid back
     * (controlled wind-down), and account security (deactivate, reactivate,
     * reset a password), because security always takes precedence over
     * subscription enforcement. Creating accounts stays refused
     * (UsersController::add()).
     */
    public const READ_ONLY_KEEPS = [
        self::FINANCE_INVOICE_SETTLE,
        self::FINANCE_INVOICE_REFUND,
        self::POS_SALE_CANCEL_PAID,
        self::STAFF_ACCOUNT_MANAGE,
        // A lapsed hotel can still take its data (A7, step 10c).
        self::FRONT_DESK_RESERVATION_EXPORT,
        self::GUESTS_GUEST_EXPORT,
        self::FINANCE_INVOICE_EXPORT,
        self::FINANCE_COLLECTIONS_EXPORT,
    ];

    /**
     * Permissions a read-only property may still use for named wind-down
     * actions only, never in general (AppController::authorizeWindDown()):
     * checking a guest out and posting the room charge of a stay that has
     * started. New bookings, check-ins and edits stay refused.
     */
    public const WIND_DOWN = [
        self::FRONT_DESK_RESERVATION_MANAGE,
    ];

    /**
     * Whether a permission only reads (its action is `view` or `view_*`).
     * Read-only access (a support session; a lapsed subscription, step 10b)
     * keeps these and loses every other.
     */
    public static function isView(string $permission): bool
    {
        $action = substr($permission, (int)strrpos($permission, '.') + 1);

        return $action === 'view' || str_starts_with($action, 'view_');
    }

    /**
     * What the Platform Owner holds during a support session (A6, B8): every
     * view permission of the Manager preset, and support access itself (to
     * end the session). Nothing that changes data.
     *
     * @return list<string>
     */
    public static function supportGrants(): array
    {
        $view = array_values(array_filter(self::ROLE_GRANTS['admin'], [self::class, 'isView']));

        return [...$view, self::PLATFORM_SUPPORT_ACCESS_START];
    }

    /**
     * What a stored role value holds. Unknown or missing roles hold nothing.
     */
    public static function forRole(?string $role): PermissionSet
    {
        return new PermissionSet(self::ROLE_GRANTS[$role ?? ''] ?? []);
    }
}
