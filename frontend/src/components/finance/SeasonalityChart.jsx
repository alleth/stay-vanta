import { lazy, Suspense, useEffect, useState } from 'react'
import { Alert, Badge, Button, ButtonGroup, Card, Form, Table } from '../ui'
import { useTheme } from '../../context/ThemeContext'
import { seasonality } from '../../api/finance'
import { formatMoney } from '../../utils/format'
import { SkeletonTable } from '../Skeleton'
import { describeError } from '../../utils/apiError'

// ApexCharts is a large dependency (~200KB gzipped) used only by this chart
// (Finance → Analytics, Manager only) — code-split so every other role, page
// and tab never pays for it.
const Chart = lazy(() => import('react-apexcharts'))

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
// ("Collected", the same settled-invoices + paid-POS-sales definition as
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
export function SeasonalityChart() {
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
    Promise.allSettled([seasonality(year), seasonality(year - 1)]).then(([cur, prev]) => {
      if (!active) return
      if (cur.status === 'rejected') {
        setResult({ year, error: describeError(cur.reason) })
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
  const metricLabel = metric === 'revenue' ? 'Collected' : 'Guests'
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
              Collected
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
