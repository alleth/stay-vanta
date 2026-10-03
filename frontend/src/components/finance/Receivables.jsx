import { useEffect, useState } from 'react'
import { Alert, Badge, Card, Pagination, Table } from '../ui'
import { pageReservations } from '../../api/frontdesk'
import { formatMoney } from '../../utils/format'
import { SkeletonTable } from '../Skeleton'
import { SummaryGroup, SummaryRow } from '../StatCard'
import { describeError } from '../../utils/apiError'

const PER_PAGE = 25

const STAY_STATUS = {
  checked_in: { bg: 'success', label: 'checked in' },
  checked_out: { bg: 'secondary', label: 'checked out' },
}

const shortDate = (ymd) => (ymd
  ? new Date(`${String(ymd).slice(0, 10)}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
  : '—')

// Receivables: what's owed but not yet collected. Two parts:
// - Outstanding invoices: the open-invoice balance (posted room charges, food
//   charged to a room…), collected once settled on the Invoices tab.
// - Not billed: stays that have started (checked in or out) with no room charge
//   posted — the same set as Front Desk's "not billed" figure. Future bookings
//   aren't receivables yet, so they're not here. The charge is posted on Front
//   Desk ("Post room charge"), or automatically at check-out.
export function Receivables({ propertyId, outstanding, onOpenInvoices }) {
  const [page, setPage] = useState(1)
  const [result, setResult] = useState(null)

  useEffect(() => {
    let active = true
    pageReservations(propertyId, { billing: 'not_billed', limit: PER_PAGE, page })
      .then((r) => { if (active) setResult({ page, data: r }) })
      .catch((err) => { if (active) setResult({ page, error: describeError(err) }) })
    return () => { active = false }
  }, [propertyId, page])

  // Keyed by the page that produced it, so paging shows the skeleton without
  // a synchronous setState.
  const data = result?.page === page ? result.data : null
  const error = result?.page === page ? result.error : null
  const total = data?.total ?? 0
  const pages = Math.max(1, Math.ceil(total / PER_PAGE))
  const openCount = outstanding?.count ?? 0

  return (
    <>
      <div className="mb-4 grid grid-cols-1 gap-3 md:grid-cols-2">
        <SummaryGroup label="Outstanding invoices" highlight={openCount > 0}>
          <div className="sv-serif text-xl font-bold tabular-nums">{formatMoney(outstanding?.total ?? 0)}</div>
          <SummaryRow value={openCount} label={`invoice${openCount === 1 ? '' : 's'} to settle`}
            variant={openCount > 0 ? 'warning' : 'secondary'}
            onClick={onOpenInvoices} title="Open the Invoices tab" />
        </SummaryGroup>
        <SummaryGroup label="Not billed" highlight={total > 0}>
          <div className="sv-serif text-xl font-bold tabular-nums">{data ? total : '—'}</div>
          <p className="mb-0 mt-1 text-sm text-muted">
            Started stays with no room charge posted, listed below. Post the room charge on Front Desk.
          </p>
        </SummaryGroup>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {!error && !data && <SkeletonTable rows={5} />}
      {data && (
        <Card>
          {data.reservations.length === 0 ? (
            <p className="px-4 py-8 text-center text-sm text-muted">
              <span className="text-emerald-700 dark:text-emerald-400">✓</span> Every started stay is billed.
            </p>
          ) : (
            <Table hover>
              <thead>
                <tr>
                  <th>Guest</th>
                  <th>Room</th>
                  <th>Stay</th>
                  <th>Status</th>
                  <th className="text-right">Estimated charge</th>
                </tr>
              </thead>
              <tbody>
                {data.reservations.map((r) => (
                  <tr key={r.id}>
                    <td>
                      {r.guest?.full_name ?? (
                        <span className="text-amber-700 dark:text-amber-400"
                          title="A room charge can only be posted to a guest's invoice.">
                          No guest: add one to bill this stay
                        </span>
                      )}
                    </td>
                    <td className="tabular-nums">{r.room?.room_number ?? '—'}</td>
                    <td className="whitespace-nowrap tabular-nums">
                      {shortDate(r.check_in)} – {shortDate(r.check_out)}
                    </td>
                    <td>
                      <Badge bg={STAY_STATUS[r.status]?.bg ?? 'secondary'}>
                        {STAY_STATUS[r.status]?.label ?? r.status}
                      </Badge>
                    </td>
                    <td className="text-right tabular-nums">{formatMoney(r.quote?.total)}</td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </Card>
      )}
      {total > PER_PAGE && (
        <div className="mt-3 flex items-center justify-end gap-3">
          <span className="text-sm text-muted">Page {page} of {pages} · {total} stay(s)</span>
          <Pagination>
            <Pagination.Prev disabled={page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))} />
            <Pagination.Next disabled={page >= pages} onClick={() => setPage((p) => Math.min(pages, p + 1))} />
          </Pagination>
        </div>
      )}
    </>
  )
}
