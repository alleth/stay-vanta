import { lazy, Suspense, useEffect, useMemo, useState } from 'react'
import {
  Alert, Badge, Button, ButtonGroup, Card, Form, Pagination, Tab, Table, Tabs,
} from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { useProperty } from '../context/PropertyContext'
import { useTheme } from '../context/ThemeContext'
import { adminDashboard, dailyCollection, monthlySummary } from '../api/reports'
import { pageReservations } from '../api/frontdesk'
import { formatMoney } from '../utils/format'
import { SkeletonCards, SkeletonTable } from '../components/Skeleton'
import { StatTiles, SummaryGroup, SummaryRow } from '../components/StatCard'
import { InvoicesPanel } from '../components/Invoices'

// Revenue: financial monitoring, kept off the operations Dashboard — what was
// collected (Collections), what's still owed (To collect), the invoices
// themselves (Invoices — the same panel Front Desk and Food & Orders render)
// and, for an admin, revenue by period and season (Analytics). Receptionists
// get the single-day collection report only; the backend enforces the same.

// ApexCharts is a large dependency (~200KB gzipped) used only by the admin
// Revenue page's seasonality chart (Analytics tab) — code-split it so every
// other role/page/tab never pays for it.
const Chart = lazy(() => import('react-apexcharts'))

// Today on the device's own clock (the hotel's), not UTC — toISOString() would
// still say "yesterday" until 8 AM in Manila.
const todayStr = () => new Date().toLocaleDateString('en-CA')
const MONTHS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
]


// Surface the real failure instead of a blanket message: prefer the API's JSON
// error, fall back to the HTTP status, then to a network-level hint.
function dashboardError(err) {
  const res = err?.response
  if (res) return res.data?.message || `Request failed (${res.status}).`
  return 'Could not reach the server. Please try again.'
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
      <Card className="mb-3">
        <Card.Body className="flex flex-wrap items-center gap-3 px-4 py-3">
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
              {data.outstanding?.count > 0 && ` · ${data.outstanding.count} open invoice(s)`}
            </span>
          )}
        </Card.Body>
      </Card>
      {rangeInvalid && <Alert variant="danger">The end date must be on or after the start date.</Alert>}
      {!rangeInvalid && error && <Alert variant="danger">{error}</Alert>}
      {!rangeInvalid && !data && !error && <SkeletonCards count={3} />}
      {!rangeInvalid && data && (
        <StatTiles
          money
          tiles={[
            { label: 'Total collected', value: data.total },
            { label: 'Invoices settled', value: data.invoices.total },
            { label: 'Food orders paid', value: data.food_orders.total },
            // Not part of the window above: what's charged right now but not
            // yet collected — room charges from Mark paid, food charged to the
            // room — until the invoice is settled (Invoices tab).
            { label: 'Not yet settled', value: data.outstanding?.total ?? 0 },
          ]}
        />
      )}
      {!rangeInvalid && data?.outstanding?.total > 0 && (
        <p className="-mt-5 mb-8 text-xs text-muted">
          “Not yet settled” is what’s on open invoices right now (e.g. reservations marked paid). It
          counts as collected once the invoice is settled on the Invoices tab.
        </p>
      )}
    </>
  )
}

// The app's design tokens (frontend/src/index.css), as literal hex values —
// ApexCharts' config takes real color strings, not CSS custom properties, so
// the chart can't inherit the theme the way every other surface does. Both
// themes' values are mirrored here; keep in sync with @theme and
// :root[data-theme='dark'] in src/index.css.
// Tall enough that the y-axis band doesn't squeeze the plot into a strip.
const CHART_HEIGHT = 260

const CHART_COLORS = {
  light: { accent: '#d99211', muted: '#6b7280', surface: '#ffffff', line: '#e8e8ec', body: '#1a1a1e' },
  dark: { accent: '#e9a62c', muted: '#9ba1aa', surface: '#191b20', line: '#2a2d34', body: '#e7e7ea' },
}

