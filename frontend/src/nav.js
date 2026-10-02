// Role-scoped module list — the single source of truth for both the
// post-login Hub (src/pages/Hub.jsx) and the breadcrumb in Layout.jsx.
// Official names and groups: CLAUDE.md, "Product architecture". Stored role
// values: owner = Platform Owner, admin = Manager, receptionist = Front Desk
// Staff (display names in utils/roles.js).
import {
  DashboardIcon, BuildingIcon, BoxIcon, DoorIcon, UserIcon, ReceiptIcon, UsersIcon, WalletIcon,
} from './components/icons'

// Hub groups for property users, in display order. Empty groups are hidden
// (Front Desk Staff have nothing in Team). The Platform Owner's Hub is
// ungrouped (group: null).
export const GROUPS = [
  { key: 'overview', label: 'Overview' },
  { key: 'guest_services', label: 'Guest services' },
  { key: 'property', label: 'Property' },
  { key: 'team', label: 'Team' },
]

const STAFF = ['admin', 'receptionist']

export const NAV = [
  // Platform Owner
  {
    to: '/dashboard', label: 'Dashboard', blurb: 'Subscriptions & subscribers',
    roles: ['owner'], group: null, Icon: DashboardIcon,
  },
  {
    to: '/subscribers', label: 'Subscribers', blurb: 'Manage hotels & resorts',
    roles: ['owner'], group: null, Icon: BuildingIcon,
  },
  // Property users
  {
    to: '/operations', label: 'Operations', blurb: 'Today at a glance',
    roles: STAFF, group: 'overview', Icon: DashboardIcon,
  },
  {
    to: '/finance', label: 'Finance', blurb: 'Collections & invoices',
    roles: STAFF, group: 'overview', Icon: WalletIcon,
  },
  {
    to: '/front-desk', label: 'Front Desk', blurb: 'Rooms, rates & reservations',
    roles: STAFF, group: 'guest_services', Icon: DoorIcon,
  },
  {
    to: '/guests', label: 'Guests', blurb: 'Registry & stay history',
    roles: STAFF, group: 'guest_services', Icon: UserIcon,
  },
  {
    to: '/pos', label: 'POS', blurb: 'Food, linens & room charges',
    roles: STAFF, group: 'guest_services', Icon: ReceiptIcon,
  },
  {
    to: '/inventory', label: 'Inventory', blurb: 'Stock & receipt booklets',
    roles: STAFF, group: 'property', Icon: BoxIcon,
  },
  {
    to: '/staff', label: 'Staff', blurb: 'Manage your team',
    roles: ['admin'], group: 'team', Icon: UsersIcon,
  },
]
