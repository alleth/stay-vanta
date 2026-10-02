import { useCallback, useEffect, useMemo, useState } from 'react'
import { Card, Table, Button, Badge, Modal, Form, Alert, Spinner } from '../ui'
import { formatMoney } from '../../utils/format'
import { listInvoices, getInvoice, settleInvoice } from '../../api/food'
import { SkeletonTableRows, Skeleton } from '../Skeleton'

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
        <InvoiceModal id={modal.id} onClose={() => setModal(null)} />
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

function InvoiceModal({ id, onClose }) {
  const [invoice, setInvoice] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    getInvoice(id).then(setInvoice).catch(() => setError('Could not load the invoice.'))
  }, [id])

  // Group lines by source_type so the invoice reads like a folio.
  const groups = useMemo(() => {
    const lines = invoice?.invoice_lines ?? []
    const known = ['reservation', 'early_check_in', 'food_order']
    const isDownpayment = (l) => l.source_type.startsWith('downpayment')
    return Object.entries(LINE_GROUPS)
      .map(([key, label]) => {
        const rows = key === 'other'
          ? lines.filter((l) => !known.includes(l.source_type) && !isDownpayment(l))
          : key === 'downpayment'
            ? lines.filter(isDownpayment)
            : lines.filter((l) => l.source_type === key)
        return { key, label, rows, subtotal: rows.reduce((s, l) => s + Number(l.amount), 0) }
      })
      .filter((g) => g.rows.length > 0)
  }, [invoice])

  const settled = invoice?.status === 'settled'

  return (
    <Modal show onHide={onClose} centered size="lg">
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
                {g.rows.map((l) => (
                  <div key={l.id} className="flex items-center justify-between gap-3 border-b border-dashed border-line py-2">
                    <div className="min-w-0">
                      <div className="text-sm">{l.description}</div>
                      <div className="text-[11px] text-muted">{fmtDateTime(l.created)}</div>
                    </div>
                    <div className="shrink-0 text-sm tabular-nums">{formatMoney(l.amount)}</div>
                  </div>
                ))}
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
              </p>
            )}
          </div>
        )}
      </Modal.Body>
      <Modal.Footer className="border-0 px-6 pt-0 pb-6">
        <Button variant="secondary" onClick={onClose}>Close</Button>
      </Modal.Footer>
    </Modal>
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
    getInvoice(id).then(setInvoice).catch(() => setError('Could not load the invoice.'))
  }, [id])

  async function settle() {
    setBusy(true)
    setError(null)
    try {
      await settleInvoice(id, { use_invoice: useInvoiceDoc, use_or: useOr })
      onSettled()
    } catch (ex) {
      setError(ex?.response?.data?.message ?? 'Could not settle the invoice.')
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
