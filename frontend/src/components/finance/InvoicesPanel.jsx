import { useCallback, useEffect, useMemo, useState } from 'react'
import { Card, Table, Button, Badge, Modal, Form, Alert, Spinner } from '../ui'
import { formatMoney } from '../../utils/format'
import { listInvoices, getInvoice, settleInvoice, reverseInvoiceLine, refundInvoice } from '../../api/food'
import { SkeletonTableRows, Skeleton } from '../Skeleton'
import { describeError } from '../../utils/apiError'
import { useAuth } from '../../context/AuthContext'
import { P } from '../../auth/permissions'
import ReasonModal from '../ReasonModal'
import RefundMethodSelect from '../RefundMethodSelect'
import { newRefundKey, refundMethodLabel } from '../../utils/refunds'

// The guests' invoices — the list, the folio view and Settle — as one panel,
// rendered as a tab by Front Desk and Finance. Settling is where
// a stay's money actually counts as collected (and where the SI/OR booklet
// numbers are stamped), and it's the front desk that takes the payment at
// check-out, so it belongs there as much as next to food. One component so
// the two tabs can't drift apart.

// Philippines standard VAT, assumed already included in prices (shown as a
// breakdown on invoices/receipts, not added on top).
const VAT_RATE = 0.12
const vatBreakdown = (total) => {
  const vatable = Number(total) / (1 + VAT_RATE)
  return { vatable, vat: Number(total) - vatable }
}

// Today on the device's own clock (the hotel's), not UTC.
const todayStr = () => new Date().toLocaleDateString('en-CA')
const fmtDateTime = (s) => (s ? new Date(s).toLocaleString() : '—')

// Who did it, from the invoice's ledger. History imported from before the
// ledger existed has no actor, and we say so rather than guess.
const actorLabel = (name, recorded) => (recorded === false ? 'Not recorded' : name ?? 'Unknown user')

const EVENT_LABELS = {
  opened: 'Invoice opened',
  line_added: 'Line added',
  line_reversed: 'Line reversed',
  line_reversed_on_cancel: 'Reversed on cancellation',
  settled: 'Settled',
  settled_on_creation: 'Settled on creation',
  refund_recorded: 'Refund recorded (before 7c)',
  refunded: 'Refunded',
  refunded_on_cancel: 'Downpayment refunded on cancellation',
}

