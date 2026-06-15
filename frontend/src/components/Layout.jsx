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
      <Navbar bg="dark" variant="dark" expand="lg" className="px-3">
        <Navbar.Brand as={NavLink} to="/" className="fw-bold">
          Stay<span className="text-warning">Vanta</span>
        </Navbar.Brand>
        <Navbar.Toggle />
        <Navbar.Collapse>
          <Nav className="me-auto">
            {items.map((n) => (
              <Nav.Link key={n.to} as={NavLink} to={n.to} end={n.end}>
                {n.label}
              </Nav.Link>
            ))}
          </Nav>
          <Nav className="align-items-lg-center gap-2">
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
            <span className="text-light small">
              {user?.name} <Badge bg="secondary">{role}</Badge>
            </span>
            <Button size="sm" variant="outline-light" onClick={handleLogout}>
              Logout
            </Button>
          </Nav>
        </Navbar.Collapse>
      </Navbar>

      <Container fluid className="py-4 flex-grow-1">
        <Outlet />
      </Container>
    </div>
  )
}
