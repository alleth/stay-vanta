import { lazy, Suspense, useEffect, useMemo, useState } from 'react'
import { Card, Alert, Form, Table, Button, Badge } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { ownerDashboard, adminDashboard, dailyCollection, monthlyVisits } from '../api/reports'
import { formatMoney } from '../utils/format'
import { SkeletonCards, SkeletonTable } from '../components/Skeleton'

// ApexCharts is a large dependency (~200KB gzipped) used only by the admin
// Dashboard's seasonality chart — code-split it so every other role/page
// never pays for it.
const Chart = lazy(() => import('react-apexcharts'))

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
// filter. Admins can widen the window to a whole month/year or a custom date
// range; receptionists may only view one day at a time (the backend enforces
// the same).
function CollectionReport({ allowMonthly }) {
  const now = new Date()
  const [mode, setMode] = useState('day')
  const [date, setDate] = useState(todayStr)
  const [month, setMonth] = useState(now.getMonth() + 1)
  const [year, setYear] = useState(now.getFullYear())
  const [rangeFrom, setRangeFrom] = useState(todayStr)
  const [rangeTo, setRangeTo] = useState(todayStr)
  const [result, setResult] = useState(null)

  // A backwards range (to before from) would 400 — wait for the user to fix
  // it instead of firing a request that's guaranteed to fail.
  const rangeInvalid = mode === 'range' && rangeTo < rangeFrom

  // Single source of truth for what this filter currently asks for — `key`
  // is derived from it (rather than computed separately) so the two can't
  // drift out of sync as modes are added or changed. Memoized so its object
  // identity — and so the effect below — only changes when the underlying
  // inputs actually do.
  const params = useMemo(
    () => (mode === 'month' ? { month, year } : mode === 'range' ? { from: rangeFrom, to: rangeTo } : { date }),
    [mode, month, year, rangeFrom, rangeTo, date],
  )
  // The result is keyed by the filter that produced it, so switching filters
  // shows the loading skeleton (a stale key) without a synchronous setState.
  const key = `${mode}-${JSON.stringify(params)}`

  useEffect(() => {
    if (rangeInvalid) return
    let active = true
    dailyCollection(params)
      .then((c) => { if (active) setResult({ key, data: c }) })
      .catch((err) => { if (active) setResult({ key, error: dashboardError(err) }) })
    return () => { active = false }
  }, [params, rangeInvalid, key])

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
              <option value="range">Date range</option>
            </Form.Select>
          )}
          {mode === 'day' && (
            <Form.Control size="sm" type="date" value={date} style={{ maxWidth: 180 }}
              onChange={(e) => setDate(e.target.value)} />
          )}
          {mode === 'month' && (
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
          {mode === 'range' && (
            <>
              <Form.Control size="sm" type="date" value={rangeFrom} style={{ maxWidth: 180 }}
                onChange={(e) => setRangeFrom(e.target.value)} />
              <span className="text-muted">to</span>
              <Form.Control size="sm" type="date" value={rangeTo} style={{ maxWidth: 180 }}
                onChange={(e) => setRangeTo(e.target.value)} />
            </>
          )}
          {data && (
            <span className="ml-auto text-sm text-muted">
              {data.invoices.count} settled invoice(s) · {data.food_orders.count} paid food order(s)
            </span>
          )}
        </Card.Body>
      </Card>
      {rangeInvalid && <Alert variant="danger">The end date must be on or after the start date.</Alert>}
      {!rangeInvalid && error && <Alert variant="danger">{error}</Alert>}
      {!rangeInvalid && !data && !error && <SkeletonCards count={3} />}
      {!rangeInvalid && data && (
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

// The app's design tokens (frontend/src/index.css @theme), as literal hex
// values — ApexCharts' config takes real color strings, not CSS custom
// properties.
const CHART_ACCENT = '#d99211'
const CHART_MUTED = '#6b7280'

// Seasonality: non-cancelled reservations per month (by check-in date), one
// year at a time. A stat-card widget matching Flowbite's area-chart example
// (headline number + a vs-last-year change badge in the header, a minimal
// smooth gradient-fill area chart, a footer with the period control) —
// rendered with ApexCharts via react-apexcharts, the same engine Flowbite's
// own charts use. Month labels stay on the x-axis (unlike the reference,
// which shows none) since knowing *which* month is the entire point of this
// chart; the busiest month gets a point annotation for the same reason. A
// table-view toggle is the accessible twin, reachable from the footer like
// the reference's link.
function SeasonalityChart() {
  const nowYear = new Date().getFullYear()
  const [year, setYear] = useState(nowYear)
  const [showTable, setShowTable] = useState(false)

  // Keyed by the year that produced it, so switching years shows the
  // loading skeleton (a stale key) without a synchronous setState. The
  // prior year's total is best-effort (for the change badge) — its failure
  // doesn't block the chart.
  const [result, setResult] = useState(null)
  useEffect(() => {
    let active = true
    Promise.allSettled([monthlyVisits(year), monthlyVisits(year - 1)]).then(([cur, prev]) => {
      if (!active) return
      if (cur.status === 'rejected') {
        setResult({ year, error: dashboardError(cur.reason) })
        return
      }
      const prevTotal = prev.status === 'fulfilled'
        ? prev.value.months.reduce((s, m) => s + m.count, 0)
        : null
      setResult({ year, data: cur.value, prevTotal })
    })
    return () => { active = false }
  }, [year])

  const data = result?.year === year ? result.data : null
  const error = result?.year === year ? result.error : null
  const prevTotal = result?.year === year ? result.prevTotal : null

  const years = Array.from({ length: 6 }, (_, i) => nowYear - i)

  const months = data?.months ?? []
  const counts = months.map((m) => m.count)
  const total = counts.reduce((s, c) => s + c, 0)
  const maxCount = counts.length ? Math.max(...counts) : 0
  const peakIndex = maxCount > 0 ? counts.indexOf(maxCount) : -1
  const delta = prevTotal ? Math.round(((total - prevTotal) / prevTotal) * 100) : null

  const series = [{ name: 'Reservations', data: counts }]

  const chartOptions = {
    chart: {
      type: 'area',
      height: 220,
      toolbar: { show: false },
      zoom: { enabled: false },
      fontFamily: 'inherit',
      animations: { enabled: false },
    },
    colors: [CHART_ACCENT],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2 },
    fill: {
      type: 'gradient',
      gradient: { opacityFrom: 0.35, opacityTo: 0, shadeIntensity: 1, stops: [0, 100] },
    },
    grid: { show: false, padding: { left: 8, right: 8 } },
    xaxis: {
      categories: months.map((m) => m.label),
      axisBorder: { show: false },
      axisTicks: { show: false },
      labels: { style: { colors: CHART_MUTED, fontSize: '10px' } },
      crosshairs: { show: true },
    },
    yaxis: { show: false },
    tooltip: {
      y: { formatter: (v) => `${v} visit${v === 1 ? '' : 's'}` },
      x: { show: false },
    },
    markers: { size: 0, hover: { size: 5 } },
    annotations: peakIndex >= 0 ? {
      points: [{
        x: months[peakIndex].label,
        y: counts[peakIndex],
        marker: { size: 5, fillColor: CHART_ACCENT, strokeColor: '#fff', strokeWidth: 2 },
        label: {
          text: `${months[peakIndex].label} · ${counts[peakIndex]}`,
          borderWidth: 0,
          offsetY: -8,
          style: { color: CHART_ACCENT, fontSize: '11px', fontWeight: 600, background: 'transparent' },
        },
      }],
    } : { points: [] },
  }

  return (
    <Card className="mb-6">
      <Card.Body className="flex flex-wrap items-center justify-between gap-3 p-6 pb-0">
        <div>
          <div className="sv-serif text-[1.75rem] font-bold">{data ? total.toLocaleString() : '—'}</div>
          <div className="text-sm text-muted">Reservations in {year}</div>
        </div>
        {delta !== null && (
          <Badge bg={delta >= 0 ? 'success' : 'danger'} className="text-sm">
            {delta >= 0 ? '↑' : '↓'} {Math.abs(delta)}% vs {year - 1}
          </Badge>
        )}
      </Card.Body>

      {error && <Card.Body className="pt-4"><Alert variant="danger" className="mb-0">{error}</Alert></Card.Body>}
      {!error && !data && <Card.Body><SkeletonTable rows={3} /></Card.Body>}

      {!error && data && showTable && (
        <Card.Body>
          <Table>
            <thead>
              <tr>{months.map((m) => <th key={m.month}>{m.label}</th>)}</tr>
            </thead>
            <tbody>
              <tr>
                {months.map((m) => (
                  <td key={m.month} className={m.count === maxCount && maxCount > 0 ? 'font-semibold' : undefined}>
                    {m.count}
                  </td>
                ))}
              </tr>
            </tbody>
          </Table>
        </Card.Body>
      )}

      {!error && data && !showTable && (
        <Card.Body className="pt-2">
          <Suspense fallback={<SkeletonTable rows={3} />}>
            <Chart key={year} type="area" height={220} series={series} options={chartOptions} />
          </Suspense>
        </Card.Body>
      )}

      <Card.Footer className="flex items-center justify-between">
        <Form.Select size="sm" value={year} style={{ width: 'auto' }}
          onChange={(e) => setYear(Number(e.target.value))}>
          {years.map((y) => <option key={y} value={y}>{y}</option>)}
        </Form.Select>
        {data && (
          <Button size="sm" variant="link" onClick={() => setShowTable((s) => !s)}>
            {showTable ? 'View as chart' : 'View as table'} →
          </Button>
        )}
      </Card.Footer>
    </Card>
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

      <SectionTitle>Seasonality</SectionTitle>
      <p className="mb-4 text-sm text-muted">
        Reservations by month (non-cancelled, by check-in date) — spot your busiest season.
      </p>
      <SeasonalityChart />

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