// Seasonality: one year at a time, toggled between two metrics — non-
// cancelled reservations by check-in date ("Guests"), or collected revenue
// ("Revenue", the same settled-invoices + paid-food-orders definition as
// adminDashboard's revenue buckets). Rendered with ApexCharts via
// react-apexcharts.
//
// The anatomy, top to bottom: a headline total with a vs-last-year change
// badge and the metric/year controls that scope this card; a smooth area
// chart — 2px line over a 20%-opacity wash, hairline horizontal grid, ticks
// anchored at zero; then a footer naming the busiest and slowest months with
// the table-view toggle.
//
// Two deliberate choices worth keeping: the y-axis stays *visible* (a chart
// whose values can only be read by hovering fails anyone who doesn't), and
// direct labels are limited to the peak and trough — a number on all twelve
// points is noise. The colour lives on the marks; every label wears a text
// token.
function SeasonalityChart() {
  const { theme } = useTheme()
  const chartColors = CHART_COLORS[theme] ?? CHART_COLORS.light
  const nowYear = new Date().getFullYear()
  const [year, setYear] = useState(nowYear)
  const [metric, setMetric] = useState('visits')
  const [showTable, setShowTable] = useState(false)

  // Keyed by the year that produced it, so switching years shows the
  // loading skeleton (a stale key) without a synchronous setState. Fetches
  // both metrics for both years in one shot (the API returns count + revenue
  // together), so toggling the metric never refetches. The prior year is
  // best-effort (for the change badge) — its failure doesn't block the chart.
  const [result, setResult] = useState(null)
  useEffect(() => {
    let active = true
    Promise.allSettled([monthlySummary(year), monthlySummary(year - 1)]).then(([cur, prev]) => {
      if (!active) return
      if (cur.status === 'rejected') {
        setResult({ year, error: dashboardError(cur.reason) })
        return
      }
      setResult({
        year,
        data: cur.value,
        prevMonths: prev.status === 'fulfilled' ? prev.value.months : null,
      })
    })
    return () => { active = false }
  }, [year])

  // Keep showing the last successful payload while a new year is in flight —
  // the card dims rather than collapsing back to a skeleton, so switching
  // years doesn't bounce the page's layout. Only the very first load has
  // nothing to hold. `dataYear` is the year actually on screen, which is what
  // the header labels, so a dimmed chart never claims to be the new year.
  const data = result?.data ?? null
  const dataYear = result?.year ?? year
  const error = result?.year === year ? result.error : null
  const refreshing = Boolean(data) && result?.year !== year
  const prevMonths = result?.prevMonths ?? null

  const years = Array.from({ length: 6 }, (_, i) => nowYear - i)
  const metricLabel = metric === 'revenue' ? 'Revenue' : 'Guests'
  const pick = (m) => (metric === 'revenue' ? m.revenue : m.count)
  const formatValue = (v) => (metric === 'revenue' ? formatMoney(v) : `${v} visit${v === 1 ? '' : 's'}`)

  // Axis ticks are compact and rounded — the exact figure is a hover, a
  // direct label or the table view away, and long peso strings would crowd
  // the plot.
  const axisLabel = (v) => {
    if (metric !== 'revenue') return Math.round(v).toLocaleString()
    const abs = Math.abs(v)
    if (abs >= 1_000_000) return `₱${(v / 1_000_000).toFixed(1)}M`
    if (abs >= 1_000) return `₱${Math.round(v / 1_000).toLocaleString()}k`
    return `₱${Math.round(v)}`
  }

  const months = data?.months ?? []
  const values = months.map(pick)
  const total = values.reduce((s, v) => s + v, 0)
  const maxVal = values.length ? Math.max(...values) : 0
  const minVal = values.length ? Math.min(...values) : 0
  const peakIndex = maxVal > 0 ? values.indexOf(maxVal) : -1
  // The slow month is worth calling out for either metric, but only when
  // it's actually distinct from the peak.
  const troughIndex = maxVal > 0 && minVal !== maxVal ? values.indexOf(minVal) : -1

  const prevTotal = prevMonths ? prevMonths.reduce((s, m) => s + pick(m), 0) : null
  const delta = prevTotal ? Math.round(((total - prevTotal) / prevTotal) * 100) : null

  const series = [{ name: metricLabel, data: values }]

  // Direct labels on the two months worth calling out. The dot wears the
  // series colour; the text stays in a text token — a light amber label is
  // hard to read against the surface, and colouring text is what the marker
  // beside it is for.
  // A label centred on January or December hangs half its width off the plot
  // and gets clipped — anchor the end months' labels inward instead.
  const labelAnchor = (i) => (i === 0 ? 'start' : i === months.length - 1 ? 'end' : 'middle')

  const annotationPoints = []
  if (peakIndex >= 0) {
    annotationPoints.push({
      x: months[peakIndex].label,
      y: values[peakIndex],
      marker: { size: 5, fillColor: chartColors.accent, strokeColor: chartColors.surface, strokeWidth: 2 },
      label: {
        text: `${months[peakIndex].label} · ${formatValue(values[peakIndex])}`,
        borderWidth: 0,
        offsetY: -8,
        textAnchor: labelAnchor(peakIndex),
        style: { color: chartColors.body, fontSize: '11px', fontWeight: 600, background: 'transparent' },
      },
    })
  }
  if (troughIndex >= 0) {
    annotationPoints.push({
      x: months[troughIndex].label,
      y: values[troughIndex],
      marker: { size: 5, fillColor: chartColors.muted, strokeColor: chartColors.surface, strokeWidth: 2 },
      label: {
        text: `${months[troughIndex].label} · ${formatValue(values[troughIndex])}`,
        borderWidth: 0,
        offsetY: -8,
        textAnchor: labelAnchor(troughIndex),
        style: { color: chartColors.muted, fontSize: '11px', fontWeight: 600, background: 'transparent' },
      },
    })
  }

  const chartOptions = {
    chart: {
      type: 'area',
      height: CHART_HEIGHT,
      toolbar: { show: false },
      zoom: { enabled: false },
      fontFamily: 'inherit',
      animations: { enabled: false },
      // The card behind it already paints the surface; ApexCharts' own dark
      // mode would otherwise lay its slightly-different grey over it.
      background: 'transparent',
    },
    theme: { mode: theme },
    colors: [chartColors.accent],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2, lineCap: 'round' },
    // A wash, not a saturated block: the line carries the shape, the fill
    // only hints at the volume under it.
    fill: {
      type: 'gradient',
      gradient: { opacityFrom: 0.2, opacityTo: 0, shadeIntensity: 1, stops: [0, 100] },
    },
    // Hairline horizontal rules, one step off the surface — enough to read a
    // value off the axis without the grid competing with the data. Solid,
    // never dashed (dashes read as "threshold" when it's just a grid).
    grid: {
      show: true,
      borderColor: chartColors.line,
      strokeDashArray: 0,
      xaxis: { lines: { show: false } },
      yaxis: { lines: { show: true } },
      padding: { left: 4, right: 12, top: 0 },
    },
    xaxis: {
      categories: months.map((m) => m.label),
      axisBorder: { show: false },
      axisTicks: { show: false },
      labels: { style: { colors: chartColors.muted, fontSize: '11px' } },
      crosshairs: { stroke: { color: chartColors.line, width: 1, dashArray: 0 } },
      tooltip: { enabled: false },
    },
    // Anchored at zero: an area chart on a truncated axis overstates every
    // peak. `forceNiceScale` keeps the ticks on round numbers, and a small
    // tick count keeps them from repeating when a quiet year's counts are
    // single digits.
    yaxis: {
      min: 0,
      forceNiceScale: true,
      tickAmount: maxVal > 0 && maxVal < 4 ? maxVal : 4,
      labels: {
        formatter: axisLabel,
        style: { colors: chartColors.muted, fontSize: '11px' },
      },
    },
    tooltip: {
      // The month is the tooltip's own heading, so the series name in front
      // of the value is just noise.
      y: { formatter: formatValue, title: { formatter: () => '' } },
      marker: { show: false },
    },
    markers: { size: 0, strokeWidth: 2, strokeColors: chartColors.surface, hover: { size: 6 } },
    annotations: { points: annotationPoints },
  }

  return (
    <Card className="mb-8">
      <Card.Body className="flex flex-wrap items-start justify-between gap-3 p-4 pb-0">
        <div className="min-w-0">
          <div className="text-xs font-medium uppercase tracking-[0.04em] text-muted">
            {metricLabel} · {dataYear}
          </div>
          <div className="mt-1 flex flex-wrap items-baseline gap-2">
            {/* Proportional figures, not tabular-nums: every digit padded to
                the width of a '0' makes a standalone headline look loose. */}
            <span className="sv-serif text-[1.75rem] font-bold leading-none">
              {data ? (metric === 'revenue' ? formatMoney(total) : total.toLocaleString()) : '—'}
            </span>
            {delta !== null && (
              <Badge bg={delta >= 0 ? 'success' : 'danger'}>
                {delta >= 0 ? '↑' : '↓'} {Math.abs(delta)}% vs {dataYear - 1}
              </Badge>
            )}
          </div>
        </div>
        <div className="flex items-center gap-2">
          {/* Two mutually-exclusive metrics read better as a segmented
              control than as a dropdown you have to open to see the choice. */}
          <ButtonGroup>
            <Button size="sm" variant={metric === 'visits' ? 'secondary' : 'outline-secondary'}
              onClick={() => setMetric('visits')}>
              Guests
            </Button>
            <Button size="sm" variant={metric === 'revenue' ? 'secondary' : 'outline-secondary'}
              onClick={() => setMetric('revenue')}>
              Revenue
            </Button>
          </ButtonGroup>
          <Form.Select size="sm" value={year} style={{ width: 'auto' }}
            onChange={(e) => setYear(Number(e.target.value))}>
            {years.map((y) => <option key={y} value={y}>{y}</option>)}
          </Form.Select>
        </div>
      </Card.Body>

      {error && <Card.Body className="pt-4"><Alert variant="danger" className="mb-0">{error}</Alert></Card.Body>}
      {!error && !data && <Card.Body><SkeletonTable rows={3} /></Card.Body>}

      {!error && data && (
        <Card.Body className={`pt-2 transition-opacity duration-200 ${refreshing ? 'opacity-40' : ''}`}>
          {showTable ? (
            // The accessible twin of the chart — a month per row reads far
            // better than twelve columns scrolling sideways.
            <Table>
              <thead>
                <tr><th>Month</th><th className="text-right">{metricLabel}</th></tr>
              </thead>
              <tbody>
                {months.map((m, i) => (
                  <tr key={m.month}>
                    <td>{m.label}</td>
                    <td className={`tabular-nums text-right ${
                      values[i] === maxVal && maxVal > 0 ? 'font-semibold' : ''
                    }`}>
                      {metric === 'revenue' ? formatMoney(values[i]) : values[i].toLocaleString()}
                    </td>
                  </tr>
                ))}
              </tbody>
            </Table>
          ) : (
            <Suspense fallback={<SkeletonTable rows={3} />}>
              {/* Remount on a theme flip too — ApexCharts doesn't reliably
                  re-theme an existing instance from an options update. */}
              <Chart key={`${dataYear}-${metric}-${theme}`} type="area"
                height={CHART_HEIGHT} series={series} options={chartOptions} />
            </Suspense>
          )}
        </Card.Body>
      )}

      <Card.Footer className="flex flex-wrap items-center justify-between gap-2">
        <div className="text-xs text-muted">
          {data && peakIndex >= 0 && (
            <>
              Busiest:{' '}
              <span className="font-medium text-body">
                {months[peakIndex].label} · {formatValue(values[peakIndex])}
              </span>
              {troughIndex >= 0 && (
                <>
                  {' · '}Slowest:{' '}
                  <span className="font-medium text-body">
                    {months[troughIndex].label} · {formatValue(values[troughIndex])}
                  </span>
                </>
              )}
            </>
          )}
        </div>
        {data && (
          <Button size="sm" variant="link" onClick={() => setShowTable((s) => !s)}>
            {showTable ? 'View as chart' : 'View as table'} →
          </Button>
        )}
      </Card.Footer>
    </Card>
  )
}


