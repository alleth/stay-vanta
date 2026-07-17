import { useEffect, useState } from 'react'
import { Card, Alert, Form } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { ownerDashboard, adminDashboard, dailyCollection } from '../api/reports'
import { formatMoney } from '../utils/format'
import { SkeletonCards, SkeletonTable } from '../components/Skeleton'

const todayStr = () => new Date().toISOString().slice(0, 10)
const MONTHS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
]

function Tiles({ tiles, money = false }) {
  return (
    <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      {tiles.map((t) => (
        <Card key={t.label} className="h-full">
          <Card.Body className="p-6">
            <div className="text-sm uppercase tracking-[0.04em] text-muted">
              {t.label}
            </div>
            <div className={`sv-serif mt-2 font-bold ${money ? 'text-[1.75rem]' : 'text-[2.25rem]'}`}>
              {money ? formatMoney(t.value) : t.value}
            </div>
          </Card.Body>
        </Card>
      ))}
    </div>
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
    <h2 className="mb-4 text-sm font-semibold uppercase tracking-[0.04em] text-muted">
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
      <Card className="mb-4">
        <Card.Body className="flex flex-wrap items-center gap-4 px-4 py-3">
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
            <span className="ml-auto text-sm text-muted">
              {data.invoices.count} settled invoice(s) · {data.food_orders.count} paid food order(s)
            </span>
          )}
        </Card.Body>
      </Card>
      {error && <Alert variant="danger">{error}</Alert>}
      {!data && !error && <SkeletonCards count={3} />}
      {data && (
        <Tiles
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
      <h1 className="sv-serif mb-1 text-[2rem] font-bold">Dashboard</h1>
      <p className="mb-6 text-muted">
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
      <h1 className="sv-serif mb-1 text-[2rem] font-bold">Dashboard</h1>
      <p className="mb-6 text-muted">
        Welcome back, {user?.name}. Platform revenue and active subscribers.
      </p>

      <SectionTitle>Subscription revenue</SectionTitle>
      <Tiles
        money
        tiles={[
          { label: 'This week', value: data.revenue.week },
          { label: 'This month', value: data.revenue.month },
          { label: 'Year to date', value: data.revenue.ytd },
        ]}
      />

      <SectionTitle>Active users</SectionTitle>
      <Tiles
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
      <h1 className="sv-serif mb-1 text-[2rem] font-bold">Dashboard</h1>
      <p className="mb-6 text-muted">
        Welcome back, {user?.name}. Today at your property.
      </p>

      <SectionTitle>Operations</SectionTitle>
      <Tiles
        tiles={[
          { label: 'Inventory items', value: data.cards.inventory_items },
          { label: 'Occupied rooms', value: data.cards.occupied_rooms },
          { label: 'Guests today', value: data.cards.guests_today },
          { label: 'Open food orders', value: data.cards.open_food_orders },
        ]}
      />

      <SectionTitle>Revenue collected</SectionTitle>
      <Tiles
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
