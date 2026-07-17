import { useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { Button, Badge } from './ui'
import { useAuth } from '../context/AuthContext'

// Navigation items, scoped by role. Owner = platform operator (revenue +
// subscribers); admin = hotel/resort head (operations); receptionist = the
// front-line tabs they act on.
const NAV = [
  { to: '/', label: 'Dashboard', end: true, roles: ['owner', 'admin', 'receptionist'] },
  { to: '/subscribers', label: 'Subscribers', roles: ['owner'] },
  { to: '/inventory', label: 'Inventory', roles: ['admin', 'receptionist'] },
  { to: '/front-desk', label: 'Front Desk', roles: ['admin', 'receptionist'] },
  { to: '/guests', label: 'Guests', roles: ['admin', 'receptionist'] },
  { to: '/food', label: 'Food & Orders', roles: ['admin', 'receptionist'] },
  { to: '/staff', label: 'Staff', roles: ['admin'] },
]

const navLinkClass = ({ isActive }) =>
  `rounded-lg px-3 py-1.5 text-sm font-medium no-underline transition-colors ${
    isActive ? 'bg-subtle text-body' : 'text-muted hover:text-body'
  }`

export default function Layout() {
  const { user, logout, role } = useAuth()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)

  function handleLogout() {
    logout()
    navigate('/login')
  }

  const items = NAV.filter((n) => !n.roles || n.roles.includes(role))

  return (
    <div className="flex min-h-screen flex-col">
      <header className="border-b border-line bg-surface px-4 py-2 lg:px-6">
        <nav className="flex flex-wrap items-center">
          <NavLink to="/" className="sv-serif text-2xl font-bold text-body no-underline">
            Stay<span className="sv-accent">Vanta</span>
          </NavLink>
          <button
            type="button"
            aria-label="Toggle navigation"
            aria-expanded={open}
            className="ml-auto rounded-lg border border-line px-3 py-1.5 text-sm lg:hidden"
            onClick={() => setOpen((o) => !o)}
          >
            ☰
          </button>
          <div className={`${open ? 'flex' : 'hidden'} w-full flex-col gap-3 pt-3 lg:flex lg:w-auto lg:flex-1 lg:flex-row lg:items-center lg:pt-0`}>
            <div className="flex flex-col gap-1 lg:mr-auto lg:ml-6 lg:flex-row">
              {items.map((n) => (
                <NavLink key={n.to} to={n.to} end={n.end} className={navLinkClass}>
                  {n.label}
                </NavLink>
              ))}
            </div>
            <div className="flex items-center gap-3">
              <span className="whitespace-nowrap text-sm">
                {user?.name} <Badge bg="secondary" className="ml-1">{role}</Badge>
              </span>
              <Button size="sm" variant="outline-secondary" onClick={handleLogout}>
                Logout
              </Button>
            </div>
          </div>
        </nav>
      </header>

      <main className="mx-auto w-full max-w-[1200px] grow px-4 py-6 lg:py-12">
        <Outlet />
      </main>

      <footer className="py-4 text-center text-sm text-muted">
        <span className="mr-2">&copy; {new Date().getFullYear()} StayVanta</span>
        ·
        <NavLink to="/privacy" className="mx-2 text-muted no-underline hover:text-body">
          Privacy Policy
        </NavLink>
        ·
        <NavLink to="/terms" className="ml-2 text-muted no-underline hover:text-body">
          Terms of Service
        </NavLink>
      </footer>
    </div>
  )
}
