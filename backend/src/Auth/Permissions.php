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
 * Phase 1 maps each stored role value to a fixed list. A role that isn't in
 * the map holds nothing (deny by default). Phase 2 replaces forRole() with the
 * grants of the user's membership at the request's property; the definitions
 * stay here, because the code is what enforces them.
 */
final class Permissions
{
    // Platform (the Platform Owner, not a property)
    public const PLATFORM_DASHBOARD_VIEW = 'platform.dashboard.view';
    public const PLATFORM_PROPERTY_MANAGE = 'platform.property.manage';

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

    // Front Desk
    public const FRONT_DESK_RESERVATION_VIEW = 'front_desk.reservation.view';
    public const FRONT_DESK_RESERVATION_MANAGE = 'front_desk.reservation.manage';
    public const FRONT_DESK_RESERVATION_BACKDATE = 'front_desk.reservation.backdate';
    public const FRONT_DESK_RESERVATION_CORRECT = 'front_desk.reservation.correct';
    public const FRONT_DESK_RESERVATION_DELETE = 'front_desk.reservation.delete';

    // Guests
    public const GUESTS_GUEST_VIEW = 'guests.guest.view';
    public const GUESTS_GUEST_MANAGE = 'guests.guest.manage';

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

    // Staff
    public const STAFF_ACCOUNT_VIEW = 'staff.account.view';
    public const STAFF_ACCOUNT_MANAGE = 'staff.account.manage';

    /**
     * Every permission, in catalog order.
     */
    public const ALL = [
        self::PLATFORM_DASHBOARD_VIEW,
        self::PLATFORM_PROPERTY_MANAGE,
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
        self::FRONT_DESK_RESERVATION_VIEW,
        self::FRONT_DESK_RESERVATION_MANAGE,
        self::FRONT_DESK_RESERVATION_BACKDATE,
        self::FRONT_DESK_RESERVATION_CORRECT,
        self::FRONT_DESK_RESERVATION_DELETE,
        self::GUESTS_GUEST_VIEW,
        self::GUESTS_GUEST_MANAGE,
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
        self::STAFF_ACCOUNT_VIEW,
        self::STAFF_ACCOUNT_MANAGE,
    ];

    /**
     * Sensitive actions. Once the event foundation exists (steps 5–8), using
     * one will require a reason; for now this is only the flag.
     */
    public const ELEVATED = [
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
     * Configuration and control that the Platform Owner and a Manager share
     * today (the Platform Owner's part goes in Phase 2, see BACKLOG.md).
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
     * Stored role value => what it holds. Reproduces the role checks the code
     * made before Phase 1, exactly; changing a grant is a behavior change and
     * goes through docs/PERMISSIONS.md first.
     */
    public const ROLE_GRANTS = [
        // Platform Owner. Holds the property permissions below only because
        // those endpoints had no check, or checked "owner or admin"; marked †
        // in the catalog.
        'owner' => [
            self::PLATFORM_DASHBOARD_VIEW,
            self::PLATFORM_PROPERTY_MANAGE,
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
            ...self::PROPERTY_CONTROL,
        ],
        // Manager
        'admin' => [
            ...self::PROPERTY_STAFF,
            ...self::PROPERTY_CONTROL,
            self::OPERATIONS_TODAY_VIEW,
            self::OPERATIONS_STAFF_VIEW,
            self::FINANCE_ANALYTICS_VIEW,
            self::SETTINGS_CHANGE_LOG_VIEW,
            self::FINANCE_INVOICE_REVERSE,
            self::FINANCE_INVOICE_REFUND,
            self::FRONT_DESK_RESERVATION_BACKDATE,
            self::FRONT_DESK_RESERVATION_CORRECT,
            self::FRONT_DESK_RESERVATION_DELETE,
        ],
        // Front Desk Staff
        'receptionist' => [
            ...self::PROPERTY_STAFF,
            self::OPERATIONS_TODAY_VIEW,
        ],
    ];

    /**
     * What a stored role value holds. Unknown or missing roles hold nothing.
     */
    public static function forRole(?string $role): PermissionSet
    {
        return new PermissionSet(self::ROLE_GRANTS[$role ?? ''] ?? []);
    }
}
