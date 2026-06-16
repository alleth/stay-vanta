import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { Navbar, Nav, Container, Button, Badge, Form } from 'react-bootstrap'
import { useAuth } from '../context/AuthContext'
import { useProperty } from '../context/PropertyContext'

// Navigation items. `roles` (when set) restricts visibility.
const NAV = [
  { to: '/', label: 'Dashboard', end: true },
  { to: '/inventory', label: 'Inventory' },
  { to: '/front-desk', label: 'Front Desk' },
  { to: '/guests', label: 'Guests' },
  { to: '/food', label: 'Food & Orders' },
  { to: '/properties', label: 'Properties', roles: ['owner'] },
  { to: '/reports', label: 'Reports', roles: ['owner', 'admin'] },
  { to: '/staff', label: 'Staff', roles: ['owner', 'admin'] },
]

export default function Layout() {
  const { user, logout, role } = useAuth()
  const { isOwner, properties, propertyId, choose } = useProperty()
  const navigate = useNavigate()

  function handleLogout() {
    logout()
    navigate('/login')
  }

  const items = NAV.filter((n) => !n.roles || n.roles.includes(role))

  return (
    <div className="d-flex flex-column min-vh-100">
      <Navbar expand="lg" className="sv-navbar px-3 px-lg-4 py-2">
        <Navbar.Brand as={NavLink} to="/" className="sv-serif fw-bold fs-4 m-0">
          Stay<span className="sv-accent">Vanta</span>
        </Navbar.Brand>
        <Navbar.Toggle />
        <Navbar.Collapse>
          <Nav className="sv-nav me-auto ms-lg-4 gap-1">
            {items.map((n) => (
              <Nav.Link key={n.to} as={NavLink} to={n.to} end={n.end}>
                {n.label}
              </Nav.Link>
            ))}
          </Nav>
          <Nav className="align-items-lg-center gap-3">
            {isOwner && properties.length > 0 && (
              <Form.Select
                size="sm"
                style={{ width: 'auto' }}
                value={propertyId ?? ''}
                onChange={(e) => choose(Number(e.target.value))}
              >
                {properties.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
              </Form.Select>
            )}
            <span className="small text-nowrap" style={{ color: 'var(--sv-text)' }}>
              {user?.name} <Badge bg="secondary" className="ms-1">{role}</Badge>
            </span>
            <Button size="sm" variant="outline-secondary" onClick={handleLogout}>
              Logout
            </Button>
          </Nav>
        </Navbar.Collapse>
      </Navbar>

      <Container className="py-4 py-lg-5 flex-grow-1" style={{ maxWidth: 1200 }}>
        <Outlet />
      </Container>
    </div>
  )
}
