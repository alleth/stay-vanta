import { useEffect, useMemo, useState } from 'react'
import { Alert, Card, Form, Table } from '../ui'
import { formatMoney } from '../../utils/format'
import { refundMethodLabel } from '../../utils/refunds'
import { collections } from '../../api/finance'
import { SkeletonCards } from '../Skeleton'
import { StatTiles } from '../StatCard'
import { describeError } from '../../utils/apiError'

// Today on the device's own clock (the hotel's), not UTC — toISOString() would
// still say "yesterday" until 8 AM in Manila.
const todayStr = () => new Date().toLocaleDateString('en-CA')
const MONTHS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
]

// Collections: money collected in a day (settled invoices + paid POS sales),
// with a date filter. Managers can widen the window to a whole month or a
// custom date range; Front Desk Staff may only view one day at a time (the
// backend enforces the same).
export function CollectionsReport({ allowMonthly }) {
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
    collections(params)
      .then((c) => { if (active) setResult({ key, data: c }) })
      .catch((err) => { if (active) setResult({ key, error: describeError(err) }) })
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
              {data.invoices.count} settled invoice(s) · {data.food_orders.count} POS sale(s)
              {data.outstanding?.count > 0 && ` · ${data.outstanding.count} outstanding invoice(s)`}
            </span>
          )}
        </Card.Body>
      </Card>
      {rangeInvalid && <Alert variant="danger">The end date must be on or after the start date.</Alert>}
      {!rangeInvalid && error && <Alert variant="danger">{error}</Alert>}
      {!rangeInvalid && !data && !error && <SkeletonCards count={3} />}
      {!rangeInvalid && data && (
        <>
          {/* Cash movement (build step 7c): money in, money back, the difference,
              each on the day it moved. The fallbacks read a server from before 7c-2. */}
          <StatTiles
            money
            className="mb-2"
            tiles={[
              { label: 'Net collected', value: data.net ?? data.total, variant: 'primary' },
              { label: 'Collected', value: data.collected?.total ?? data.total },
              { label: 'Refunded', value: data.refunded?.total ?? 0, variant: data.refunded?.total > 0 ? 'danger' : undefined },
              // Not part of the window above: what's billed right now but not
              // yet collected, until the invoice is settled (Invoices tab).
              { label: 'Outstanding', value: data.outstanding?.total ?? 0 },
            ]}
          />
          <p className="mb-8 text-xs text-muted">
            Collected: {formatMoney(data.invoices.total)} from settled invoices and {formatMoney(data.food_orders.total)} from
            POS sales.{data.refunded?.total > 0 && ' Refunded is money returned to guests on these days.'}
          </p>
          {data.refunds?.length > 0 && <RefundsTable refunds={data.refunds} />}
        </>
      )}
      {!rangeInvalid && data?.outstanding?.total > 0 && (
        <p className="mb-8 text-xs text-muted">
          “Outstanding” is what’s on open invoices right now. It counts as collected once the invoice
          is settled.
        </p>
      )}
    </>
  )
}

// The refunds behind the Refunded figure: who returned what, how and why.
// Refunds from before step 7c have no method or reason on record.
function RefundsTable({ refunds }) {
  return (
    <Card className="mb-8">
      <Card.Header>Refunds</Card.Header>
      <Table hover>
        <thead>
          <tr>
            <th>When</th><th>For</th><th>Method</th><th>By</th><th>Reason</th>
            <th className="text-right">Amount</th>
          </tr>
        </thead>
        <tbody>
          {refunds.map((r) => (
            <tr key={r.id}>
              <td className="whitespace-nowrap text-xs text-muted">{new Date(r.at).toLocaleString()}</td>
              <td>
                {r.kind === 'pos'
                  ? `POS order #${r.order_id}`
                  : `Invoice #${String(r.invoice_id).padStart(4, '0')}${r.guest ? ` · ${r.guest}` : ''}`}
                {r.type === 'refunded_on_cancel' && <div className="text-[11px] text-muted">Downpayment, booking cancelled</div>}
              </td>
              <td>{refundMethodLabel(r.method)}</td>
              <td className="text-xs">{r.recorded === false && !r.actor ? 'Not recorded' : r.actor ?? 'Unknown user'}</td>
              <td className="max-w-[240px] text-xs text-muted">{r.reason ?? '—'}</td>
              <td className="text-right tabular-nums">{formatMoney(r.amount)}</td>
            </tr>
          ))}
        </tbody>
      </Table>
    </Card>
  )
}
