import { useEffect, useState } from 'react'
import { Row, Col, Card, Alert, Form } from 'react-bootstrap'
import { useAuth } from '../context/AuthContext'
import { ownerDashboard, adminDashboard, dailyCollection } from '../api/reports'
import { formatMoney } from '../utils/format'
import { SkeletonCards, SkeletonTable } from '../components/Skeleton'

const todayStr = () => new Date().toISOString().slice(0, 10)
const MONTHS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
]

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

// Money collected in a day (settled invoices + paid food orders), with a date
// filter. Admins can widen the window to a whole month/year; receptionists may
// only view one day at a time (the backend enforces the same).
function CollectionReport({ allowMonthly }) {
  const now = new Date()
  const [mode, setMode] = useState('day')
  const [date, setDate] = useState(todayStr)
  const [month, setMonth] = useState(now.getMonth() + 1)
  const [year, setYear] = useState(now.getFullYear())
  // The result is keyed by the filter that produced it, so switching filters
  // shows the loading skeleton (a stale key) without a synchronous setState.
  const key = mode === 'month' ? `m-${month}-${year}` : `d-${date}`
  const [result, setResult] = useState(null)

  useEffect(() => {
    const params = mode === 'month' ? { month, year } : { date }
    let active = true
    dailyCollection(params)
      .then((c) => { if (active) setResult({ key, data: c }) })
      .catch((err) => { if (active) setResult({ key, error: dashboardError(err) }) })
    return () => { active = false }
  }, [mode, date, month, year, key])

  const data = result?.key === key ? result.data : null
  const error = result?.key === key ? result.error : null

  const years = Array.from({ length: 6 }, (_, i) => now.getFullYear() - i)

  return (
    <>
      <Card className="mb-3">
        <Card.Body className="d-flex align-items-center gap-3 flex-wrap py-3">
          {allowMonthly && (
            <Form.Select size="sm" value={mode} style={{ width: 'auto' }}
              onChange={(e) => setMode(e.target.value)}>
              <option value="day">Daily</option>
              <option value="month">Monthly</option>
            </Form.Select>
          )}
          {mode === 'day' ? (
            <Form.Control size="sm" type="date" value={date} style={{ maxWidth: 180 }}
              onChange={(e) => setDate(e.target.value)} />
          ) : (
            <>
              <Form.Select size="sm" value={month} style={{ width: 'auto' }}
                onChange={(e) => setMonth(Number(e.target.value))}>
                {MONTHS.map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
              </Form.Select>
              <Form.Select size="sm" value={year} style={{ width: 'auto' }}
                onChange={(e) => setYear(Number(e.target.value))}>
                {years.map((y) => <option key={y} value={y}>{y}</option>)}
              </Form.Select>
            </>
          )}
          {data && (
            <span className="small ms-auto" style={{ color: 'var(--sv-muted)' }}>
              {data.invoices.count} settled invoice(s) · {data.food_orders.count} paid food order(s)
            </span>
          )}
        </Card.Body>
      </Card>
      {error && <Alert variant="danger">{error}</Alert>}
      {!data && !error && <SkeletonCards count={3} />}
      {data && (
        <Tiles
          cols={3}
          money
          tiles={[
            { label: 'Total collected', value: data.total },
            { label: 'Invoices settled', value: data.invoices.total },
            { label: 'Food orders paid', value: data.food_orders.total },
          ]}
        />
      )}
    </>
  )
}

// Receptionist: the daily collection is the only report they can view.
function ReceptionistDashboard({ user }) {
  return (
    <div>
      <h1 className="sv-serif fw-bold mb-1" style={{ fontSize: '2rem' }}>Dashboard</h1>
      <p className="mb-4" style={{ color: 'var(--sv-muted)' }}>
        Welcome back, {user?.name}. Money collected on the selected day.
      </p>
      <SectionTitle>Daily collection</SectionTitle>
      <CollectionReport allowMonthly={false} />
    </div>
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

      <SectionTitle>Collection report</SectionTitle>
      <CollectionReport allowMonthly />
    </div>
  )
}

export default function Dashboard() {
  const { user, role } = useAuth()
  if (role === 'owner') return <OwnerDashboard user={user} />
  if (role === 'admin') return <AdminDashboard user={user} />
  // Receptionists see only the daily collection.
  return <ReceptionistDashboard user={user} />
}
