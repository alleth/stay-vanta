import { Row, Col, Card } from 'react-bootstrap'
import { useAuth } from '../context/AuthContext'

const TILES = [
  { label: 'Inventory items', value: '—' },
  { label: 'Occupied rooms', value: '—' },
  { label: 'Guests today', value: '—' },
  { label: 'Open food orders', value: '—' },
]

export default function Dashboard() {
  const { user, role } = useAuth()
  return (
    <div>
      <h1 className="h3 fw-bold mb-1">Dashboard</h1>
      <p className="text-muted">
        Welcome back, {user?.name}. You are signed in as <strong>{role}</strong>.
      </p>
      <Row className="g-3">
        {TILES.map((t) => (
          <Col key={t.label} sm={6} lg={3}>
            <Card className="shadow-sm h-100">
              <Card.Body>
                <div className="text-muted small">{t.label}</div>
                <div className="fs-2 fw-bold">{t.value}</div>
              </Card.Body>
            </Card>
          </Col>
        ))}
      </Row>
    </div>
  )
}
