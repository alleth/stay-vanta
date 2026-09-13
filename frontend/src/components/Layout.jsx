import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { Button, Badge } from './ui'
import BrandMark from './BrandMark'
import ThemeToggle from './ThemeToggle'
import { useAuth } from '../context/AuthContext'
import { NAV } from '../nav'

// First letters of up to the first two words — for the header's avatar chip.
function initials(name) {
  if (!name) return ''
  const parts = name.trim().split(/\s+/)
  return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase()
}

// No persistent tab bar: navigation is the post-login Hub (src/pages/Hub.jsx,
// a role-scoped icon grid at "/"). Two ways back, each in its own layer: the
// brand in the header (global chrome — it never changes), and a breadcrumb at
// the top of the page body (page-level context — "Home / Front Desk"), which
// replaced the old Home tab. Crumb labels come from src/nav.js, the same list
// the Hub's tiles are built from, so a crumb can't drift from the tile that
// was clicked.
export default function Layout() {
  const { user, logout, role } = useAuth()
  const navigate = useNavigate()
  const { pathname } = useLocation()
  // Every route under this layout is flat and listed in NAV except the Hub
  // itself at "/", which is the crumb trail's root and needs no second crumb.
  const current = NAV.find((item) => item.to === pathname)

  function handleLogout() {
    logout()
    navigate('/login')
  }

  return (
    <div className="flex min-h-screen flex-col">
      <header className="sticky top-0 z-30 border-b border-line bg-surface/95 px-4 py-2.5 backdrop-blur-sm lg:px-8">
        <div className="flex flex-wrap items-center gap-3">
          <NavLink
            to="/"
            className="sv-serif flex items-center gap-2 text-lg font-bold text-body no-underline"
          >
            <BrandMark className="h-6 w-6 shrink-0" />
            <span>Stay<span className="sv-accent">Vanta</span></span>
          </NavLink>
          <ThemeToggle className="ml-auto" />
          <div className="flex items-center gap-3 lg:border-l lg:border-line lg:pl-4">
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-ink text-xs font-semibold text-on-ink">
              {initials(user?.name)}
            </span>
            <span className="hidden whitespace-nowrap text-sm sm:inline">
              {user?.name} <Badge bg="secondary" className="ml-1">{role}</Badge>
            </span>
            <Button size="sm" variant="outline-secondary" onClick={handleLogout}>
              Logout
            </Button>
          </div>
        </div>
      </header>

      <main className="mx-auto w-full max-w-[1200px] grow px-4 py-6 lg:px-8 lg:py-12">
        {/* The trail belongs with the page it describes, not in the chrome —
            the header is global and doesn't change as you move around. Nothing
            renders on the Hub itself, which is the trail's root. */}
        {current && (
          <nav aria-label="Breadcrumb" className="mb-4">
            <ol className="flex items-center gap-2 text-sm">
              <li className="flex">
                <NavLink to="/" className="text-muted no-underline hover:text-body">
                  Home
                </NavLink>
              </li>
              <li aria-hidden="true" className="text-muted">/</li>
              <li className="min-w-0">
                <span aria-current="page" className="truncate font-medium text-body">
                  {current.label}
                </span>
              </li>
            </ol>
          </nav>
        )}
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
