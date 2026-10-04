import { useEffect, useState } from 'react'
import { Alert, Badge } from './ui'
import { Skeleton } from './Skeleton'
import { reservationHistory } from '../api/frontdesk'
import { describeError } from '../utils/apiError'
import { formatMoney } from '../utils/format'
import { refundMethodLabel } from '../utils/refunds'

// A reservation's timeline (build step 8): its own events and the invoice
// events of its money, oldest first. This is the record of who did what to a
// reservation; `receptionist_id` ("last updated by") is legacy and isn't shown.

const EVENT_LABELS = {
  // Reservation
  booked: 'Booked',
  walked_in: 'Walk-in checked in',
  backdated: 'Past stay entered',
  edited: 'Edited',
  corrected: 'Corrected',
  discount_changed: 'Discount changed',
  checked_in: 'Checked in',
  checked_out: 'Checked out',
  cancelled: 'Cancelled',
  cancelled_after_payment: 'Cancelled after payment',
  deleted: 'Deleted',
  // Invoice
  opened: 'Invoice opened',
  line_added: 'Charge posted',
  line_reversed: 'Charge reversed',
  line_reversed_on_cancel: 'Charge reversed on cancellation',
  settled: 'Invoice settled',
  settled_on_creation: 'Downpayment collected',
  refund_recorded: 'Refund recorded',
  refunded: 'Refunded',
  refunded_on_cancel: 'Downpayment refunded',
}

const FIELD_LABELS = {
  room_id: 'Room',
  guest_id: 'Guest',
  check_in: 'Check-in',
  check_out: 'Check-out',
  status: 'Status',
  source: 'Source',
  booking_reference: 'Booking reference',
  sold_rate: 'Sold rate',
  channel_discount_type: 'Channel discount',
  channel_discount_value: 'Channel discount value',
  total_guests: 'Guests',
  discount_amount: 'Referral discount',
  promo_rate: 'Promo rate',
  additional_beds: 'Additional beds',
  checked_in_at: 'Checked in at',
  checked_out_at: 'Checked out at',
  beneficiaries: 'Senior/PWD',
  extras: 'Extra charges',
}

// What a recorded flag on a transition means, in words.
const FLAG_TEXT = {
  early_check_in: 'early check-in fee posted',
  room_charge_posted: 'room charge posted',
  charges_reversed: 'charges reversed',
  downpayment_refunded: 'downpayment refunded',
  backdated_entry: 'entered after the stay',
}

const fmtWhen = (s) => (s ? new Date(s).toLocaleString() : '—')
const who = (item) => (item.recorded === false ? 'Not recorded' : item.actor ?? 'Unknown user')

function describeValue(field, value, roomName) {
  if (value === null || value === undefined || value === '') return '—'
  if (field === 'room_id') return roomName(value)
  if (field === 'beneficiaries') {
    return value.length
      ? value.map((b) => `${b.beneficiary_name} (${String(b.discount_type).toUpperCase()} ${b.id_number})`).join(', ')
      : 'none'
  }
  if (field === 'extras') {
    return value.length ? value.map((x) => `${x.name} ×${x.quantity}`).join(', ') : 'none'
  }
  if (field === 'checked_in_at' || field === 'checked_out_at') return fmtWhen(`${value}Z`.replace(' ', 'T'))
  return String(value)
}

// Before → after lines for a reservation event, and the flags a transition set.
function changeLines(item, roomName) {
  const changes = item.changes ?? {}
  if (changes.after || changes.before) return [] // creation / deletion: the whole reservation, not a diff
  const lines = []
  for (const [field, change] of Object.entries(changes)) {
    if (FLAG_TEXT[field]) {
      if (change === true) lines.push(FLAG_TEXT[field])
      continue
    }
    if (change && typeof change === 'object' && 'before' in change) {
      lines.push(`${FIELD_LABELS[field] ?? field}: ${describeValue(field, change.before, roomName)} → `
        + `${describeValue(field, change.after, roomName)}`)
    }
  }
  return lines
}

export default function ReservationHistory({ reservationId, rooms = [] }) {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    reservationHistory(reservationId)
      .then(setData)
      .catch((ex) => setError(describeError(ex, 'Could not load the history.')))
  }, [reservationId])

  const roomName = (id) => rooms.find((r) => Number(r.id) === Number(id))?.room_number ?? `#${id}`
  const partial = (data?.history ?? []).some((h) => h.recorded === false)

  return (
    <section className="mt-6 border-t border-line pt-4">
      <h3 className="mb-3 text-sm font-semibold uppercase tracking-[0.04em] text-muted">History</h3>
      {error && <Alert variant="danger">{error}</Alert>}
      {!data && !error && (
        <div className="space-y-2">
          <Skeleton className="h-4 w-1/2" />
          <Skeleton className="h-4 w-2/3" />
          <Skeleton className="h-4 w-1/3" />
        </div>
      )}
      {data && data.history.length === 0 && <p className="mb-0 text-sm text-muted">Nothing recorded yet.</p>}
      {data && data.history.length > 0 && (
        <ol className="m-0 list-none space-y-2 p-0">
          {data.history.map((item) => {
            const isInvoice = item.source === 'invoice'
            const lines = isInvoice ? [] : changeLines(item, roomName)
            return (
              <li key={item.id}
                className={`flex justify-between gap-3 border-l-2 pl-3 text-sm ${isInvoice ? 'border-line' : 'border-accent'}`}>
                <div className="min-w-0">
                  <div>
                    <span className="font-semibold">{EVENT_LABELS[item.event] ?? item.event}</span>
                    {isInvoice && (
                      <span className="text-muted"> · Invoice #{String(item.invoice_id).padStart(4, '0')}</span>
                    )}
                  </div>
                  <div className="text-xs text-muted">{who(item)} · {fmtWhen(item.at)}</div>
                  {item.reason && <div className="text-xs">Reason: {item.reason}</div>}
                  {isInvoice && item.line && <div className="text-xs text-muted">{item.line}</div>}
                  {isInvoice && item.method && (
                    <div className="text-xs text-muted">Returned by {refundMethodLabel(item.method)}</div>
                  )}
                  {(item.invoice_number || item.or_number) && (
                    <div className="text-xs text-muted">
                      {[item.invoice_number && `SI ${item.invoice_number}`, item.or_number && `OR ${item.or_number}`]
                        .filter(Boolean).join(' · ')}
                    </div>
                  )}
                  {lines.map((l) => <div key={l} className="text-xs text-muted">{l}</div>)}
                </div>
                {item.amount !== null && item.amount !== undefined && (
                  <span className="shrink-0 tabular-nums">{formatMoney(item.amount)}</span>
                )}
              </li>
            )
          })}
        </ol>
      )}
      {partial && (
        <p className="mt-3 mb-0 text-xs text-muted">
          <Badge bg="secondary" className="mr-1">Not recorded</Badge>
          History from before reservation records began is partial: who did those steps wasn&apos;t recorded.
        </p>
      )}
    </section>
  )
}
