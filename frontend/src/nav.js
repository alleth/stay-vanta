// Role-scoped module list — the single source of truth for both the
// post-login Hub (src/pages/Hub.jsx, the icon picker) and the header's
// active-page context. Owner = platform operator; admin = hotel/resort
// head; receptionist = front-line tabs they act on (see CLAUDE.md).
import {
  DashboardIcon, BuildingIcon, BoxIcon, DoorIcon, UserIcon, ReceiptIcon, UsersIcon,
} from './components/icons'

export const NAV = [
  {
    to: '/dashboard', label: 'Dashboard', blurb: 'Reports & revenue',
    roles: ['owner', 'admin', 'receptionist'], Icon: DashboardIcon,
  },
  {
    to: '/subscribers', label: 'Subscribers', blurb: 'Manage hotels & resorts',
    roles: ['owner'], Icon: BuildingIcon,
  },
  {
    to: '/inventory', label: 'Inventory', blurb: 'Stock & receipt booklets',
    roles: ['admin', 'receptionist'], Icon: BoxIcon,
  },
  {
    to: '/front-desk', label: 'Front Desk', blurb: 'Rooms, rates & reservations',
    roles: ['admin', 'receptionist'], Icon: DoorIcon,
  },
  {
    to: '/guests', label: 'Guests', blurb: 'Registry & stay history',
    roles: ['admin', 'receptionist'], Icon: UserIcon,
  },
  {
    to: '/food', label: 'Food & Orders', blurb: 'Menu, orders & invoices',
    roles: ['admin', 'receptionist'], Icon: ReceiptIcon,
  },
  {
    to: '/staff', label: 'Staff', blurb: 'Manage receptionists',
    roles: ['admin'], Icon: UsersIcon,
  },
]
