import { useEffect, useState } from 'react'
import { Navigate } from 'react-router-dom'
import { Row, Col, Card, Alert } from 'react-bootstrap'
import { useAuth } from '../context/AuthContext'
import { ownerDashboard, adminDashboard } from '../api/reports'
import { formatMoney } from '../utils/format'
import { SkeletonCards, SkeletonTable } from '../components/Skeleton'

function Tiles({ tiles, cols = 3, money = false }) {
  return (
    <Row className="g-3 mb-4">
      {tiles.map((t) => (
        <Col key={t.label} sm={6} lg={cols}>
          <Card className="h-100">
            <Card.Body className="p-4">
              <div
                className="small text-uppercase"
                style={{ color: 'var(--sv-muted)', letterSpacing: '0.04em' }}
              >
                {t.label}
              </div>
              <div className="sv-serif fw-bold mt-2" style={{ fontSize: money ? '1.75rem' : '2.25rem' }}>
                {money ? formatMoney(t.value) : t.value}
              </div>
            </Card.Body>
          </Card>
        </Col>
      ))}
    </Row>
  )
}

function Loading() {
  return (
    <>
      <SkeletonCards count={4} />
      <SkeletonTable rows={4} />
    </>
  )
}

// Surface the real failure instead of a blanket message: prefer the API's JSON
// error, fall back to the HTTP status, then to a network-level hint.
function dashboardError(err) {
  const res = err?.response
  if (res) return res.data?.message || `Request failed (${res.status}).`
  return 'Could not reach the server. Please try again.'
}

function SectionTitle({ children }) {
  return (
    <h2 className="h6 text-uppercase mb-3" style={{ color: 'var(--sv-muted)', letterSpacing: '0.04em' }}>
      {children}
    </h2>
  )
}

// Platform owner: subscription revenue + active users.
function OwnerDashboard({ user }) {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    ownerDashboard().then(setData).catch((err) => setError(dashboardError(err)))
  }, [])

  if (error) return <Alert variant="danger">{error}</Alert>
  if (!data) return <Loading />

  return (
    <div>
      <h1 className="sv-serif fw-bold mb-1" style={{ fontSize: '2rem' }}>Dashboard</h1>
      <p className="mb-4" style={{ color: 'var(--sv-muted)' }}>
        Welcome back, {user?.name}. Platform revenue and active subscribers.
      </p>

      <SectionTitle>Subscription revenue</SectionTitle>
      <Tiles
        cols={3}
        money
        tiles={[
          { label: 'This week', value: data.revenue.week },
          { label: 'This month', value: data.revenue.month },
          { label: 'Year to date', value: data.revenue.ytd },
        ]}
      />

      <SectionTitle>Active users</SectionTitle>
      <Tiles
        cols={3}
        tiles={[
          { label: 'Hotels & Resorts', value: data.counts.hotels },
          { label: 'Active subscriptions', value: data.counts.active_subscriptions },
          { label: 'Registered admins', value: data.counts.admins },
        ]}
      />
    </div>
  )
}

// Hotel/resort admin: operational figures + collected revenue.
function AdminDashboard({ user }) {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    adminDashboard().then(setData).catch((err) => setError(dashboardError(err)))
  }, [])

  if (error) return <Alert variant="danger">{error}</Alert>
  if (!data) return <Loading />

  return (
    <div>
      <h1 className="sv-serif fw-bold mb-1" style={{ fontSize: '2rem' }}>Dashboard</h1>
      <p className="mb-4" style={{ color: 'var(--sv-muted)' }}>
        Welcome back, {user?.name}. Today at your property.
      </p>

      <SectionTitle>Operations</SectionTitle>
      <Tiles
        cols={3}
        tiles={[
          { label: 'Inventory items', value: data.cards.inventory_items },
          { label: 'Occupied rooms', value: data.cards.occupied_rooms },
          { label: 'Guests today', value: data.cards.guests_today },
          { label: 'Open food orders', value: data.cards.open_food_orders },
        ]}
      />

      <SectionTitle>Revenue collected</SectionTitle>
      <Tiles
        cols={3}
        money
        tiles={[
          { label: 'This week', value: data.revenue.week },
          { label: 'This month', value: data.revenue.month },
          { label: 'Year to date', value: data.revenue.ytd },
        ]}
      />
    </div>
  )
}

export default function Dashboard() {
  const { user, role } = useAuth()
  if (role === 'owner') return <OwnerDashboard user={user} />
  if (role === 'admin') return <AdminDashboard user={user} />
  // Receptionists have no dashboard — send them to their first working tab.
  return <Navigate to="/front-desk" replace />
}
