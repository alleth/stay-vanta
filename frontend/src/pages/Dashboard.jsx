import { useEffect, useId, useMemo, useRef, useState } from 'react'
import { Card, Alert, Form, Table, Button, Badge } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { ownerDashboard, adminDashboard, dailyCollection, monthlyVisits } from '../api/reports'
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

// A smooth curve through a set of {x,y} points using monotone cubic
// (Fritsch-Carlson) interpolation — unlike a plain Catmull-Rom spline, it's
// guaranteed not to overshoot beyond the two points on either side of a
// segment. That matters here: a flat run of zero months right next to a
// spike (a common shape for seasonal data) would otherwise curve visibly
// *below* the zero baseline approaching the flat months, which reads as a
// negative count. Sets the tangent to 0 at any local min/max (including
// flat runs) so the curve settles rather than overshooting.
function smoothPath(pts) {
  const n = pts.length
  if (n === 0) return ''
  if (n === 1) return `M${pts[0].x},${pts[0].y}`

  const slope = []
  for (let i = 0; i < n - 1; i++) {
    const dx = pts[i + 1].x - pts[i].x
    slope[i] = dx === 0 ? 0 : (pts[i + 1].y - pts[i].y) / dx
  }
  const tangent = new Array(n)
  tangent[0] = slope[0]
  tangent[n - 1] = slope[n - 2]
  for (let i = 1; i < n - 1; i++) {
    tangent[i] = (slope[i - 1] === 0 || slope[i] === 0 || slope[i - 1] * slope[i] < 0)
      ? 0
      : (slope[i - 1] + slope[i]) / 2
  }
  // Limit each tangent so the curve can't swing past the segment's own
  // endpoints (the standard Fritsch-Carlson overshoot correction).
  for (let i = 0; i < n - 1; i++) {
    if (slope[i] === 0) {
      tangent[i] = 0
      tangent[i + 1] = 0
      continue
    }
    const a = tangent[i] / slope[i]
    const b = tangent[i + 1] / slope[i]
    const h = Math.hypot(a, b)
    if (h > 3) {
      const scale = 3 / h
      tangent[i] = scale * a * slope[i]
      tangent[i + 1] = scale * b * slope[i]
    }
  }

  let d = `M${pts[0].x},${pts[0].y}`
  for (let i = 0; i < n - 1; i++) {
    const p0 = pts[i]
    const p1 = pts[i + 1]
    const dx = p1.x - p0.x
    const c1x = p0.x + dx / 3
    const c1y = p0.y + (tangent[i] * dx) / 3
    const c2x = p1.x - dx / 3
    const c2y = p1.y - (tangent[i + 1] * dx) / 3
    d += ` C${c1x},${c1y} ${c2x},${c2y} ${p1.x},${p1.y}`
  }
  return d
}

