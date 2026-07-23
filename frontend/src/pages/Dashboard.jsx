import { useEffect, useMemo, useRef, useState } from 'react'
import { Card, Alert, Form, Table, Button } from '../components/ui'
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

// A "nice" round axis top + tick step for a max data value, e.g. 7 -> top 8,
// ticks [0, 2, 4, 6, 8]. Keeps y-axis labels on clean whole numbers — counts
// are always integers, so a small range (0-4, e.g. a brand-new property's
// first year) steps by 1 rather than the general formula's fractional step.
function niceScale(maxValue) {
  const target = Math.max(maxValue, 1)
  if (target <= 4) return { top: target, ticks: Array.from({ length: target + 1 }, (_, i) => i) }
  const rough = target / 4
  const mag = 10 ** Math.floor(Math.log10(rough))
  const norm = rough / mag
  const step = Math.max(1, (norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 5 ? 5 : 10) * mag)
  const top = Math.ceil(target / step) * step
  const ticks = []
  for (let v = 0; v <= top; v += step) ticks.push(Math.round(v))
  return { top, ticks }
}

// Seasonality: non-cancelled reservations per month (by check-in date), one
// year at a time. A plain SVG line chart — no charting library in this repo.
// Single series, so per the design system no legend box is needed (the title
// names it); the busiest month is direct-labeled since that's the one thing
// this chart exists to answer. A table-view toggle is the accessible twin.
function SeasonalityChart() {
  const nowYear = new Date().getFullYear()
  const [year, setYear] = useState(nowYear)
  const [showTable, setShowTable] = useState(false)
  const [hover, setHover] = useState(null)
  const svgRef = useRef(null)

  // Keyed by the year that produced it, so switching years shows the
  // loading skeleton (a stale key) without a synchronous setState.
  const [result, setResult] = useState(null)
  useEffect(() => {
    let active = true
    monthlyVisits(year)
      .then((r) => { if (active) setResult({ year, data: r }) })
      .catch((err) => { if (active) setResult({ year, error: dashboardError(err) }) })
    return () => { active = false }
  }, [year])

  const data = result?.year === year ? result.data : null
  const error = result?.year === year ? result.error : null

  const years = Array.from({ length: 6 }, (_, i) => nowYear - i)

  const W = 720
  const H = 260
  const padL = 34
  const padR = 16
  const padT = 28
  const padB = 28
  const innerW = W - padL - padR
  const innerH = H - padT - padB

  const months = data?.months ?? []
  const counts = months.map((m) => m.count)
  const maxCount = counts.length ? Math.max(...counts) : 0
  const scale = niceScale(maxCount)
  const peakIndex = maxCount > 0 ? counts.indexOf(maxCount) : -1

  const xFor = (i) => padL + (months.length > 1 ? (innerW * i) / (months.length - 1) : innerW / 2)
  const yFor = (v) => padT + innerH - (innerH * v) / (scale.top || 1)

  const points = months.map((m, i) => ({ ...m, x: xFor(i), y: yFor(m.count) }))
  const linePath = points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x},${p.y}`).join(' ')

  function onMove(e) {
    if (!svgRef.current || points.length === 0) return
    const rect = svgRef.current.getBoundingClientRect()
    const px = ((e.clientX - rect.left) / rect.width) * W
    const ratio = innerW > 0 ? (px - padL) / innerW : 0
    const idx = Math.min(points.length - 1, Math.max(0, Math.round(ratio * (points.length - 1))))
    setHover(idx)
  }

  const hovered = hover !== null ? points[hover] : null
  const tooltipW = 96
  const tooltipX = hovered ? Math.min(Math.max(hovered.x - tooltipW / 2, padL), W - padR - tooltipW) : 0

  return (
    <>
      <Card className="mb-4">
        <Card.Body className="flex flex-wrap items-center gap-4 px-4 py-3">
          <Form.Select size="sm" value={year} style={{ width: 'auto' }}
            onChange={(e) => setYear(Number(e.target.value))}>
            {years.map((y) => <option key={y} value={y}>{y}</option>)}
          </Form.Select>
          {data && (
            <Button size="sm" variant="outline-secondary" className="ml-auto"
              onClick={() => setShowTable((s) => !s)}>
              {showTable ? 'View as chart' : 'View as table'}
            </Button>
          )}
        </Card.Body>
      </Card>
      {error && <Alert variant="danger">{error}</Alert>}
      {!error && !data && <SkeletonTable rows={3} />}
      {!error && data && showTable && (
        <Card className="mb-6">
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
        </Card>
      )}
      {!error && data && !showTable && (
        <Card className="mb-6">
          <Card.Body>
            <svg ref={svgRef} viewBox={`0 0 ${W} ${H}`} className="w-full select-none" style={{ maxHeight: 260 }}
              onMouseMove={onMove} onMouseLeave={() => setHover(null)}>
              {scale.ticks.map((t) => (
                <g key={t}>
                  <line x1={padL} x2={W - padR} y1={yFor(t)} y2={yFor(t)} stroke="var(--color-line)" strokeWidth="1" />
                  <text x={padL - 8} y={yFor(t)} textAnchor="end" dominantBaseline="middle"
                    fontSize="10" fill="var(--color-muted)">{t}</text>
                </g>
              ))}

              {points.map((p) => (
                <text key={p.month} x={p.x} y={H - 8} textAnchor="middle" fontSize="10" fill="var(--color-muted)">
                  {p.label}
                </text>
              ))}

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
        </Card>
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
