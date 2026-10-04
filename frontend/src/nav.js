// Module list — the single source of truth for the post-login Hub
// (src/pages/Hub.jsx), the breadcrumb in Layout.jsx and the route guards in
// App.jsx. Official names and groups: CLAUDE.md, "Product architecture".
//
// Each module opens for a person in its `scope` who holds its `permission`
// (canOpen below). Scope keeps the Platform Owner on platform screens: they
// hold some hotel permissions today (docs/PERMISSIONS.md, † grants), and a
// permission never implies where it applies.
import {
  DashboardIcon, BuildingIcon, BoxIcon, DoorIcon, UserIcon, ReceiptIcon, UsersIcon, WalletIcon, SettingsIcon,
} from './components/icons'
import { P } from './auth/permissions'

// Hub groups for property users, in display order. Empty groups are hidden
// (Front Desk Staff have nothing in Team). The Platform Owner's Hub is
// ungrouped (group: null).
export const GROUPS = [
  { key: 'overview', label: 'Overview' },
  { key: 'guest_services', label: 'Guest services' },
  { key: 'property', label: 'Property' },
  { key: 'team', label: 'Team' },
  { key: 'settings', label: 'Settings' },
]

export const NAV = [
  // Platform
  {
    to: '/dashboard', label: 'Dashboard', blurb: 'Subscriptions & subscribers',
    scope: 'platform', permission: P.PLATFORM_DASHBOARD_VIEW, group: null, Icon: DashboardIcon,
  },
  {
    to: '/subscribers', label: 'Subscribers', blurb: 'Manage hotels & resorts',
    scope: 'platform', permission: P.PLATFORM_PROPERTY_MANAGE, group: null, Icon: BuildingIcon,
  },
  // Property
  {
    to: '/operations', label: 'Operations', blurb: 'Today at a glance',
    scope: 'property', permission: P.OPERATIONS_TODAY_VIEW, group: 'overview', Icon: DashboardIcon,
  },
  {
    to: '/finance', label: 'Finance', blurb: 'Collections & invoices',
    scope: 'property', permission: P.FINANCE_COLLECTIONS_VIEW, group: 'overview', Icon: WalletIcon,
  },
  {
    to: '/front-desk', label: 'Front Desk', blurb: 'Reservations, rooms & check-out',
    scope: 'property', permission: P.FRONT_DESK_RESERVATION_VIEW, group: 'guest_services', Icon: DoorIcon,
  },
  {
    to: '/guests', label: 'Guests', blurb: 'Registry & stay history',
    scope: 'property', permission: P.GUESTS_GUEST_VIEW, group: 'guest_services', Icon: UserIcon,
  },
  {
    to: '/pos', label: 'POS', blurb: 'Food, linens & room charges',
    scope: 'property', permission: P.POS_SALE_VIEW, group: 'guest_services', Icon: ReceiptIcon,
  },
  {
    to: '/inventory', label: 'Inventory', blurb: 'Stock & categories',
    scope: 'property', permission: P.INVENTORY_ITEM_VIEW, group: 'property', Icon: BoxIcon,
  },
  {
    to: '/staff', label: 'Staff', blurb: 'Manage your team',
    scope: 'property', permission: P.STAFF_ACCOUNT_VIEW, group: 'team', Icon: UsersIcon,
  },
  // Settings never ships without its change log (CLAUDE.md), so it opens for
  // whoever may read that log: a Manager (step 9).
  {
    to: '/settings', label: 'Settings', blurb: 'Rooms, rates & change log',
    scope: 'property', permission: P.SETTINGS_CHANGE_LOG_VIEW, group: 'settings', Icon: SettingsIcon,
  },
]

/** Whether a NAV item opens for this person: right scope and the permission. */
export function canOpen(item, { scope, can }) {
  return item.scope === scope && can(item.permission)
}

/** The NAV item for a path, e.g. navItem('/finance'). */
export function navItem(to) {
  const item = NAV.find((n) => n.to === to)
  if (!item) throw new Error(`No module at ${to} in nav.js`)
  return item
}