// Seasonality: non-cancelled reservations per month (by check-in date), one
// year at a time. A stat-card widget in the style of Flowbite's area-chart
// example (headline number + a vs-last-year change badge, a minimal smooth
// area chart with no axis chrome, a footer with the period control) — built
// as plain SVG since that reference is actually built on ApexCharts and this
// repo has no charting library. Single series, so no legend box is needed
// (the title names it); the busiest month is direct-labeled since that's the
// one thing this chart exists to answer. A table-view toggle is the
// accessible twin, reachable from the footer like the reference's link.
function SeasonalityChart() {
  const nowYear = new Date().getFullYear()
  const [year, setYear] = useState(nowYear)
  const [showTable, setShowTable] = useState(false)
  const [hover, setHover] = useState(null)
  const svgRef = useRef(null)
  const gradientId = useId()

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

  const W = 720
  const H = 220
  const padX = 8
  const padT = 32
  const padB = 24
  const innerW = W - padX * 2
  const innerH = H - padT - padB

  const months = data?.months ?? []
  const counts = months.map((m) => m.count)
  const total = counts.reduce((s, c) => s + c, 0)
  const maxCount = counts.length ? Math.max(...counts) : 0
  const top = Math.max(maxCount, 1) * 1.2
  const peakIndex = maxCount > 0 ? counts.indexOf(maxCount) : -1
  const delta = prevTotal ? Math.round(((total - prevTotal) / prevTotal) * 100) : null

  const xFor = (i) => padX + (months.length > 1 ? (innerW * i) / (months.length - 1) : innerW / 2)
  const yFor = (v) => padT + innerH - (innerH * v) / top

  const points = months.map((m, i) => ({ ...m, x: xFor(i), y: yFor(m.count) }))
  const linePath = smoothPath(points)
  // Area variant: the same smoothed line, closed down to the zero baseline —
  // filled with a gradient fading to transparent, never a flat saturated block.
  const baselineY = yFor(0)
  const areaPath = points.length
    ? `${linePath} L${points[points.length - 1].x},${baselineY} L${points[0].x},${baselineY} Z`
    : ''

  function onMove(e) {
    if (!svgRef.current || points.length === 0) return
    const rect = svgRef.current.getBoundingClientRect()
    const px = ((e.clientX - rect.left) / rect.width) * W
    const ratio = innerW > 0 ? (px - padX) / innerW : 0
    const idx = Math.min(points.length - 1, Math.max(0, Math.round(ratio * (points.length - 1))))
    setHover(idx)
  }

  const hovered = hover !== null ? points[hover] : null
  const tooltipW = 96
  const tooltipX = hovered ? Math.min(Math.max(hovered.x - tooltipW / 2, padX), W - padX - tooltipW) : 0

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
          <svg ref={svgRef} viewBox={`0 0 ${W} ${H}`} className="w-full select-none" style={{ maxHeight: 220 }}
            onMouseMove={onMove} onMouseLeave={() => setHover(null)}>
            <defs>
              <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor="var(--color-accent)" stopOpacity="0.35" />
                <stop offset="100%" stopColor="var(--color-accent)" stopOpacity="0" />
              </linearGradient>
            </defs>

            {points.map((p) => (
              <text key={p.month} x={p.x} y={H - 6} textAnchor="middle" fontSize="10" fill="var(--color-muted)">
                {p.label}
              </text>
            ))}

            <path d={areaPath} fill={`url(#${gradientId})`} stroke="none" />

            {hovered && (
              <line x1={hovered.x} x2={hovered.x} y1={padT} y2={H - padB}
                stroke="var(--color-muted)" strokeWidth="1" strokeDasharray="2,2" opacity="0.5" />
            )}

            <path d={linePath} fill="none" stroke="var(--color-ink)" strokeWidth="2"
              strokeLinejoin="round" strokeLinecap="round" />

            {points.map((p, i) => (
              <circle key={p.month} cx={p.x} cy={p.y} r={i === peakIndex || hover === i ? 5 : 4}
                fill={i === peakIndex ? 'var(--color-accent)' : 'var(--color-ink)'}
                stroke="var(--color-surface)" strokeWidth="2" />
            ))}

            {peakIndex >= 0 && (
              <text x={points[peakIndex].x} y={points[peakIndex].y - 12} textAnchor="middle"
                fontSize="11" fontWeight="600" fill="var(--color-accent)">
                {points[peakIndex].label} · {points[peakIndex].count}
              </text>
            )}

            {hovered && (
              <g transform={`translate(${tooltipX}, ${Math.max(hovered.y - 46, padT - 6)})`}>
                <rect width={tooltipW} height="34" rx="6" fill="var(--color-ink)" />
                <text x={tooltipW / 2} y="14" textAnchor="middle" fontSize="12" fontWeight="700" fill="#fff">
                  {hovered.count} visit{hovered.count === 1 ? '' : 's'}
                </text>
                <text x={tooltipW / 2} y="27" textAnchor="middle" fontSize="10" fill="#c7cad4">
                  {hovered.label} {data.year}
                </text>
              </g>
            )}
          </svg>
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