const UNPAID_PER_PAGE = 25

const STAY_STATUS = {
  booked: { bg: 'info', label: 'booked' },
  checked_in: { bg: 'success', label: 'checked in' },
  checked_out: { bg: 'secondary', label: 'checked out' },
}

const shortDate = (ymd) => (ymd
  ? new Date(`${String(ymd).slice(0, 10)}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
  : '—')

function SectionTitle({ children }) {
  return <h2 className="mb-3 text-xs font-semibold uppercase tracking-[0.04em] text-muted">{children}</h2>
}

// What's charged or booked but not yet collected: the open-invoice balance,
// and every non-cancelled reservation still marked unpaid (the same set
// Front Desk's "unpaid" figure counts), with where its room charge stands.
// Marking paid happens on Front Desk; settling, on the Invoices tab.
function ToCollect({ propertyId, outstanding, onOpenInvoices }) {
  const [page, setPage] = useState(1)
  const [result, setResult] = useState(null)

  useEffect(() => {
    let active = true
    pageReservations(propertyId, { payment_status: 'unpaid', limit: UNPAID_PER_PAGE, page })
      .then((r) => { if (active) setResult({ page, data: r }) })
      .catch((err) => { if (active) setResult({ page, error: dashboardError(err) }) })
    return () => { active = false }
  }, [propertyId, page])

  // Keyed by the page that produced it, so paging shows the skeleton without
  // a synchronous setState.
  const data = result?.page === page ? result.data : null
  const error = result?.page === page ? result.error : null
  const total = data?.total ?? 0
  const pages = Math.max(1, Math.ceil(total / UNPAID_PER_PAGE))
  const openCount = outstanding?.count ?? 0

  return (
    <>
      <div className="mb-4 grid grid-cols-1 gap-3 md:grid-cols-2">
        <SummaryGroup label="Open invoices" highlight={openCount > 0}>
          <div className="sv-serif text-xl font-bold tabular-nums">{formatMoney(outstanding?.total ?? 0)}</div>
          <SummaryRow value={openCount} label={`invoice${openCount === 1 ? '' : 's'} to settle`}
            variant={openCount > 0 ? 'warning' : 'secondary'}
            onClick={onOpenInvoices} title="Open the Invoices tab" />
        </SummaryGroup>
        <SummaryGroup label="Unpaid reservations" highlight={total > 0}>
          <div className="sv-serif text-xl font-bold tabular-nums">{data ? total : '—'}</div>
          <p className="mb-0 mt-1 text-sm text-muted">
            Not cancelled and still marked unpaid — listed below. Mark them paid on Front Desk.
          </p>
        </SummaryGroup>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {!error && !data && <SkeletonTable rows={5} />}
      {data && (
        <Card>
          {data.reservations.length === 0 ? (
            <p className="px-4 py-8 text-center text-sm text-muted">
              <span className="text-emerald-700 dark:text-emerald-400">✓</span> No unpaid reservations.
            </p>
          ) : (
            <Table hover>
              <thead>
                <tr>
                  <th>Guest</th>
                  <th>Room</th>
                  <th>Stay</th>
                  <th>Status</th>
                  <th>Room charge</th>
                  <th className="text-right">Amount</th>
                </tr>
              </thead>
              <tbody>
                {data.reservations.map((r) => (
                  <tr key={r.id}>
                    <td>{r.guest?.full_name ?? <span className="text-muted">No guest on file</span>}</td>
                    <td className="tabular-nums">{r.room?.room_number ?? '—'}</td>
                    <td className="whitespace-nowrap tabular-nums">
                      {shortDate(r.check_in)} – {shortDate(r.check_out)}
                    </td>
                    <td>
                      <Badge bg={STAY_STATUS[r.status]?.bg ?? 'secondary'}>
                        {STAY_STATUS[r.status]?.label ?? r.status}
                      </Badge>
                    </td>
                    <td className="text-sm text-muted">
                      {r.room_charge_invoice === 'open' ? 'on an open invoice' : 'not posted yet'}
                    </td>
                    <td className="text-right tabular-nums">{formatMoney(r.quote?.total)}</td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </Card>
      )}
      {total > UNPAID_PER_PAGE && (
        <div className="mt-3 flex items-center justify-end gap-3">
          <span className="text-sm text-muted">Page {page} of {pages} · {total} reservation(s)</span>
          <Pagination>
            <Pagination.Prev disabled={page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))} />
            <Pagination.Next disabled={page >= pages} onClick={() => setPage((p) => Math.min(pages, p + 1))} />
          </Pagination>
        </div>
      )}
    </>
  )
}

// Admin only: collected revenue by period, then the seasonality chart.
function Analytics() {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    adminDashboard().then(setData).catch((err) => setError(dashboardError(err)))
  }, [])

  return (
    <>
      <SectionTitle>Revenue collected</SectionTitle>
      {error && <Alert variant="danger">{error}</Alert>}
      {!error && !data && <SkeletonCards count={4} />}
      {data && (
        <StatTiles
          money
          tiles={[
            { label: 'This week', value: data.revenue.week },
            { label: 'This month', value: data.revenue.month },
            { label: 'Year to date', value: data.revenue.ytd },
            { label: 'All time', value: data.revenue.all_time },
          ]}
        />
      )}
      <SectionTitle>Seasonality</SectionTitle>
      <SeasonalityChart />
    </>
  )
}

export default function Revenue() {
  const { role } = useAuth()
  const { propertyId } = useProperty()
  const isAdmin = role === 'admin'
  const [tab, setTab] = useState('collections')
  // The open-invoice balance, for the To collect tab's title and card; re-read
  // after an invoice is settled so the figure follows.
  const [outstanding, setOutstanding] = useState(null)
  const [settledAt, setSettledAt] = useState(0)

  useEffect(() => {
    let active = true
    dailyCollection()
      .then((c) => { if (active) setOutstanding(c.outstanding) })
      .catch(() => { if (active) setOutstanding(null) })
    return () => { active = false }
  }, [settledAt])

  if (!propertyId) return <Alert variant="info">Select a property to view its revenue.</Alert>

  return (
    <div>
      <h1 className="mb-4 text-2xl font-bold">Revenue</h1>
      <Tabs activeKey={tab} onSelect={setTab} className="mb-4">
        <Tab eventKey="collections" title="Collections">
          <CollectionReport allowMonthly={isAdmin} />
        </Tab>
        <Tab eventKey="to_collect"
          title={outstanding?.count > 0 ? `To collect (${outstanding.count})` : 'To collect'}>
          <ToCollect propertyId={propertyId} outstanding={outstanding} onOpenInvoices={() => setTab('invoices')} />
        </Tab>
        <Tab eventKey="invoices" title="Invoices">
          <InvoicesPanel propertyId={propertyId} onSettled={() => setSettledAt(Date.now())} />
        </Tab>
        {isAdmin && (
          <Tab eventKey="analytics" title="Analytics">
            <Analytics />
          </Tab>
        )}
      </Tabs>
    </div>
  )
}