// `onSettled` lets the host page refresh whatever depends on an invoice being
// settled (e.g. Front Desk's "invoice not settled" flag).
export function InvoicesPanel({ propertyId, onSettled }) {
  // Date-filtered, but open tabs always show.
  const [invoices, setInvoices] = useState([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  const [date, setDate] = useState(todayStr)
  const [all, setAll] = useState(false)
  const [modal, setModal] = useState(null) // {type:'invoice'|'settle', id}

  const load = useCallback(async () => {
    if (!propertyId) return
    setLoading(true)
    try {
      setInvoices(await listInvoices(propertyId, { date: all ? 'all' : date }))
      setError(null)
    } catch {
      setError('Could not load invoices.')
    } finally {
      setLoading(false)
    }
  }, [propertyId, date, all])

  // eslint-disable-next-line react-hooks/set-state-in-effect
  useEffect(() => { load() }, [load])

  return (
    <>
      {error && <Alert variant="danger" dismissible onClose={() => setError(null)}>{error}</Alert>}
      <Card className="mb-2">
        <Card.Body className="flex flex-wrap items-end gap-4 p-4">
          <Form.Group>
            <Form.Label className="mb-1 text-muted">Date</Form.Label>
            <Form.Control type="date" size="sm" value={date} disabled={all} style={{ width: 'auto' }}
              onChange={(e) => setDate(e.target.value)} />
          </Form.Group>
          <Form.Check type="checkbox" label="All dates" className="mb-1" checked={all}
            onChange={(e) => setAll(e.target.checked)} />
          <span className="mb-1 text-sm text-muted">Open tabs always show.</span>
        </Card.Body>
      </Card>
      <Card>
        <Table hover>
          <thead>
            <tr>
              <th>#</th><th>Guest</th><th>Opened</th><th>Charges</th>
              <th className="text-right">Total</th><th>Status</th>
              <th className="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {loading && <SkeletonTableRows rows={4} cols={7} />}
            {!loading && invoices.length === 0 && (
              <tr><td colSpan={7} className="py-6 text-center text-muted">No invoices to show.</td></tr>
            )}
            {!loading && invoices.map((inv) => (
              <tr key={inv.id}>
                <td className="text-muted">
                  {String(inv.id).padStart(4, '0')}
                  {inv.invoice_number && (
                    <div className="whitespace-nowrap text-[11px]">SI {inv.invoice_number}</div>
                  )}
                  {inv.or_number && (
                    <div className="whitespace-nowrap text-[11px]">OR {inv.or_number}</div>
                  )}
                </td>
                <td className="font-semibold">{inv.guest?.full_name ?? '—'}</td>
                <td className="whitespace-nowrap text-xs text-muted">{fmtDateTime(inv.created)}</td>
                <td className="max-w-[260px]">
                  <span className="block truncate text-xs text-muted">
                    {inv.invoice_lines?.length
                      ? `${inv.invoice_lines.length} line(s) · ${inv.invoice_lines.map((l) => l.description).join(', ')}`
                      : 'No charges yet'}
                  </span>
                </td>
                <td className="text-right font-semibold">{formatMoney(inv.total)}</td>
                <td>
                  <Badge bg={inv.status === 'open' ? 'warning' : 'success'}>{inv.status}</Badge>
                  {inv.settled_at && (
                    <div className="mt-0.5 whitespace-nowrap text-[11px] text-muted">
                      {fmtDateTime(inv.settled_at)}
                    </div>
                  )}
                  {inv.settled_by && (
                    <div className="whitespace-nowrap text-[11px] text-muted">by {actorLabel(inv.settled_by.name, inv.settled_by.recorded)}</div>
                  )}
                </td>
                <td className="whitespace-nowrap text-right">
                  <Button size="sm" variant="outline-secondary" className="mr-1"
                    onClick={() => setModal({ type: 'invoice', id: inv.id })}>View</Button>
                  {inv.status === 'open' && (
                    <Button size="sm" variant="outline-success"
                      onClick={() => setModal({ type: 'settle', id: inv.id })}>
                      Settle
                    </Button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </Table>
      </Card>

      {modal?.type === 'invoice' && (
        <InvoiceModal id={modal.id} onClose={() => setModal(null)} onChanged={load} />
      )}
      {modal?.type === 'settle' && (
        <SettleModal id={modal.id} onClose={() => setModal(null)}
          onSettled={() => { setModal(null); load(); onSettled?.() }} />
      )}
    </>
  )
}

// Charge groups shown on the invoice, in display order. The `downpayment`
// group collects all downpayment lines: the collection itself, the credit
// applied at check-out, and any cancellation refund.
const LINE_GROUPS = {
  reservation: 'Room & stay',
  downpayment: 'Downpayment',
  early_check_in: 'Extra charges',
  food_order: 'POS',
  other: 'Other charges',
}

function InvoiceModal({ id, onClose, onChanged }) {
  const { can } = useAuth()
  const [invoice, setInvoice] = useState(null)
  const [error, setError] = useState(null)
  const [reversing, setReversing] = useState(null) // the line a Manager is reversing

  useEffect(() => {
    getInvoice(id).then(setInvoice).catch((ex) => setError(describeError(ex, 'Could not load the invoice.')))
  }, [id])

  // The ledger event that put each line on the invoice (added, or the
  // reversal that created a negative line), and which lines were reversed.
  const { eventForLine, reversedBy } = useMemo(() => {
    const eventForLine = {}
    for (const e of invoice?.history ?? []) {
      if (e.line_id !== null) eventForLine[e.line_id] = e
    }
    const reversedBy = {}
    for (const l of invoice?.invoice_lines ?? []) {
      if (l.reverses_line_id) reversedBy[l.reverses_line_id] = l
    }
    return { eventForLine, reversedBy }
  }, [invoice])

  // Group lines by source_type so the invoice reads like a folio; a reversal
  // sits in its original's group, right after it.
  const groups = useMemo(() => {
    const lines = invoice?.invoice_lines ?? []
    const byId = Object.fromEntries(lines.map((l) => [l.id, l]))
    const kindOf = (l) => (l.reverses_line_id && byId[l.reverses_line_id]
      ? byId[l.reverses_line_id].source_type : l.source_type)
    const known = ['reservation', 'early_check_in', 'food_order']
    const isDownpayment = (l) => kindOf(l).startsWith('downpayment')
    const ordered = lines.filter((l) => !l.reverses_line_id || !byId[l.reverses_line_id])
      .flatMap((l) => (reversedBy[l.id] ? [l, reversedBy[l.id]] : [l]))
    return Object.entries(LINE_GROUPS)
      .map(([key, label]) => {
        const rows = key === 'other'
          ? ordered.filter((l) => !known.includes(kindOf(l)) && !isDownpayment(l))
          : key === 'downpayment'
            ? ordered.filter(isDownpayment)
            : ordered.filter((l) => kindOf(l) === key)
        return { key, label, rows, subtotal: rows.reduce((s, l) => s + Number(l.amount), 0) }
      })
      .filter((g) => g.rows.length > 0)
  }, [invoice, reversedBy])

  const settled = invoice?.status === 'settled'
  // Elevated: a Manager, with a reason, on an open invoice. The backend
  // refuses everything else the same way.
  const canReverse = can(P.FINANCE_INVOICE_REVERSE) && invoice?.status === 'open'

  // Money back on a settled invoice (build step 7c): a Manager, with a reason
  // and method; recorded as its own event, the invoice never changes.
  const canRefund = can(P.FINANCE_INVOICE_REFUND) && settled && Number(invoice?.refundable ?? 0) > 0
  const [refund, setRefund] = useState(null) // {amount, method, key} while the dialog is open
  const refundAmount = Number(refund?.amount)
  const refundReady = refund !== null && refund.method !== '' && refundAmount > 0
    && refundAmount <= Number(invoice?.refundable ?? 0)

  async function recordRefund(reason) {
    const updated = await refundInvoice(invoice.id, {
      amount: refundAmount, method: refund.method, reason, refundKey: refund.key,
    })
    setRefund(null)
    setInvoice(updated)
    onChanged?.()
  }

  async function reverse(reason) {
    await reverseInvoiceLine(invoice.id, reversing.id, reason)
    setReversing(null)
    setInvoice(await getInvoice(id))
    onChanged?.()
  }

  return (
    <>
    <Modal show={reversing === null && refund === null} onHide={onClose} centered size="lg">
      <Modal.Header closeButton className="border-0 px-6 pt-4 pb-0" />
      <Modal.Body className="px-6 pt-0 pb-6">
        {error && <Alert variant="danger">{error}</Alert>}
        {!invoice && !error && (
          <div className="space-y-3">
            <Skeleton className="h-8 w-1/3" />
            <Skeleton className="h-5 w-1/2" />
            <Skeleton className="h-5 w-full" />
            <Skeleton className="h-5 w-full" />
            <Skeleton className="h-5 w-2/3" />
          </div>
        )}
        {invoice && (
          <div>
            {/* ---- Heading: number + status ---- */}
            <div className="flex items-start justify-between gap-3">
              <div>
                <div className="text-[11px] font-semibold uppercase tracking-[0.18em] text-muted">
                  Invoice
                </div>
                <div className="text-2xl font-bold tracking-tight">
                  #{String(invoice.id).padStart(4, '0')}
                </div>
              </div>
              <span className={`rounded-full px-3 py-1 text-xs font-semibold ${
                settled
                  ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                  : 'bg-accent-soft text-accent'
              }`}>
                {settled ? 'Settled' : 'Open tab'}
              </span>
            </div>

            {/* ---- Meta: billed to + dates ---- */}
            <div className="mt-6 grid grid-cols-1 gap-4 rounded-xl border border-line bg-subtle p-4 sm:grid-cols-3">
              <div>
                <div className="text-[11px] font-semibold uppercase tracking-wide text-muted">Billed to</div>
                <div className="mt-0.5 text-sm font-semibold">{invoice.guest?.full_name ?? 'Walk-in guest'}</div>
                {(invoice.guest?.contact_number || invoice.guest?.email) && (
                  <div className="text-xs text-muted">
                    {[invoice.guest.contact_number, invoice.guest.email].filter(Boolean).join(' · ')}
                  </div>
                )}
              </div>
              <div>
                <div className="text-[11px] font-semibold uppercase tracking-wide text-muted">Opened</div>
                <div className="mt-0.5 text-sm">{fmtDateTime(invoice.created)}</div>
              </div>
              <div>
                <div className="text-[11px] font-semibold uppercase tracking-wide text-muted">Settled</div>
                <div className="mt-0.5 text-sm">{invoice.settled_at ? fmtDateTime(invoice.settled_at) : '—'}</div>
                {(invoice.invoice_number || invoice.or_number) && (
                  <div className="mt-0.5 text-xs text-muted">
                    {invoice.invoice_number && <div>Sales Invoice {invoice.invoice_number}</div>}
                    {invoice.or_number && <div>Official Receipt {invoice.or_number}</div>}
                  </div>
                )}
              </div>
            </div>

            {/* ---- Charges, grouped ---- */}
            {groups.length === 0 && (
              <p className="mt-6 mb-0 text-sm text-muted">No charges on this invoice yet.</p>
            )}
            {groups.map((g) => (
              <div key={g.key} className="mt-6">
                <div className="flex items-baseline justify-between border-b border-line pb-1">
                  <span className="text-[11px] font-semibold uppercase tracking-wide text-muted">
                    {g.label}
                  </span>
                  <span className="text-xs text-muted">{g.rows.length} item(s)</span>
                </div>
                {g.rows.map((l) => {
                  const ev = eventForLine[l.id]
                  const isReversal = Boolean(l.reverses_line_id)
                  const reversed = Boolean(reversedBy[l.id])
                  return (
                    <div key={l.id} className={`flex items-center justify-between gap-3 border-b border-dashed border-line py-2 ${isReversal ? 'pl-4' : ''}`}>
                      <div className="min-w-0">
                        <div className={`text-sm ${reversed ? 'text-muted line-through' : ''}`}>
                          {l.description}
                          {reversed && <Badge bg="secondary" className="ml-2 no-underline">Reversed</Badge>}
                        </div>
                        <div className="text-[11px] text-muted">
                          {fmtDateTime(ev?.at ?? l.created)}
                          {ev && <> · {isReversal ? 'reversed' : 'added'} by {actorLabel(ev.actor, ev.recorded)}</>}
                        </div>
                        {isReversal && ev?.reason && (
                          <div className="text-[11px] text-muted">Reason: {ev.reason}</div>
                        )}
                      </div>
                      <div className="flex shrink-0 items-center gap-2">
                        <span className="text-sm tabular-nums">{formatMoney(l.amount)}</span>
                        {canReverse && !isReversal && !reversed && (
                          <Button size="sm" variant="outline-danger" onClick={() => setReversing(l)}>Reverse</Button>
                        )}
                      </div>
                    </div>
                  )
                })}
                <div className="flex justify-between pt-1.5 text-xs text-muted">
                  <span>Subtotal — {g.label}</span>
                  <span className="tabular-nums">{formatMoney(g.subtotal)}</span>
                </div>
              </div>
            ))}

            {/* ---- VAT breakdown (12%, already included in the total) ---- */}
            {Number(invoice.total) > 0 && (() => {
              const { vatable, vat } = vatBreakdown(invoice.total)
              return (
                <div className="mt-4 flex flex-col gap-1 border-t border-line pt-3 text-xs text-muted">
                  <div className="flex justify-between"><span>VATable Sales</span><span className="tabular-nums">{formatMoney(vatable)}</span></div>
                  <div className="flex justify-between"><span>VAT (12%)</span><span className="tabular-nums">{formatMoney(vat)}</span></div>
                </div>
              )
            })()}

            {/* ---- Grand total ---- */}
            <div className="mt-4 flex items-center justify-between rounded-xl bg-ink px-4 py-3 text-on-ink">
              <span className="text-sm font-medium opacity-80">Total due (VAT-inclusive)</span>
              <span className="text-xl font-bold tabular-nums">{formatMoney(invoice.total)}</span>
            </div>
            {settled && (
              <p className="mt-2 mb-0 text-center text-xs text-muted">
                Paid in full · settled {fmtDateTime(invoice.settled_at)}
                {invoice.settled_by && <> by {actorLabel(invoice.settled_by.name, invoice.settled_by.recorded)}</>}
              </p>
            )}

            {/* ---- Refunds: money returned, each its own record (step 7c) ---- */}
            {(invoice.refunds ?? []).length > 0 && (
              <div className="mt-6">
                <div className="flex items-baseline justify-between border-b border-line pb-1">
                  <span className="text-[11px] font-semibold uppercase tracking-wide text-muted">Refunds</span>
                  <span className="text-xs text-muted">Cash out on the day recorded; the invoice is unchanged</span>
                </div>
                {invoice.refunds.map((r) => (
                  <div key={r.id} className="flex items-center justify-between gap-3 border-b border-dashed border-line py-2">
                    <div className="min-w-0">
                      <div className="text-sm">
                        {EVENT_LABELS[r.event] ?? r.event} · {refundMethodLabel(r.method)}
                      </div>
                      <div className="text-[11px] text-muted">
                        {fmtDateTime(r.at)} · by {actorLabel(r.actor, r.recorded)}
                        {r.reason && <> · {r.reason}</>}
                      </div>
                    </div>
                    <span className="shrink-0 text-sm tabular-nums text-red-700 dark:text-red-300">
                      {formatMoney(r.amount)}
                    </span>
                  </div>
                ))}
                <div className="flex justify-between pt-1.5 text-xs text-muted">
                  <span>Still refundable</span>
                  <span className="tabular-nums">{formatMoney(invoice.refundable ?? 0)}</span>
                </div>
              </div>
            )}

            {/* ---- History: the invoice's ledger, oldest first ---- */}
            {(invoice.history ?? []).length > 0 && (
              <details className="mt-6">
                <summary className="cursor-pointer text-[11px] font-semibold uppercase tracking-wide text-muted">
                  History ({invoice.history.length})
                </summary>
                <ol className="mt-2 mb-0 list-none space-y-1.5 p-0">
                  {invoice.history.map((e) => (
                    <li key={e.id} className="flex justify-between gap-3 text-xs">
                      <div className="min-w-0">
                        <span className="font-semibold">{EVENT_LABELS[e.event] ?? e.event}</span>
                        <span className="text-muted"> · {actorLabel(e.actor, e.recorded)} · {fmtDateTime(e.at)}</span>
                        {e.reason && <div className="text-muted">Reason: {e.reason}</div>}
                        {(e.invoice_number || e.or_number) && (
                          <div className="text-muted">
                            {[e.invoice_number && `SI ${e.invoice_number}`, e.or_number && `OR ${e.or_number}`].filter(Boolean).join(' · ')}
                          </div>
                        )}
                      </div>
                      {e.amount !== null && <span className="shrink-0 tabular-nums">{formatMoney(e.amount)}</span>}
                    </li>
                  ))}
                </ol>
              </details>
            )}
          </div>
        )}
      </Modal.Body>
      <Modal.Footer className="border-0 px-6 pt-0 pb-6">
        {canRefund && (
          <Button variant="outline-danger" className="mr-auto"
            onClick={() => setRefund({ amount: '', method: '', key: newRefundKey() })}>
            Record refund
          </Button>
        )}
        <Button variant="secondary" onClick={onClose}>Close</Button>
      </Modal.Footer>
    </Modal>
      <ReasonModal show={refund !== null}
        title="Record refund"
        description={invoice && `Money returned to ${invoice.guest?.full_name ?? 'the guest'} against invoice #${String(invoice.id).padStart(4, '0')}. `
          + 'It counts as cash out today. The settled invoice and its receipt numbers stay as they are.'}
        confirmLabel="Record refund"
        ready={refundReady}
        onHide={() => setRefund(null)}
        onConfirm={recordRefund}>
        {refund !== null && (
          <>
            <Form.Group className="mb-3">
              <Form.Label>Amount returned</Form.Label>
              <Form.Control id="invoice-refund-amount" type="number" min="0.01" step="0.01"
                max={invoice?.refundable ?? undefined} value={refund.amount}
                onChange={(e) => setRefund({ ...refund, amount: e.target.value })} />
              <Form.Text>Up to {formatMoney(invoice?.refundable ?? 0)}.</Form.Text>
            </Form.Group>
            <RefundMethodSelect id="invoice-refund-method" value={refund.method}
              onChange={(method) => setRefund({ ...refund, method })} />
          </>
        )}
      </ReasonModal>
      <ReasonModal show={reversing !== null}
        title="Reverse invoice line"
        description={reversing && `Adds a reversal of “${reversing.description}” (${formatMoney(reversing.amount)}). `
          + 'The original line stays on the invoice; the total goes down by its amount.'}
        confirmLabel="Reverse line"
        onHide={() => setReversing(null)}
        onConfirm={reverse} />
    </>
  )
}

// Settling shows the itemized charges first, and lets staff mark
// which physical document was issued — the next number from the registered
// booklet series (Inventory → Receipt Booklets) is stamped onto the invoice.
function SettleModal({ id, onClose, onSettled }) {
  const [invoice, setInvoice] = useState(null)
  const [error, setError] = useState(null)
  const [useInvoiceDoc, setUseInvoiceDoc] = useState(false)
  const [useOr, setUseOr] = useState(false)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    getInvoice(id).then(setInvoice).catch((ex) => setError(describeError(ex, 'Could not load the invoice.')))
  }, [id])

  async function settle() {
    setBusy(true)
    setError(null)
    try {
      await settleInvoice(id, { use_invoice: useInvoiceDoc, use_or: useOr })
      onSettled()
    } catch (ex) {
      setError(describeError(ex, 'Could not settle the invoice.'))
      setBusy(false)
    }
  }

  return (
    <Modal show onHide={onClose} centered>
      <Modal.Header closeButton>
        <Modal.Title>Settle invoice #{String(id).padStart(4, '0')}</Modal.Title>
      </Modal.Header>
      <Modal.Body>
        {error && <Alert variant="danger">{error}</Alert>}
        {!invoice && !error && (
          <div className="space-y-2">
            <Skeleton className="h-5 w-3/4" />
            <Skeleton className="h-5 w-full" />
            <Skeleton className="h-5 w-2/3" />
          </div>
        )}
        {invoice && (
          <>
            <div className="mb-2 text-sm text-muted">
              Billed to <span className="font-semibold text-body">{invoice.guest?.full_name ?? 'Walk-in guest'}</span>
            </div>
            <div className="mb-4 overflow-hidden rounded-lg border border-line">
              {(invoice.invoice_lines ?? []).map((l) => (
                <div key={l.id} className="flex justify-between gap-3 border-b border-line px-3 py-2 text-sm">
                  <span className="min-w-0">{l.description}</span>
                  <span className="shrink-0 tabular-nums">{formatMoney(l.amount)}</span>
                </div>
              ))}
              {(invoice.invoice_lines ?? []).length === 0 && (
                <div className="border-b border-line px-3 py-2 text-sm text-muted">No charges on this invoice.</div>
              )}
              {Number(invoice.total) > 0 && (() => {
                const { vatable, vat } = vatBreakdown(invoice.total)
                return (
                  <div className="flex flex-col gap-0.5 border-b border-line px-3 py-2 text-xs text-muted">
                    <div className="flex justify-between"><span>VATable Sales</span><span className="tabular-nums">{formatMoney(vatable)}</span></div>
                    <div className="flex justify-between"><span>VAT (12%)</span><span className="tabular-nums">{formatMoney(vat)}</span></div>
                  </div>
                )
              })()}
              <div className="flex justify-between bg-subtle px-3 py-2 font-bold">
                <span>Total (VAT-inclusive)</span><span className="tabular-nums">{formatMoney(invoice.total)}</span>
              </div>
            </div>
            <div className="space-y-2">
              <Form.Check label="Issued a physical Sales Invoice (stamps the next invoice number)"
                checked={useInvoiceDoc} onChange={(e) => setUseInvoiceDoc(e.target.checked)} />
              <Form.Check label="Issued an Official Receipt (stamps the next OR number)"
                checked={useOr} onChange={(e) => setUseOr(e.target.checked)} />
            </div>
            <p className="mt-2 mb-0 text-xs text-muted">
              Numbers come from the active booklet series registered in Inventory → Receipt Booklets.
            </p>
          </>
        )}
      </Modal.Body>
      <Modal.Footer>
        <Button variant="secondary" onClick={onClose}>Cancel</Button>
        <Button variant="success" disabled={busy || !invoice} onClick={settle}>
          {busy ? <Spinner size="sm" /> : 'Settle'}
        </Button>
      </Modal.Footer>
    </Modal>
  )
}
