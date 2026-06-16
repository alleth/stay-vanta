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
      <h1 className="sv-serif fw-bold mb-1" style={{ fontSize: '2rem' }}>Dashboard</h1>
      <p className="mb-4" style={{ color: 'var(--sv-muted)' }}>
        Welcome back, {user?.name}. You are signed in as <strong>{role}</strong>.
      </p>
      <Row className="g-3">
        {TILES.map((t) => (
          <Col key={t.label} sm={6} lg={3}>
            <Card className="h-100">
              <Card.Body className="p-4">
                <div className="small text-uppercase" style={{ color: 'var(--sv-muted)', letterSpacing: '0.04em' }}>
                  {t.label}
                </div>
                <div className="sv-serif fw-bold mt-2" style={{ fontSize: '2.25rem' }}>{t.value}</div>
              </Card.Body>
            </Card>
          </Col>
        ))}
      </Row>
    </div>
  )
}
