import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Tab, Tabs, Card, Table, Button, Badge, Modal, Form, Alert, Spinner, ListGroup, InputGroup, Pagination,
} from '../components/ui'
import { useProperty } from '../context/PropertyContext'
import { useAuth } from '../context/AuthContext'
import { P } from '../auth/permissions'
import { formatMoney } from '../utils/format'
import { matchGuests, listGuests } from '../api/guests'
import { SkeletonTable, SkeletonCards } from '../components/Skeleton'
import { SummaryGroup, SummaryRow } from '../components/StatCard'
import { InvoicesPanel } from '../components/finance/InvoicesPanel'
import { BILLING_STATE, roomStatusLabel, roomServiceLabel } from '../utils/roles'
import RoomServiceHistory from '../components/RoomServiceHistory'
import {
  listRooms, setRoomService, listRoomRates, listBookingSources, listPromoRates,
  listReservations, pageReservations, reservationStats,
  createReservation, updateReservation, deleteReservation, transitionReservation,
  postRoomCharge, reverseRoomCharge,
  listExtraCharges,
} from '../api/frontdesk'
import { describeError } from '../utils/apiError'
import ReasonModal from '../components/ReasonModal'
import RefundMethodSelect from '../components/RefundMethodSelect'
import ReservationHistory from '../components/ReservationHistory'
import ReservationPrice from '../components/ReservationPrice'
import { WALK_IN, sourceLabel } from '../utils/bookingLabels'

// Standard check-in is from noon; arriving earlier in the day is an early check-in.
const isEarlyCheckInNow = () => new Date().getHours() < 12

// Today on the device's own clock (the hotel's), not UTC.
const todayStr = () => new Date().toLocaleDateString('en-CA')
// Monday of the current week as YYYY-MM-DD.
function startOfWeek() {
  const d = new Date()
  d.setDate(d.getDate() - ((d.getDay() + 6) % 7))
  return d.toLocaleDateString('en-CA')
}

const RESERVATIONS_PER_PAGE = 25

// 'walk_in' is fixed — always available, never admin-managed, never eligible
// for a promo rate. Every other source comes from the property's own
// `booking_sources` (created by promo-rate saves in Settings), replacing what used to be a
// hardcoded OTA list.
const ONLINE = 'online'

// The two ways a booking reaches the desk. An online one carries the
// channel's own paperwork — its booking ID and the rate it sold at — which a
// walk-in has no equivalent of.
const BOOKING_TYPES = [
  { id: WALK_IN, label: 'Walk-in', hint: 'Guest is here now' },
  { id: ONLINE, label: 'Online booking', hint: 'Agoda, Cocotel, …' },
]

// The promo multiplier that applies to a source + room: the admin's
// room-specific row wins over a property-wide one; null when none is
// configured. The promo nightly price is the room's base rate × this.
function resolvePromoMultiplier(promoRates, source, roomId) {
  const forSource = promoRates.filter((p) => p.source === source)
  const specific = roomId ? forSource.find((p) => p.room_id === Number(roomId)) : null
  const found = specific ?? forSource.find((p) => p.room_id === null)
  return found ? Number(found.multiplier) : null
}

// The room's original nightly rate, mirroring the backend's resolveBaseRate:
// a room-specific rate wins, else the cheapest property-wide one, else 0.
function resolveBaseRate(rates, roomId) {
  const cheapest = (list) =>
    list.length ? Math.min(...list.map((rt) => Number(rt.base_rate))) : null
  const specific = cheapest(rates.filter((rt) => rt.room_id === Number(roomId)))
  return specific ?? cheapest(rates.filter((rt) => rt.room_id === null)) ?? 0
}

const ROOM_VARIANT = { available: 'success', occupied: 'danger', maintenance: 'warning', out_of_service: 'secondary' }
const RES_VARIANT = { booked: 'secondary', checked_in: 'primary', checked_out: 'success', cancelled: 'dark' }

// The statutory discounts a guest can qualify for. A booking carries one
// beneficiary row per qualified guest (a room can hold several), so these are
// counted per row rather than read off a single flag.
const DISCOUNT_KINDS = [
  { type: 'senior', label: 'senior' },
  { type: 'pwd', label: 'PWD' },
]

const fmtDateTime = (s) => (s ? new Date(s).toLocaleString() : null)

// The booking form's running estimate, itemized the way the invoice will be:
// the room subtotal, then each discount that was applied as its own line
// (negative), then the total and any advance-booking downpayment. Shared by
// the side rail (wider screens) and the Pricing section (phones) so the two
// can't drift apart.
// Why a reservation can't be edited by this user, or null when it can. The
// row always opens; this decides whether the modal opens editable or as a
// read-only view. Mirrors ReservationsController::edit()/delete().
function editBlockReason(r, canCorrect) {
  if (r.deleted_at) return 'This reservation was deleted. Its history is kept below.'
  if (r.room_charge_invoice === 'settled') {
    return 'Its invoice is settled, so it can no longer be edited or deleted.'
  }
  if (r.status === 'cancelled') return 'A cancelled reservation can’t be edited.'
  if (r.status !== 'booked' && !canCorrect) return 'Only a Manager can change a stay that’s checked in or out.'
  return null
}

// The Rooms card: one occupancy figure and a bar split by status, instead of
// three separate tiles for parts of the same whole. Solid saturated fills
// read correctly in both themes, so the segments need no dark: pairs.
function RoomsOccupancy({ counts }) {
  const total = counts.available + counts.occupied + counts.maintenance
  const pct = (n) => (total > 0 ? `${(n / total) * 100}%` : '0%')
  return (
    <>
      <div className="flex items-baseline gap-2">
        <span className="sv-serif text-2xl font-bold tabular-nums">{counts.occupied}</span>
        <span className="text-sm text-muted">of {total} room{total === 1 ? '' : 's'} occupied</span>
      </div>
      <div className="mt-2 flex h-2 overflow-hidden rounded-full bg-subtle"
        role="img"
        aria-label={`${counts.occupied} occupied, ${counts.available} vacant, ${counts.maintenance} in maintenance`}>
        <div className="bg-red-500" style={{ width: pct(counts.occupied) }} />
        <div className="bg-emerald-500" style={{ width: pct(counts.available) }} />
        <div className="bg-amber-500" style={{ width: pct(counts.maintenance) }} />
      </div>
      <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted">
        <span className="inline-flex items-center gap-1">
          <span className="h-2 w-2 rounded-full bg-red-500" />{counts.occupied} occupied
        </span>
        <span className="inline-flex items-center gap-1">
          <span className="h-2 w-2 rounded-full bg-emerald-500" />{counts.available} vacant
        </span>
        <span className="inline-flex items-center gap-1">
          <span className="h-2 w-2 rounded-full bg-amber-500" />{counts.maintenance} maintenance
        </span>
      </div>
    </>
  )
}

function EstimateBreakdown({ subtotalLabel, subtotal, discounts, extras = [], total, downpayment }) {
  if (subtotal <= 0) {
    return <div className="sv-serif mt-1 text-xl font-bold">—</div>
  }
  return (
    <div className="mt-2 text-xs">
      <div className="flex items-baseline justify-between gap-2">
        <span className="text-muted">{subtotalLabel}</span>
        <span className="tabular-nums">{formatMoney(subtotal)}</span>
      </div>
      {discounts.map((d) => (
        <div key={d.label} className="mt-1 flex items-baseline justify-between gap-2">
          <span className="text-muted">{d.label}</span>
          <span className="whitespace-nowrap tabular-nums text-emerald-700 dark:text-emerald-400">
            −{formatMoney(d.amount)}
          </span>
        </div>
      ))}
      {/* Added after the discounts, which don't cover them. */}
      {extras.map((x) => (
        <div key={x.label} className="mt-1 flex items-baseline justify-between gap-2">
          <span className="text-muted">{x.label}</span>
          <span className="whitespace-nowrap tabular-nums">+{formatMoney(x.amount)}</span>
        </div>
      ))}
      <div className="mt-2 flex items-baseline justify-between gap-2 border-t border-line pt-2">
        <span className="font-medium uppercase tracking-[0.04em] text-muted">Total</span>
        <span className="sv-serif text-xl font-bold tabular-nums">{formatMoney(total)}</span>
      </div>
      {downpayment > 0 && (
        <div className="mt-1 flex items-baseline justify-between gap-2">
          <span className="text-muted">Downpayment (50%)</span>
          <span className="tabular-nums">{formatMoney(downpayment)}</span>
        </div>
      )}
    </div>
  )
}

// Allowed manual status changes per current room status. A room becomes
// `occupied` only by booking + checking in a guest (so picking "occupied" opens
// the reservation flow), and returns to `available` automatically on check-out.
// Service changes a room card offers (G4): what each one does, and why it asks.
const SERVICE_ACTIONS = {
  maintenance: {
    title: 'Start maintenance',
    description: 'The room comes off sale for now: no guest can check in until maintenance is done. Bookings for later dates stay. Say what needs fixing.',
  },
  out_of_service: {
    title: 'Take out of service',
    description: 'The room can’t be booked or checked into until it’s returned to service. Say why.',
  },
  in_service: {
    title: 'Back in service',
    description: 'The room can be sold and checked into again.',
  },
}

/**
 * A room card's actions (G4): book it, start or end maintenance (Front Desk
 * and Managers), take it out of service or return it (Managers), and its
 * service history (Managers). Occupancy is never set by hand (R4).
 */
function RoomActions({ room, can, onBook, onService, onHistory }) {
  const service = room.service_status ?? 'in_service'
  const canChange = can(P.ROOMS_ROOM_UPDATE_STATUS)
  const canRemove = can(P.ROOMS_ROOM_REMOVE_FROM_SERVICE)
  return (
    <div className="flex flex-wrap gap-1">
      {service === 'in_service' && room.status !== 'occupied' && can(P.FRONT_DESK_RESERVATION_MANAGE) && (
        <Button size="sm" variant="outline-primary" onClick={onBook}>New booking</Button>
      )}
      {service === 'in_service' && canChange && (
        <Button size="sm" variant="outline-secondary" onClick={() => onService('maintenance')}>Maintenance</Button>
      )}
      {service === 'maintenance' && canChange && (
        <Button size="sm" variant="outline-success" onClick={() => onService('in_service')}>Maintenance done</Button>
      )}
      {service !== 'out_of_service' && canRemove && (
        <Button size="sm" variant="outline-danger" onClick={() => onService('out_of_service')}>Out of service</Button>
      )}
      {service === 'out_of_service' && canRemove && (
        <Button size="sm" variant="outline-success" onClick={() => onService('in_service')}>Return to service</Button>
      )}
      {can(P.ROOMS_ROOM_VIEW_HISTORY) && (
        <Button size="sm" variant="link" onClick={onHistory}>History</Button>
      )}
    </div>
  )
}

export default function FrontDesk() {
  const { propertyId } = useProperty()
  const { can } = useAuth()
  // Rooms, rates, promo rates and extra charges are set up in Settings (step 9).
  const canSetUpRooms = can(P.SETTINGS_ROOM_MANAGE)
  // Anyone can fix a booking before check-in; a Manager can also correct (or
  // delete) a stay that's under way or over — the backend decides the rest.
  const canCorrect = can(P.FRONT_DESK_RESERVATION_CORRECT)
  const canDelete = can(P.FRONT_DESK_RESERVATION_DELETE)
  // Reverse room charge (elevated: a Manager, with a reason).
  const canReverse = can(P.FINANCE_INVOICE_REVERSE)
  // Every row opens its reservation; whether it opens editable is
  // editBlockReason()'s call (the backend enforces the same rules).
  const [rooms, setRooms] = useState([])
  const [rates, setRates] = useState([])
  const [bookingSources, setBookingSources] = useState([])
  const [promoRates, setPromoRates] = useState([])
  // Three separate views of reservations, each fetched for its own purpose —
  // the table's current page, the calendar date's stays, and the card counts —
  // so none of them is computed from a truncated list.
  const [reservations, setReservations] = useState([]) // the table's page
  const [resTotal, setResTotal] = useState(0)
  const [resPage, setResPage] = useState(1)
  const [calReservations, setCalReservations] = useState([])
  const [resStats, setResStats] = useState({ booked: 0, checked_out_today: 0, cancelled_today: 0, not_billed: 0, open_invoices: 0 })
  const [extraCharges, setExtraCharges] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [modal, setModal] = useState(null) // { type: 'reservation', reservation? }
  const [reservationRoomId, setReservationRoomId] = useState(null)
  const [reservationDate, setReservationDate] = useState(null)
  const [calDate, setCalDate] = useState(todayStr)
  const [resFilter, setResFilter] = useState('today') // today | week | all
  // Set by clicking the "To collect → unpaid" figure: the table then lists
  // exactly what that number counts.
  const [resBilling, setResBilling] = useState(null) // null | 'not_billed'
  // Controlled so the summary cards can jump to the list behind a number.
  const [tab, setTab] = useState('reservations')
  const [pending, setPending] = useState(null) // key of the in-flight inline action
  const [earlyConfirm, setEarlyConfirm] = useState(null) // reservation pending an early check-in
  const [reverseFor, setReverseFor] = useState(null) // reservation whose room charge is being reversed
  const [cancelRefund, setCancelRefund] = useState(null) // {reservation, method, reason}: an advance booking being cancelled
  const [cancelWithReason, setCancelWithReason] = useState(null) // a stay with charges being cancelled
  const today = todayStr()

  // Front Desk shows a "fresh start" each day: pending bookings (booked /
  // checked_in) always show, but completed transactions (checked_out /
  // cancelled) only show if they happened within the selected window — the
  // server applies it, since the table is paginated there.
  const since = resFilter === 'all' || resFilter === 'deleted' ? undefined
    : resFilter === 'week' ? startOfWeek() : today
  // A Manager's read-only view of deleted reservations (build step 8).
  const deletedOnly = resFilter === 'deleted' ? 'only' : undefined

  const refresh = useCallback(async () => {
    if (!propertyId) return
    try {
      const [rm, rt, bs, pr, pageData, cal, st, ec] = await Promise.all([
        listRooms(propertyId), listRoomRates(propertyId), listBookingSources(propertyId),
        listPromoRates(propertyId),
        pageReservations(propertyId, {
          page: resPage, limit: RESERVATIONS_PER_PAGE, since, billing: resBilling ?? undefined, deleted: deletedOnly,
        }),
        listReservations(propertyId, { on_date: calDate }),
        reservationStats(propertyId), listExtraCharges(propertyId),
      ])
      setRooms(rm)
      setRates(rt)
      setBookingSources(bs)
      setPromoRates(pr)
      // A delete can empty the last page; step back rather than show nothing.
      if (pageData.reservations.length === 0 && resPage > 1) setResPage((p) => p - 1)
      setReservations(pageData.reservations)
      setResTotal(pageData.total ?? 0)
      setCalReservations(cal)
      setResStats(st)
      setExtraCharges(ec)
      setError(null)
    } catch {
      setError('Could not load front desk data.')
    } finally {
      setLoading(false)
    }
  }, [propertyId, resPage, since, resBilling, calDate, deletedOnly])

  // The active early check-in fee (0 if none) — shown in the warning and billed
  // automatically by the backend when an early check-in is confirmed.
  const earlyFee = useMemo(() => {
    const c = extraCharges.find((x) => x.code === 'early_check_in' && x.is_active)
    return c ? Number(c.amount) : 0
  }, [extraCharges])

  useEffect(() => {
    // State updates occur after the awaited fetch; safe data effect.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    refresh()
  }, [refresh])

  const availableRooms = useMemo(() => rooms.filter((r) => r.status === 'available'), [rooms])

  // At-a-glance counts. A `booked` reservation is a pending stay; once checked in
  // the room is "occupied" (counted there, not as a reservation). The checked-out
  // and cancelled cards monitor what happened *today*.
  const counts = useMemo(() => ({
    available: rooms.filter((r) => r.status === 'available').length,
    occupied: rooms.filter((r) => r.status === 'occupied').length,
    maintenance: rooms.filter((r) => r.status === 'maintenance').length,
    reservations: resStats.booked,
    checkedOutToday: resStats.checked_out_today,
    cancelledToday: resStats.cancelled_today,
    notBilled: resStats.not_billed ?? 0,
    openInvoices: resStats.open_invoices ?? 0,
  }), [rooms, resStats])

  const resPages = Math.max(1, Math.ceil(resTotal / RESERVATIONS_PER_PAGE))

  // Date filter: which rooms are free, and which reservations fall on calDate
  // (the server returns only stays touching it). A reservation occupies a room
  // for the nights [check_in, check_out), so the check-out day is free again.
  const occupiedOnDate = useMemo(() => {
    const occ = new Set()
    for (const r of calReservations) {
      if (r.status === 'cancelled' || !r.check_in || !r.check_out) continue
      if (r.check_in <= calDate && calDate < r.check_out) occ.add(r.room_id)
    }
    return occ
  }, [calReservations, calDate])

  const availableOnDate = useMemo(
    () => rooms.filter((r) => r.status !== 'maintenance' && !occupiedOnDate.has(r.id)),
    [rooms, occupiedOnDate],
  )

  // Reservations touching the date (inclusive of arrival & departure days).
  const reservationsOnDate = useMemo(
    () => calReservations.filter((r) =>
      r.status !== 'cancelled' && r.check_in && r.check_out
      && r.check_in <= calDate && calDate <= r.check_out),
    [calReservations, calDate],
  )

  async function runTransition(id, transition, data = {}) {
    setPending(`${transition}-${id}`)
    setError(null)
    try {
      await transitionReservation(id, transition, data)
      await refresh()
    } catch (ex) {
      setError(describeError(ex, 'Action failed.'))
    } finally {
      setPending(null)
    }
  }

  // Button entry point. Check-in before noon routes through the early check-in
  // warning; check-out confirms first (it posts the room charge).
  function onTransition(r, transition) {
    if (transition === 'check-in') {
      if (isEarlyCheckInNow()) { setEarlyConfirm(r); return }
      runTransition(r.id, 'check-in')
      return
    }
    if (transition === 'check-out'
      && !window.confirm('Check out this guest? This finalizes the stay and posts the room charge to their invoice.')) {
      return
    }
    // Cancelling an advance booking retains 10% of the downpayment and returns
    // 90%: a refund (cash out today), so it asks how the money went back.
    if (transition === 'cancel' && r.status === 'booked' && Number(r.downpayment) > 0) {
      setCancelRefund({ reservation: r, method: '', reason: '' })
      return
    }
    // Once money was taken (charges posted, or the guest is in the room with
    // charges likely), cancelling records why (build step 8, R2).
    if (transition === 'cancel' && (r.status === 'checked_in' || r.billing_state === 'billed')) {
      setCancelWithReason(r)
      return
    }
    runTransition(r.id, transition)
  }

  // Post room charge: bills the stay onto the guest's open invoice. Billing
  // state then follows the invoice (Billed, then Settled once it's settled).
  // The undo is Reverse room charge: a Manager, with a reason, while the
  // invoice is open (recorded in invoice_events; lines are never deleted).
  async function onPostCharge(r) {
    setPending(`charge-${r.id}`)
    setError(null)
    try {
      await postRoomCharge(r.id)
      await refresh()
    } catch (ex) {
      setError(describeError(ex, 'Could not post the room charge.'))
    } finally {
      setPending(null)
    }
  }

  // A room's service availability (G4): { room, target } while the reason
  // dialog is open. Occupancy is never set here: it follows check-in and
  // check-out.
  const [serviceChange, setServiceChange] = useState(null)
  const [roomHistoryOf, setRoomHistoryOf] = useState(null)

  function openReservation(roomId = null, date = null) {
    setReservationRoomId(roomId)
    setReservationDate(date)
    setModal({ type: 'reservation' })
  }

  if (!propertyId)
    return <Alert variant="info">Select or create a property to use the front desk.</Alert>

  return (
    <div>
      <h1 className="mb-4 text-2xl font-bold">Front Desk</h1>
      {error && <Alert variant="danger" onClose={() => setError(null)} dismissible>{error}</Alert>}

      {loading ? (
        <>
          <SkeletonCards count={3} />
          <SkeletonTable rows={5} />
        </>
      ) : (
        <>
        {/* Three questions instead of eight tiles: how full are the rooms,
            what happened today, and what's still to collect. The last one is
            ringed when anything is owed, and its figures open the list behind
            them. */}
        <div className="mb-4 grid grid-cols-1 gap-3 md:grid-cols-3">
          <SummaryGroup label="Rooms">
            <RoomsOccupancy counts={counts} />
          </SummaryGroup>
          <SummaryGroup label="Today">
            <SummaryRow value={counts.reservations} label="upcoming bookings" variant="primary" />
            <SummaryRow value={counts.checkedOutToday} label="checked out today" variant="secondary" />
            <SummaryRow value={counts.cancelledToday} label="cancelled today" variant="secondary" />
          </SummaryGroup>
          <SummaryGroup label="Receivables" highlight={counts.notBilled + counts.openInvoices > 0}>
            <SummaryRow value={counts.notBilled} label="not billed"
              variant={counts.notBilled > 0 ? 'warning' : 'secondary'}
              title="Started stays with no room charge posted. Show them in the table."
              onClick={() => { setResBilling('not_billed'); setResFilter('all'); setResPage(1); setTab('reservations') }} />
            <SummaryRow value={counts.openInvoices} label="outstanding invoices"
              variant={counts.openInvoices > 0 ? 'warning' : 'secondary'}
              title="Open the Invoices tab"
              onClick={() => setTab('invoices')} />
          </SummaryGroup>
        </div>

        <Tabs activeKey={tab} onSelect={setTab} className="mb-4">
          {/* ---- Reservations ---- */}
          <Tab eventKey="reservations" title={`Reservations (${resTotal})`}>
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
              <div className="flex flex-wrap items-center gap-2">
                <Form.Group className="mb-0 flex items-center gap-2">
                  <Form.Label className="mb-0 text-muted">Show</Form.Label>
                  <Form.Select size="sm" value={resFilter} style={{ width: 'auto' }}
                    onChange={(e) => { setResFilter(e.target.value); setResPage(1) }}>
                    <option value="today">Today&apos;s activity</option>
                    <option value="week">This week</option>
                    <option value="all">All</option>
                    {canDelete && <option value="deleted">Deleted</option>}
                  </Form.Select>
                </Form.Group>
                {resBilling === 'not_billed' && (
                  <Button size="sm" variant="outline-secondary" title="Show every reservation again"
                    onClick={() => { setResBilling(null); setResPage(1) }}>
                    Not billed only ✕
                  </Button>
                )}
              </div>
              <Button onClick={() => openReservation()} disabled={availableRooms.length === 0}>
                New reservation
              </Button>
            </div>
            <Card>
              <Table hover>
                <thead>
                  <tr>
                    <th>Guest</th><th>Room</th><th>Dates</th><th>Source</th>
                    <th className="text-right">Total</th><th>Status</th><th>Billing</th>
                    <th>Logs</th><th>Booked by</th><th className="text-right">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {reservations.length === 0 && (
                    <tr><td colSpan={10} className="py-6 text-center text-muted">No reservations to show.</td></tr>
                  )}
                  {reservations.map((r) => (
                    <tr key={r.id}
                      className={[
                        r.status === 'cancelled' && 'text-muted',
                        'cursor-pointer',
                      ].filter(Boolean).join(' ')}
                      title={editBlockReason(r, canCorrect)
                        ? 'Click to view the details'
                        : canDelete ? 'Click to view, edit or delete' : 'Click to view or edit this booking'}
                      onClick={() => setModal({ type: 'reservation', reservation: r })}>
                      <td className="font-semibold">
                        {r.guest?.full_name ?? '—'}{' '}
                        {r.guest && (
                          <Badge bg="light" className="font-normal">{r.guest.guest_type}</Badge>
                        )}
                      </td>
                      <td>{r.room?.room_number ?? '—'}</td>
                      <td className="text-xs">{r.check_in} → {r.check_out}</td>
                      <td className="text-xs">
                        {sourceLabel(bookingSources, r.source)}
                        {/* One badge per qualified guest, since a room can
                            hold several — "senior ×2" beats a bare flag. */}
                        {DISCOUNT_KINDS.map(({ type, label }) => {
                          const n = (r.reservation_discounts ?? [])
                            .filter((d) => d.discount_type === type).length
                          return n > 0 && (
                            <Badge key={type} bg="info" className="ml-1">
                              {label}{n > 1 ? ` ×${n}` : ''}
                            </Badge>
                          )
                        })}
                        {Number(r.discount_amount) > 0 && (
                          <Badge bg="info" className="ml-1">referral</Badge>
                        )}
                      </td>
                      <td className="text-right">
                        {formatMoney(r.quote?.total)}
                        {r.promo_rate !== null && r.promo_rate !== undefined && (
                          <div className="whitespace-nowrap text-[11px] text-muted">promo rate</div>
                        )}
                        {Number(r.quote?.channel_discount) > 0 && (
                          <div className="whitespace-nowrap text-[11px] text-muted">
                            −{formatMoney(r.quote.channel_discount)} (
                            {r.channel_discount_type === 'percent'
                              ? `${Number(r.channel_discount_value)}% `
                              : ''}
                            {sourceLabel(bookingSources, r.source)})
                          </div>
                        )}
                        {Number(r.quote?.statutory_discount) > 0 && (
                          <div className="whitespace-nowrap text-[11px] text-muted">
                            −{formatMoney(r.quote.statutory_discount)} (
                            {r.reservation_discounts?.length ?? 0}/{r.total_guests ?? 1} guests)
                          </div>
                        )}
                        {Number(r.quote?.referral_discount) > 0 && (
                          <div className="whitespace-nowrap text-[11px] text-muted">
                            −{formatMoney(r.quote.referral_discount)} (referral)
                          </div>
                        )}
                        {Number(r.quote?.extras) > 0 && (
                          <div className="whitespace-nowrap text-[11px] text-muted">
                            +{formatMoney(r.quote.extras)} (extras)
                          </div>
                        )}
                        {Number(r.downpayment) > 0 && (
                          <div className="whitespace-nowrap text-xs text-muted">DP {formatMoney(r.downpayment)}</div>
                        )}
                      </td>
                      <td><Badge bg={RES_VARIANT[r.status]}>{r.status.replace('_', ' ')}</Badge></td>
                      <td>
                        {/* Billing comes from the invoice (BL1): Not billed →
                            Billed (charge on an open invoice) → Settled. */}
                        <Badge bg={BILLING_STATE[r.billing_state]?.bg ?? 'secondary'}
                          title={r.billing_state === 'billed'
                            ? 'Settle it on the Invoices tab (Front Desk or Finance) for it to count as collected.'
                            : r.billing_state === 'settled'
                              ? 'Collected. It can no longer be edited or deleted.'
                              : 'No room charge posted yet.'}>
                          {BILLING_STATE[r.billing_state]?.label ?? 'Not billed'}
                        </Badge>
                      </td>
                      <td className="min-w-[170px] text-xs text-muted">
                        <div>Booked: {fmtDateTime(r.created) ?? '—'}</div>
                        {r.checked_in_at && <div>In: {fmtDateTime(r.checked_in_at)}</div>}
                        {r.checked_out_at && <div>Out: {fmtDateTime(r.checked_out_at)}</div>}
                      </td>
                      <td className="text-xs text-muted">
                        {r.booked_by ? (r.booked_by.recorded ? r.booked_by.name ?? 'Unknown user' : 'Not recorded') : '—'}
                      </td>
                      <td className="text-right" onClick={(e) => e.stopPropagation()}>
                        {r.deleted_at ? <Badge bg="secondary">Deleted</Badge> : (
                        <div className="inline-flex flex-wrap justify-end gap-1">
                          {r.status === 'booked' && (
                            <Button size="sm" variant="outline-primary"
                              disabled={pending !== null}
                              onClick={() => onTransition(r, 'check-in')}>
                              {pending === `check-in-${r.id}` ? <Spinner size="sm" /> : 'Check in'}
                            </Button>
                          )}
                          {r.status === 'checked_in' && (
                            <Button size="sm" variant="outline-success"
                              disabled={pending !== null}
                              onClick={() => onTransition(r, 'check-out')}>
                              {pending === `check-out-${r.id}` ? <Spinner size="sm" /> : 'Check out'}
                            </Button>
                          )}
                          {r.status !== 'cancelled' && r.billing_state === 'not_billed' && (
                            <Button size="sm" variant="outline-secondary"
                              disabled={pending !== null || !r.guest_id}
                              title={r.guest_id
                                ? 'Post the room charge to the guest’s invoice'
                                : 'Add a guest first to post the room charge.'}
                              onClick={() => onPostCharge(r)}>
                              {pending === `charge-${r.id}` ? <Spinner size="sm" /> : 'Post room charge'}
                            </Button>
                          )}
                          {canReverse && r.billing_state === 'billed' && (
                            <Button size="sm" variant="outline-danger"
                              disabled={pending !== null}
                              title="Take the room charge off the guest’s open invoice (reason required)"
                              onClick={() => setReverseFor(r)}>
                              Reverse charge
                            </Button>
                          )}
                          {(r.status === 'booked' || r.status === 'checked_in') && (
                            <Button size="sm" variant="outline-danger"
                              disabled={pending !== null}
                              onClick={() => onTransition(r, 'cancel')}>
                              {pending === `cancel-${r.id}` ? <Spinner size="sm" /> : 'Cancel'}
                            </Button>
                          )}
                        </div>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            </Card>
            {resTotal > RESERVATIONS_PER_PAGE && (
              <div className="mt-3 flex items-center justify-end gap-3">
                <span className="text-sm text-muted">
                  Page {resPage} of {resPages} · {resTotal} reservation(s)
                </span>
                <Pagination>
                  <Pagination.Prev disabled={resPage <= 1}
                    onClick={() => setResPage((p) => Math.max(1, p - 1))} />
                  <Pagination.Next disabled={resPage >= resPages}
                    onClick={() => setResPage((p) => Math.min(resPages, p + 1))} />
                </Pagination>
              </div>
            )}
          </Tab>

          {/* ---- Invoices ---- the same panel as Finance, so the desk
              can settle a guest's bill at check-out without leaving; settling
              refreshes the billing badges above. */}
          <Tab eventKey="invoices" title="Invoices">
            <InvoicesPanel propertyId={propertyId} onSettled={refresh} />
          </Tab>

          {/* ---- Rooms ---- */}
          <Tab eventKey="rooms" title={`Rooms (${rooms.length})`}>
            {canSetUpRooms && (
              <p className="mb-3 text-sm text-muted">
                Rooms, rates, promo rates and extra charges are set up in Settings (from Home).
              </p>
            )}
            <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
              {rooms.length === 0 && <p className="text-muted">No rooms yet.</p>}
              {rooms.map((room) => (
                <Card key={room.id} className="h-full">
                  <Card.Body>
                    <div className="flex items-start justify-between gap-2">
                      <div className="text-2xl font-bold">{room.room_number}</div>
                      <div className="flex flex-wrap justify-end gap-1">
                        {room.status === 'occupied' && <Badge bg={ROOM_VARIANT.occupied}>{roomStatusLabel('occupied')}</Badge>}
                        {(room.service_status ?? 'in_service') !== 'in_service' ? (
                          <Badge bg={ROOM_VARIANT[room.service_status]}>{roomServiceLabel(room.service_status)}</Badge>
                        ) : room.status !== 'occupied' && (
                          <Badge bg={ROOM_VARIANT.available}>{roomStatusLabel('available')}</Badge>
                        )}
                      </div>
                    </div>
                    <div className="mb-2 text-sm text-muted">{room.room_type ?? 'Room'}</div>
                    <RoomActions room={room} can={can}
                      onBook={() => openReservation(room.id)}
                      onService={(target) => setServiceChange({ room, target })}
                      onHistory={() => setRoomHistoryOf(room)} />
                  </Card.Body>
                </Card>
              ))}
            </div>
          </Tab>

          {/* ---- Calendar / availability by date ---- */}
          <Tab eventKey="calendar" title="Calendar">
            <Card className="mb-4">
              <Card.Body className="flex flex-wrap items-center gap-4 p-4">
                <Form.Group className="mb-0 flex items-center gap-2">
                  <Form.Label className="mb-0 font-semibold">Date</Form.Label>
                  <Form.Control type="date" value={calDate} style={{ maxWidth: 190 }}
                    onChange={(e) => setCalDate(e.target.value)} />
                </Form.Group>
                <span className="text-sm text-muted">
                  {availableOnDate.length} room(s) free · {reservationsOnDate.length} reservation(s) on this date
                </span>
              </Card.Body>
            </Card>
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
              <div className="lg:col-span-5">
                <Card className="h-full">
                  <Card.Header>Available rooms</Card.Header>
                  {availableOnDate.length === 0 ? (
                    <Card.Body><p className="mb-0 text-muted">No rooms free on this date.</p></Card.Body>
                  ) : (
                    <ListGroup>
                      {availableOnDate.map((r) => (
                        <ListGroup.Item key={r.id} className="flex items-center justify-between px-4 py-3">
                          <span>
                            <span className="font-semibold">{r.room_number}</span>
                            <span className="ml-2 text-xs text-muted">{r.room_type ?? 'Room'}</span>
                          </span>
                          {calDate >= today && (
                            <Button size="sm" variant="outline-primary"
                              onClick={() => openReservation(r.id, calDate)}>Book</Button>
                          )}
                        </ListGroup.Item>
                      ))}
                    </ListGroup>
                  )}
                </Card>
              </div>
              <div className="lg:col-span-7">
                <Card className="h-full">
                  <Card.Header>Reservations on this date</Card.Header>
                  <Table hover>
                    <thead>
                      <tr><th>Guest</th><th>Room</th><th>Dates</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                      {reservationsOnDate.length === 0 && (
                        <tr><td colSpan={4} className="py-6 text-center text-muted">No reservations on this date.</td></tr>
                      )}
                      {reservationsOnDate.map((r) => (
                        <tr key={r.id}>
                          <td className="font-semibold">{r.guest?.full_name ?? '—'}</td>
                          <td>{r.room?.room_number ?? '—'}</td>
                          <td className="text-xs">{r.check_in} → {r.check_out}</td>
                          <td><Badge bg={RES_VARIANT[r.status]}>{r.status.replace('_', ' ')}</Badge></td>
                        </tr>
                      ))}
                    </tbody>
                  </Table>
                </Card>
              </div>
            </div>
          </Tab>

        </Tabs>
        </>
      )}

      {cancelRefund && (() => {
        const dp = Number(cancelRefund.reservation.downpayment)
        return (
          <Modal show onHide={() => setCancelRefund(null)}>
            <Modal.Header closeButton>
              <Modal.Title>Cancel advance booking</Modal.Title>
            </Modal.Header>
            <Modal.Body>
              <p className="mb-3 text-sm text-muted">
                {cancelRefund.reservation.guest?.full_name ?? 'The guest'} paid a {formatMoney(dp)} downpayment.
                10% ({formatMoney(dp * 0.1)}) is retained and {formatMoney(dp * 0.9)} goes back to the guest,
                recorded as a refund today.
              </p>
              <RefundMethodSelect id="cancel-refund-method" value={cancelRefund.method}
                onChange={(method) => setCancelRefund({ ...cancelRefund, method })} />
              <Form.Group>
                <Form.Label>Reason</Form.Label>
                <Form.Control id="cancel-refund-reason" as="textarea" rows={2} maxLength={500}
                  value={cancelRefund.reason} placeholder="e.g. Guest changed travel plans"
                  onChange={(e) => setCancelRefund({ ...cancelRefund, reason: e.target.value })} />
                <Form.Text>Saved with the cancellation and can’t be edited later.</Form.Text>
              </Form.Group>
            </Modal.Body>
            <Modal.Footer>
              <Button variant="secondary" onClick={() => setCancelRefund(null)}>Back</Button>
              <Button variant="danger"
                disabled={!cancelRefund.method || cancelRefund.reason.trim().length < 5 || pending !== null}
                onClick={async () => {
                  const { reservation, method, reason } = cancelRefund
                  setCancelRefund(null)
                  await runTransition(reservation.id, 'cancel', { refund_method: method, reason: reason.trim() })
                }}>
                Cancel and refund {formatMoney(dp * 0.9)}
              </Button>
            </Modal.Footer>
          </Modal>
        )
      })()}
      <ReasonModal show={cancelWithReason !== null}
        title="Cancel reservation"
        description={cancelWithReason && `Cancelling ${cancelWithReason.guest?.full_name ?? 'this stay'}`
          + ' reverses the charges already posted to the guest’s invoice. The reversal lines stay on the invoice.'}
        confirmLabel="Cancel reservation"
        onHide={() => setCancelWithReason(null)}
        onConfirm={async (reason) => {
          await transitionReservation(cancelWithReason.id, 'cancel', { reason })
          setCancelWithReason(null)
          await refresh()
        }} />
      <ReasonModal show={reverseFor !== null}
        title="Reverse room charge"
        description={reverseFor && `Takes the room charge${Number(reverseFor.downpayment) > 0 ? ' and downpayment credit' : ''} for `
          + `${reverseFor.guest?.full_name ?? 'this stay'} off the open invoice. The lines stay on the invoice as reversals; `
          + 'the stay reads Not billed and can be billed again.'}
        confirmLabel="Reverse charge"
        onHide={() => setReverseFor(null)}
        onConfirm={async (reason) => {
          await reverseRoomCharge(reverseFor.id, reason)
          setReverseFor(null)
          await refresh()
        }} />
      {serviceChange && (
        <ReasonModal show
          title={`Room ${serviceChange.room.room_number}: ${SERVICE_ACTIONS[serviceChange.target].title}`}
          description={SERVICE_ACTIONS[serviceChange.target].description}
          confirmLabel={SERVICE_ACTIONS[serviceChange.target].title}
          optional={serviceChange.target === 'in_service'}
          onHide={() => setServiceChange(null)}
          onConfirm={async (reason) => {
            await setRoomService(serviceChange.room.id, serviceChange.target, reason)
            setServiceChange(null)
            await refresh()
          }} />
      )}
      {roomHistoryOf && (
        <Modal show onHide={() => setRoomHistoryOf(null)} centered>
          <Modal.Header closeButton><Modal.Title>Room {roomHistoryOf.room_number} · service history</Modal.Title></Modal.Header>
          <Modal.Body className="max-h-[65vh] overflow-y-auto">
            <RoomServiceHistory roomId={roomHistoryOf.id} />
          </Modal.Body>
          <Modal.Footer><Button variant="secondary" onClick={() => setRoomHistoryOf(null)}>Close</Button></Modal.Footer>
        </Modal>
      )}

      {modal?.type === 'reservation' && (
        <ReservationModal rooms={rooms} rates={rates} bookingSources={bookingSources} promoRates={promoRates}
          extraCharges={extraCharges}
          propertyId={propertyId} defaultRoomId={reservationRoomId} defaultCheckIn={reservationDate}
          reservation={modal.reservation}
          onClose={() => setModal(null)} onSaved={() => { setModal(null); refresh() }} />
      )}

      {earlyConfirm && (
        <Modal show onHide={() => setEarlyConfirm(null)} centered>
          <Modal.Header closeButton><Modal.Title>Early check-in</Modal.Title></Modal.Header>
          <Modal.Body>
            <p>
              It&apos;s before noon, so checking in{' '}
              <strong>{earlyConfirm.guest?.full_name ?? 'this guest'}</strong> now counts as an{' '}
              <strong>early check-in</strong>.
            </p>
            {earlyFee > 0 ? (
              earlyConfirm.guest_id ? (
                <Alert variant="warning" className="mb-0">
                  An early check-in fee of <strong>{formatMoney(earlyFee)}</strong> will be added
                  to the guest&apos;s bill.
                </Alert>
              ) : (
                <Alert variant="secondary" className="mb-0">
                  This reservation has no guest on file, so the {formatMoney(earlyFee)} fee can&apos;t
                  be billed — the guest will simply be checked in early.
                </Alert>
              )
            ) : (
              <Alert variant="secondary" className="mb-0">
                No early check-in fee is set, so nothing will be charged.
              </Alert>
            )}
          </Modal.Body>
          <Modal.Footer>
            <Button variant="secondary" onClick={() => setEarlyConfirm(null)}>Cancel</Button>
            <Button variant="primary"
              onClick={() => { const r = earlyConfirm; setEarlyConfirm(null); runTransition(r.id, 'check-in', { early_check_in: true }) }}>
              Proceed with early check-in
            </Button>
          </Modal.Footer>
        </Modal>
      )}
    </div>
  )
}

// Editing an existing 'booked' reservation reuses this same modal, scoped to
// booking details only (room/dates/source/discount/beds) — the linked guest
// isn't editable here (that's the Guests module's job), and the backend
// blocks the edit entirely once checked in or once a downpayment has been
// collected against the original quote (cancel and rebook instead).
function ReservationModal({
  rooms, rates, bookingSources, promoRates, extraCharges = [], propertyId, defaultRoomId, defaultCheckIn, reservation,
  onClose, onSaved,
}) {
  const editing = Boolean(reservation)
  const firstAvailable = rooms.find((r) => r.status === 'available')
  // New bookings start as a walk-in, since that's the common case at a desk —
  // but opening the form from a future date on the Calendar clearly isn't one,
  // so that defaults to a channel instead (and keeps the date that was picked).
  const forFutureDate = Boolean(defaultCheckIn) && defaultCheckIn > todayStr()
  const [form, setForm] = useState({
    room_id: reservation?.room_id ?? defaultRoomId ?? firstAvailable?.id ?? '',
    // Never blank: a walk-in's date field is disabled, so an empty default
    // would leave a required field nobody can fill.
    check_in: reservation?.check_in ?? defaultCheckIn ?? todayStr(),
    check_out: reservation?.check_out ?? '',
    source: reservation?.source
      ?? (forFutureDate ? bookingSources[0]?.code ?? WALK_IN : WALK_IN),
    booking_reference: reservation?.booking_reference ?? '',
    sold_rate: reservation?.sold_rate ?? '',
    // The channel's own promotion: '' (none) | percent | fixed.
    channel_discount_type: reservation?.channel_discount_type ?? '',
    channel_discount_value: reservation?.channel_discount_value ?? '',
    referral: Boolean(reservation?.discount_amount),
    discount_amount: reservation?.discount_amount ?? '',
    guest_name: '', guest_type: 'local', nationality: '',
    contact_number: '', email: '', address: '',
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  // Senior/PWD statutory discount — a room can hold several qualified guests
  // (an elderly couple, say), each recorded with their own name + ID. The 20%
  // only covers each beneficiary's own even share of the room, so it needs the
  // guest count to divide by. [{key, discount_type, name, id_number}], exactly
  // as Food & Orders records the diners on an order.
  const [beneficiaries, setBeneficiaries] = useState(
    () => (reservation?.reservation_discounts ?? []).map((d, i) => ({
      key: i, discount_type: d.discount_type, name: d.beneficiary_name, id_number: d.id_number,
    })),
  )
  const [totalGuests, setTotalGuests] = useState(Number(reservation?.total_guests) || 1)

  // The admin's custom extra charges (Extra Charges tab), each with a
  // quantity: {extra_charge_id: qty}. A charge already on the booking is
  // offered at the name/amount it was picked at, even if it has since been
  // repriced or switched off — the backend keeps that snapshot too. Early
  // check-in isn't offered: check-in itself bills that.
  const [extraQty, setExtraQty] = useState(() => Object.fromEntries(
    (reservation?.reservation_extra_charges ?? []).map((x) => [x.extra_charge_id, x.quantity]),
  ))
  const onBooking = reservation?.reservation_extra_charges ?? []
  const extraOptions = [
    ...onBooking.map((x) => ({ id: x.extra_charge_id, name: x.name, amount: x.amount })),
    ...extraCharges
      .filter((c) => !c.code && c.is_active && !onBooking.some((x) => x.extra_charge_id === c.id))
      .map((c) => ({ id: c.id, name: c.name, amount: c.amount })),
  ]
  const pickedExtras = extraOptions
    .filter((o) => extraQty[o.id] > 0)
    .map((o) => ({ ...o, quantity: extraQty[o.id] }))
  const setExtra = (id, qty) => setExtraQty((q) => ({ ...q, [id]: qty }))
  const addBeneficiary = () => {
    setBeneficiaries((bs) => [...bs, { key: Date.now(), discount_type: 'senior', name: '', id_number: '' }])
    // Never fewer guests than beneficiaries — the backend rejects that, and it
    // would mean discounting more of the room than there are people in it.
    setTotalGuests((tg) => Math.max(tg, beneficiaries.length + 1))
  }
  const removeBeneficiary = (key) => setBeneficiaries((bs) => bs.filter((b) => b.key !== key))
  const setBeneficiaryField = (key, field) => (e) =>
    setBeneficiaries((bs) => bs.map((b) => (b.key === key ? { ...b, [field]: e.target.value } : b)))

  // `source` stays the single value that gets submitted — walk_in or a
  // channel code. The two controls above it are just a clearer way to pick
  // one: a walk-in and an OTA booking are different situations, not two
  // neighbours in one long list.
  const isWalkIn = form.source === WALK_IN
  const hasChannels = bookingSources.length > 0

  // Only a holder of front_desk.reservation.backdate (a Manager) may record a
  // stay that started before today (the backend refuses anyone else). It's
  // saved as whatever its dates say it is by now: checked out if it's over,
  // checked in if the guest is still here.
  const { can } = useAuth()
  const canBackdate = can(P.FRONT_DESK_RESERVATION_BACKDATE)
  const canCorrect = can(P.FRONT_DESK_RESERVATION_CORRECT)
  const canDelete = can(P.FRONT_DESK_RESERVATION_DELETE)
  // Opened on a reservation this user can't change (settled, cancelled, or a
  // stay only an admin may correct): same form, every field disabled, no
  // Save/Delete — a view of all its details.
  const readOnlyReason = editing ? editBlockReason(reservation, canCorrect) : null
  const readOnly = readOnlyReason !== null
  const isPast = !editing && Boolean(form.check_in) && form.check_in < todayStr()
  const pastEnded = isPast && Boolean(form.check_out) && form.check_out <= todayStr()
  // A stay that's under way or over (admin-only to edit) keeps its status, so
  // its dates can't be moved to say it hasn't happened yet.
  const isStay = reservation?.status === 'checked_in' || reservation?.status === 'checked_out'
  // Build step 8: the changes that say why, asked in the form (the server
  // refuses them without one). One reason covers the whole save.
  const [reason, setReason] = useState('')
  const movesIntoPast = editing && reservation.status === 'booked' && Boolean(form.check_in)
    && form.check_in < todayStr() && form.check_in !== reservation.check_in
  const referralSet = Boolean(form.referral) && Number(form.discount_amount) > 0
    && (!editing || Number(form.discount_amount) !== Number(reservation.discount_amount ?? 0))
  const reasonNeeded = editing && isStay ? 'Correcting a stay'
    : (isPast || movesIntoPast) ? 'Entering a stay before today'
      : referralSet ? 'Giving a referral discount' : null
  // Any room may be picked for nights that are already over; today's status
  // says nothing about who was in it then.
  const stayEnded = Boolean(form.check_in) && form.check_in < todayStr()
    && Boolean(form.check_out) && form.check_out <= todayStr()

  // Deleting is a Manager's soft delete with a reason (build step 8): the
  // reservation and its history are kept, hidden from the lists.
  const [confirmDelete, setConfirmDelete] = useState(false)
  const deleting = confirmDelete

  // A walk-in is someone at the desk right now, so the stay starts today and
  // the date isn't the receptionist's to choose. The backend decides this for
  // itself too — this only keeps the form from disagreeing with the result.
  function setBookingType(type) {
    setForm((f) => (type === WALK_IN
      ? { ...f, source: WALK_IN, check_in: todayStr() }
      : { ...f, source: bookingSources[0]?.code ?? '' }))
  }

  // The promo rate is read-only here: the room's original rate × the admin's
  // multiplier for the picked source (the backend computes the same on booking).
  const baseRate = resolveBaseRate(rates, form.room_id)
  const multiplier = form.source === WALK_IN
    ? null
    : resolvePromoMultiplier(promoRates, form.source, form.room_id)
  const promoRate = multiplier !== null && baseRate > 0 ? multiplier * baseRate : null

  // Advance booking (check-in after today) collects a 50% downpayment of the
  // estimated total — promo rate and discount included. The backend computes
  // the authoritative amount the same way. Senior/PWD is 20% of each
  // beneficiary's own share of the room, not 20% of the room; referral is a
  // flat amount the receptionist types in, applied on top of whatever's left
  // after the statutory discount and capped there so the estimate can't go
  // negative while they're still typing — the two stack, since a guest can be
  // a senior citizen *and* have a referral.
  const nights = form.check_in && form.check_out
    ? Math.max(0, Math.round((new Date(form.check_out) - new Date(form.check_in)) / 86400000))
    : 0
  const nightly = promoRate ?? (baseRate > 0 ? baseRate : null)
  const estSubtotal = nightly !== null && nights > 0 ? nightly * nights : 0
  // The channel's promotion comes off first — it's the price the OTA sold
  // the stay at, so the Senior/PWD share and the referral are taken from that.
  const channelValue = Number(form.channel_discount_value) || 0
  const estChannelDiscount = isWalkIn || channelValue <= 0 ? 0
    : form.channel_discount_type === 'percent' ? estSubtotal * Math.min(channelValue, 100) / 100
      : form.channel_discount_type === 'fixed' ? Math.min(channelValue, estSubtotal)
        : 0
  const estAfterChannel = estSubtotal - estChannelDiscount
  const qualifying = Math.min(beneficiaries.length, totalGuests)
  const estStatutoryDiscount = qualifying > 0
    ? estAfterChannel * (qualifying / totalGuests) * 0.2
    : 0
  const estRemaining = Math.max(0, estAfterChannel - estStatutoryDiscount)
  const estReferralDiscount = form.referral
    ? Math.min(Number(form.discount_amount) || 0, estRemaining)
    : 0
  const estDiscount = estChannelDiscount + estStatutoryDiscount + estReferralDiscount
  const extraLines = pickedExtras.map((x) => ({
    label: `${x.name} × ${x.quantity}`,
    amount: Number(x.amount) * x.quantity,
  }))
  const estExtras = extraLines.reduce((sum, x) => sum + x.amount, 0)
  const estTotal = Math.max(0, estSubtotal - estDiscount) + estExtras
  const isAdvance = Boolean(form.check_in) && form.check_in > todayStr()
  const downpayment = isAdvance ? estTotal * 0.5 : 0
  // Only the discounts actually applied, each as its own line.
  const estimate = {
    subtotalLabel: `${nights} night${nights === 1 ? '' : 's'} × ${formatMoney(nightly ?? 0)}`
      + (promoRate !== null ? ' (promo)' : ''),
    subtotal: estSubtotal,
    discounts: [
      estChannelDiscount > 0 && {
        label: `${sourceLabel(bookingSources, form.source)} discount`
          + (form.channel_discount_type === 'percent' ? ` (${channelValue}%)` : ''),
        amount: estChannelDiscount,
      },
      estStatutoryDiscount > 0 && {
        label: `Senior/PWD (${qualifying} of ${totalGuests})`,
        amount: estStatutoryDiscount,
      },
      estReferralDiscount > 0 && { label: 'Referral', amount: estReferralDiscount },
    ].filter(Boolean),
    extras: extraLines,
    total: estTotal,
    downpayment,
  }
  const [guestId, setGuestId] = useState(null) // set when reusing an existing guest
  const [duplicates, setDuplicates] = useState(null)
  // Booking with a new guest despite look-alikes needs a reason (G3, GU3).
  const [newGuestReason, setNewGuestReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)
  // Guest name autocomplete over previously-registered guests.
  const [suggestions, setSuggestions] = useState([])
  const [showSug, setShowSug] = useState(false)
  const [searching, setSearching] = useState(false) // true while a debounced lookup is in flight

  useEffect(() => {
    if (editing || !showSug || guestId) return undefined
    const q = form.guest_name.trim()
    // eslint-disable-next-line react-hooks/set-state-in-effect -- derived reset, not a data fetch
    if (q.length < 2) { setSuggestions([]); setSearching(false); return undefined }
    let active = true
    setSearching(true)
    const t = setTimeout(async () => {
      try {
        const list = await listGuests(propertyId, { q })
        if (active) setSuggestions(list.slice(0, 8))
      } catch { /* ignore search errors */ } finally {
        if (active) setSearching(false)
      }
    }, 200)
    return () => { active = false; clearTimeout(t) }
  }, [form.guest_name, showSug, guestId, propertyId, editing])

  // Reuse an existing guest: pin its id and pre-fill the detail fields. Any
  // field the user then fills that was empty on file completes the record on save.
  function pickGuest(g) {
    setGuestId(g.id)
    setDuplicates(null)
    setShowSug(false)
    setSuggestions([])
    setForm((f) => ({
      ...f,
      guest_name: g.full_name,
      guest_type: g.guest_type ?? f.guest_type,
      nationality: g.nationality ?? '',
      contact_number: g.contact_number ?? '',
      email: g.email ?? '',
      address: g.address ?? '',
    }))
  }

  // Undo picking a guest (e.g. the wrong suggestion was clicked): drop the
  // link and blank every auto-filled detail field back out so the receptionist
  // can search again or type a fresh guest from scratch.
  function clearGuestSelection() {
    setGuestId(null)
    setDuplicates(null)
    setSuggestions([])
    setShowSug(false)
    setForm((f) => ({
      ...f,
      guest_name: '',
      guest_type: 'local',
      nationality: '',
      contact_number: '',
      email: '',
      address: '',
    }))
  }

  function buildPayload(extra = {}) {
    const payload = { ...form, ...extra }
    if (reasonNeeded) payload.reason = reason.trim()
    if (!payload.referral) payload.discount_amount = ''
    delete payload.referral
    // Sent blank to clear it: a walk-in has no channel to have promised one.
    if (payload.source === WALK_IN || !payload.channel_discount_type) {
      payload.channel_discount_type = ''
      payload.channel_discount_value = ''
    }
    // Always sent, so an edit that removed the last beneficiary clears them
    // rather than leaving the old rows in place.
    payload.total_guests = totalGuests
    payload.discount_beneficiaries = beneficiaries.map((b) => ({
      discount_type: b.discount_type, name: b.name.trim(), id_number: b.id_number.trim(),
    }))
    // Always sent too, so unticking the last one clears them.
    payload.extra_charges = pickedExtras.map((x) => ({ extra_charge_id: x.id, quantity: x.quantity }))
    if (guestId) { payload.guest_id = guestId; delete payload.guest_name }
    return payload
  }

  async function book(force) {
    setBusy(true)
    setErr(null)
    try {
      if (editing) {
        await updateReservation(reservation.id, buildPayload())
        onSaved()
        return
      }
      // Warn about a duplicate only when creating a new (typed) guest.
      if (!force && !guestId && form.guest_name.trim()) {
        const matches = await matchGuests(
          { full_name: form.guest_name, email: form.email, contact_number: form.contact_number },
          propertyId,
        )
        if (matches.length) { setDuplicates(matches); setBusy(false); return }
      }
      const payload = buildPayload()
      if (force && !guestId) {
        payload.new_guest_force = true
        payload.new_guest_reason = newGuestReason.trim()
      }
      await createReservation(payload, propertyId)
      onSaved()
    } catch (ex) {
      setErr(describeError(ex, 'Save failed. Check the fields and try again.'))
      setBusy(false)
    }
  }

  return (
    <>
    <Modal show={!confirmDelete} onHide={onClose} centered size="xl">
      <Form onSubmit={(e) => { e.preventDefault(); if (!readOnly) book(false) }}>
        <Modal.Header closeButton>
          <Modal.Title>
            {readOnly ? 'Reservation details' : editing ? 'Edit reservation' : 'New reservation'}
          </Modal.Title>
        </Modal.Header>
        <Modal.Body className="p-0">
         {readOnly && (
          <div className="border-b border-line bg-subtle px-4 py-3 text-sm">
            <span className="font-medium">{reservation.status.replace('_', ' ')}</span>
            {' · '}{BILLING_STATE[reservation.billing_state]?.label ?? 'Not billed'}
            <span className="text-muted"> — view only. {readOnlyReason}</span>
            {reservation.room_charge_invoice && (
              <div className="mt-1 text-xs text-muted">
                The billed amounts are on the guest’s invoice (Invoices tab); the estimate below is
                re-quoted from today’s rates.
              </div>
            )}
          </div>
         )}
         {/* A disabled fieldset makes every control inside inert at once, so a
             read-only view can't drift from the editable form it mirrors. */}
         <fieldset disabled={readOnly} className="m-0 min-w-0 border-0 p-0">
         <div className="flex flex-col md:flex-row">
          {/* The rail is the booking type. Unlike tabs over one required
              form, hiding the channel fields for a walk-in is correct: a
              walk-in has no channel, so those fields don't exist for it —
              they're not skipped, they don't apply. */}
          <div className="w-full shrink-0 border-b border-line p-4 md:w-60 md:border-b-0 md:border-r">
            <div className="mb-2 text-xs font-medium uppercase tracking-[0.04em] text-muted">
              Booking type
            </div>
            <div
              role="radiogroup"
              aria-label="Booking type"
              className="flex gap-2 md:flex-col"
            >
              {BOOKING_TYPES.map((t) => {
                const selected = isWalkIn === (t.id === WALK_IN)
                const blocked = editing || (t.id !== WALK_IN && !hasChannels)
                return (
                  <button
                    key={t.id}
                    type="button"
                    role="radio"
                    aria-checked={selected}
                    disabled={blocked}
                    onClick={() => setBookingType(t.id)}
                    className={`flex-1 rounded-lg border px-3 py-2 text-left text-sm font-medium transition-colors md:flex-none ${
                      selected
                        ? 'border-ink bg-subtle text-body'
                        : 'border-line text-muted hover:bg-subtle hover:text-body'
                    } ${blocked ? 'pointer-events-none opacity-50' : ''}`}
                  >
                    {t.label}
                    <span className="mt-0.5 block text-xs font-normal text-muted">{t.hint}</span>
                  </button>
                )
              })}
            </div>
            {!hasChannels && (
              <p className="mt-2 mb-0 text-xs text-muted">
                No channels yet — add one on the Promo Rates tab.
              </p>
            )}
            {editing && (
              <p className="mt-2 mb-0 text-xs text-muted">
                A booking can&apos;t change type after it&apos;s made.
              </p>
            )}
            <div className="mt-4 hidden border-t border-line pt-3 md:block">
              <div className="text-xs font-medium uppercase tracking-[0.04em] text-muted">
                Est. total
              </div>
              <EstimateBreakdown {...estimate} />
            </div>
          </div>

          <div className="min-w-0 flex-1 p-4 md:max-h-[70vh] md:overflow-y-auto">
          {err && <Alert variant="danger">{err}</Alert>}
          {editing && (
            <div className="mb-4 flex items-center gap-2">
              <span className="text-sm text-muted">Guest:</span>
              <span className="font-semibold">{reservation.guest?.full_name ?? '—'}</span>
              {reservation.guest && <Badge bg="light" className="font-normal">{reservation.guest.guest_type}</Badge>}
            </div>
          )}
          <section id="stay" data-section>
            <h3 className="mb-3 text-xs font-semibold uppercase tracking-[0.04em] text-muted">Stay</h3>
          <div className="grid grid-cols-1 gap-x-6 md:grid-cols-12">
            <Form.Group className="mb-4 md:col-span-6">
              <Form.Label>Room</Form.Label>
              <Form.Select value={form.room_id} onChange={set('room_id')} required>
                {rooms.map((r) => (
                  <option key={r.id} value={r.id} disabled={!stayEnded && r.status !== 'available' && r.id !== reservation?.room_id}>
                    {r.room_number} — {r.room_type ?? 'Room'}
                    {r.status !== 'available' ? ` (${roomStatusLabel(r.status)})` : ''}
                  </option>
                ))}
              </Form.Select>
            </Form.Group>
            <Form.Group className="mb-4 md:col-span-3">
              <Form.Label>Check-in</Form.Label>
              <Form.Control type="date" value={form.check_in} onChange={set('check_in')}
                min={canBackdate ? undefined : todayStr()}
                // An admin may backdate a walk-in, but not move one ahead —
                // nor move a stay that's begun to after today.
                max={(isWalkIn && canBackdate && !editing) || isStay ? todayStr() : undefined}
                required disabled={isWalkIn && !editing && !canBackdate} />
              {isWalkIn && !editing && !isPast && <Form.Text muted>Today — the guest is here.</Form.Text>}
            </Form.Group>
            <Form.Group className="mb-4 md:col-span-3">
              <Form.Label>Check-out</Form.Label>
              <Form.Control type="date" value={form.check_out} onChange={set('check_out')}
                min={form.check_in || todayStr()}
                max={reservation?.status === 'checked_out' ? todayStr() : undefined} required />
            </Form.Group>
          </div>
          {/* No "extra beds" count here: an extra bed is an Extra Charge the
              admin prices, picked under Pricing, so it's actually billed. */}
          {isPast ? (
            <div className="-mt-2 mb-4">
              <Form.Text muted>
                {pastEnded
                  ? 'Past stay — saved as checked out. Find it under “All” to mark it paid.'
                  : 'Started before today — saved as checked in.'}
              </Form.Text>
            </div>
          ) : isWalkIn && !editing && (
            <div className="-mt-2 mb-4">
              <Form.Text muted>Arriving now — checks in as soon as you save.</Form.Text>
            </div>
          )}

          {/* The channel's own paperwork. Only an online booking has any of
              it, which is why these appear with the type rather than sitting
              greyed out for every walk-in. */}
          {!isWalkIn && (
            <div className="grid grid-cols-1 gap-x-6 md:grid-cols-12">
              <Form.Group className="mb-4 md:col-span-4">
                <Form.Label>Booking source</Form.Label>
                <Form.Select value={form.source} onChange={set('source')} required>
                  {bookingSources.map((bs) => <option key={bs.id} value={bs.code}>{bs.name}</option>)}
                </Form.Select>
              </Form.Group>
              <Form.Group className="mb-4 md:col-span-4">
                <Form.Label>Booking ID</Form.Label>
                <Form.Control value={form.booking_reference} onChange={set('booking_reference')}
                  required placeholder="e.g. 4821993077" />
                <Form.Text muted>The channel&apos;s confirmation number.</Form.Text>
              </Form.Group>
              <Form.Group className="mb-4 md:col-span-4">
                <Form.Label>Sales stayed (based rate)</Form.Label>
                <Form.Control type="number" min={0.01} step="0.01" value={form.sold_rate}
                  onChange={set('sold_rate')} placeholder="Optional" />
                <Form.Text muted>
                  What the channel sold the night for, before commission — recorded for
                  reconciliation, not used to bill the guest.
                </Form.Text>
              </Form.Group>
            </div>
          )}
          </section>

          {!editing && (
          <section id="guest" data-section className="mt-6 border-t border-line pt-5">
          <div className="mb-3 flex items-center justify-between">
            <h3 className="text-xs font-semibold uppercase tracking-[0.04em] text-muted">Guest</h3>
            {guestId && (
              <span className="flex items-center gap-2">
                <Badge bg="success">Using existing guest</Badge>
                <button type="button" className="text-xs text-red-600 hover:underline dark:text-red-400"
                  onClick={clearGuestSelection}>
                  Wrong guest? Clear
                </button>
              </span>
            )}
          </div>

          {duplicates?.length > 0 && (
            <Alert variant="warning">
              <div className="mb-1 font-semibold">A matching guest already exists</div>
              <ListGroup className="mb-2">
                {duplicates.map((d) => (
                  <ListGroup.Item key={d.id} className="flex items-center justify-between bg-transparent px-0 py-1">
                    <span>
                      {d.full_name}
                      <span className="ml-2 text-xs text-muted">
                        {[d.contact_number, d.email].filter(Boolean).join(' · ')}
                      </span>
                    </span>
                    <Button size="sm" variant="outline-success" onClick={() => pickGuest(d)}>Use this guest</Button>
                  </ListGroup.Item>
                ))}
              </ListGroup>
              <Form.Control as="textarea" rows={2} className="mb-2" value={newGuestReason} maxLength={500}
                onChange={(e) => setNewGuestReason(e.target.value)}
                placeholder="Why a new guest record? e.g. a different person with the same name" />
              <Button size="sm" variant="warning" disabled={busy || newGuestReason.trim().length < 5}
                onClick={() => book(true)}>
                Book with a new guest anyway
              </Button>
            </Alert>
          )}

          <div className="grid grid-cols-1 gap-x-6 md:grid-cols-12">
            <Form.Group className="mb-4 md:col-span-5">
              <Form.Label>Guest name</Form.Label>
              <div className="relative">
                <Form.Control value={form.guest_name} autoComplete="off"
                  onChange={(e) => { setGuestId(null); setShowSug(true); set('guest_name')(e) }}
                  onFocus={() => setShowSug(true)}
                  onBlur={() => setTimeout(() => setShowSug(false), 150)}
                  placeholder="Search a returning guest, or type a new name" />
                {searching && !guestId && (
                  <Spinner size="sm" className="absolute right-2 top-1/2 -translate-y-1/2" />
                )}
                {showSug && !guestId && (searching || suggestions.length > 0) && (
                  <div className="absolute z-10 mt-1 max-h-[220px] w-full overflow-y-auto rounded-lg border border-line bg-surface shadow-md"
                    onMouseDown={(e) => e.preventDefault()}>
                    {suggestions.length === 0 && searching && (
                      <div className="px-3 py-2 text-xs text-muted">Searching…</div>
                    )}
                    {suggestions.map((g) => (
                      <button type="button" key={g.id}
                        className="flex w-full flex-col px-3 py-2 text-left hover:bg-subtle"
                        onClick={() => pickGuest(g)}>
                        <span className="text-sm font-semibold">{g.full_name}</span>
                        <span className="text-xs text-muted">
                          {[g.contact_number, g.email].filter(Boolean).join(' · ') || 'No contact on file'}
                        </span>
                      </button>
                    ))}
                  </div>
                )}
              </div>
              {guestId && (
                <Form.Text muted>
                  Filling any blank field below completes this guest&apos;s record.
                </Form.Text>
              )}
            </Form.Group>
            <Form.Group className="mb-4 md:col-span-3">
              <Form.Label>Guest type</Form.Label>
              <Form.Select value={form.guest_type} onChange={set('guest_type')}>
                <option value="local">Local</option>
                <option value="foreign">Foreign</option>
              </Form.Select>
            </Form.Group>
            <Form.Group className="mb-4 md:col-span-4">
              <Form.Label>Nationality</Form.Label>
              <Form.Control value={form.nationality} onChange={set('nationality')} placeholder="Optional" />
            </Form.Group>
          </div>
          <div className="grid grid-cols-1 gap-x-6 md:grid-cols-12">
            <Form.Group className="mb-4 md:col-span-4">
              <Form.Label>Contact number</Form.Label>
              <Form.Control value={form.contact_number} onChange={set('contact_number')} placeholder="Optional" />
            </Form.Group>
            <Form.Group className="mb-4 md:col-span-4">
              <Form.Label>Email</Form.Label>
              <Form.Control type="email" value={form.email} onChange={set('email')} placeholder="Optional" />
            </Form.Group>
            <Form.Group className="mb-4 md:col-span-4">
              <Form.Label>Address</Form.Label>
              <Form.Control value={form.address} onChange={set('address')} placeholder="Optional" />
            </Form.Group>
          </div>
          </section>
          )}

          <section id="pricing" data-section className="mt-6 border-t border-line pt-5">
            <h3 className="mb-3 text-xs font-semibold uppercase tracking-[0.04em] text-muted">Pricing</h3>
            <div className="grid grid-cols-1 gap-x-6 md:grid-cols-12">
              <Form.Group className="mb-4 md:col-span-6">
                <Form.Label>Guests in the room</Form.Label>
                <Form.Control type="number" min={Math.max(1, beneficiaries.length)}
                  value={totalGuests}
                  onChange={(e) => setTotalGuests(
                    Math.max(beneficiaries.length || 1, parseInt(e.target.value, 10) || 1),
                  )}
                  required />
                <Form.Text muted>
                  What a Senior/PWD discount is shared over — each beneficiary&apos;s 20% covers
                  their own share of the room, not the whole room.
                </Form.Text>
              </Form.Group>
              <Form.Group className="mb-4 md:col-span-6">
                <Form.Label>Promo rate</Form.Label>
                <Form.Control value={promoRate !== null ? formatMoney(promoRate) : ''}
                  disabled readOnly
                  placeholder={form.source === WALK_IN ? '—' : 'Not set'} />
                {promoRate !== null && (
                  <Form.Text muted>
                    ×{multiplier} of {formatMoney(baseRate)} original rate
                  </Form.Text>
                )}
                {form.source !== WALK_IN && multiplier === null && (
                  <Form.Text muted>
                    No {sourceLabel(bookingSources, form.source)} multiplier is set — the original room rate applies.
                  </Form.Text>
                )}
                {form.source !== WALK_IN && multiplier !== null && baseRate <= 0 && (
                  <Form.Text muted>
                    This room has no rate yet — add one on the Rates tab first.
                  </Form.Text>
                )}
              </Form.Group>
            </div>
            {/* Only an online booking has a channel to have promised the
                guest a deal — same reason the booking ID sits with the type. */}
            {!isWalkIn && (
              <Form.Group className="mb-4">
                <Form.Label>
                  {sourceLabel(bookingSources, form.source)} discount{' '}
                  <span className="font-normal text-muted">(optional — the channel&apos;s promotion)</span>
                </Form.Label>
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-12">
                  <Form.Select className="sm:col-span-5" value={form.channel_discount_type}
                    onChange={(e) => setForm({
                      ...form,
                      channel_discount_type: e.target.value,
                      channel_discount_value: e.target.value ? form.channel_discount_value : '',
                    })}>
                    <option value="">No discount</option>
                    <option value="percent">Percentage (%)</option>
                    <option value="fixed">Fixed amount (₱)</option>
                  </Form.Select>
                  {form.channel_discount_type && (
                    <InputGroup className="sm:col-span-7">
                      {form.channel_discount_type === 'fixed' && <InputGroup.Text>₱</InputGroup.Text>}
                      <Form.Control type="number" min={0.01} step="0.01"
                        max={form.channel_discount_type === 'percent' ? 100 : undefined}
                        value={form.channel_discount_value} onChange={set('channel_discount_value')}
                        required placeholder={form.channel_discount_type === 'percent' ? 'e.g. 10' : 'e.g. 300'} />
                      {form.channel_discount_type === 'percent' && <InputGroup.Text>%</InputGroup.Text>}
                    </InputGroup>
                  )}
                </div>
                {estChannelDiscount > 0 ? (
                  <Form.Text muted>
                    −{formatMoney(estChannelDiscount)} off the room, before any Senior/PWD or referral discount.
                  </Form.Text>
                ) : (
                  <Form.Text muted>
                    A percentage of the room total, or a fixed amount off the whole stay.
                  </Form.Text>
                )}
              </Form.Group>
            )}
            <Form.Group className="mb-4">
              <div className="mb-1 flex items-center justify-between">
                <Form.Label className="mb-0">
                  Senior/PWD discount{' '}
                  <span className="font-normal text-muted">(optional — one per qualified guest)</span>
                </Form.Label>
                <Button size="sm" variant="outline-secondary" onClick={addBeneficiary}>
                  + Add beneficiary
                </Button>
              </div>
              {beneficiaries.length === 0 && (
                <Form.Text muted>
                  Add one for each Senior Citizen or PWD staying in the room — their name and ID
                  are recorded against the discount.
                </Form.Text>
              )}
              {beneficiaries.map((b) => (
                <div key={b.key} className="mb-1 flex items-center gap-1">
                  <Form.Select size="sm" style={{ width: 90 }} value={b.discount_type}
                    onChange={setBeneficiaryField(b.key, 'discount_type')}>
                    <option value="senior">Senior</option>
                    <option value="pwd">PWD</option>
                  </Form.Select>
                  <Form.Control size="sm" value={b.name} onChange={setBeneficiaryField(b.key, 'name')}
                    placeholder="Beneficiary name" required />
                  <Form.Control size="sm" value={b.id_number} onChange={setBeneficiaryField(b.key, 'id_number')}
                    placeholder="ID number" required />
                  <button type="button" title="Remove"
                    className="px-1 text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                    onClick={() => removeBeneficiary(b.key)}>×</button>
                </div>
              ))}
              {qualifying > 0 && estSubtotal > 0 && (
                <Form.Text muted>
                  −{formatMoney(estStatutoryDiscount)} — {qualifying} of {totalGuests}{' '}
                  guest{totalGuests === 1 ? '' : 's'} qualify, 20% of their own share.
                </Form.Text>
              )}
            </Form.Group>
            <Form.Group className="mb-4">
              <Form.Check type="checkbox" label="Referral discount"
                checked={form.referral}
                onChange={(e) => setForm({ ...form, referral: e.target.checked })} />
              {form.referral && (
                <>
                  <Form.Control className="mt-2" type="number" min={0.01} step="0.01" value={form.discount_amount}
                    onChange={set('discount_amount')} required autoFocus placeholder="e.g. 500" />
                  <Form.Text muted>
                    Flat amount off the room total, on top of any senior/PWD discount above
                    {estRemaining > 0 ? ` (max ${formatMoney(estRemaining)})` : ''}.
                  </Form.Text>
                </>
              )}
            </Form.Group>
            {extraOptions.length > 0 && (
              <Form.Group className="mb-4">
                <Form.Label>Extra charges</Form.Label>
                {extraOptions.map((o) => {
                  const qty = extraQty[o.id] ?? 0
                  return (
                    <div key={o.id} className="mb-2 flex min-h-9 items-center gap-3">
                      <Form.Check type="checkbox" className="flex-1"
                        label={`${o.name} — ${formatMoney(o.amount)}`}
                        checked={qty > 0}
                        onChange={(e) => setExtra(o.id, e.target.checked ? 1 : 0)} />
                      {qty > 0 && (
                        <div className="w-20 shrink-0">
                          <Form.Control type="number" min={1} max={99} value={qty}
                            aria-label={`${o.name} quantity`}
                            onChange={(e) => setExtra(o.id, Math.min(99, Math.max(1, Number(e.target.value) || 1)))} />
                        </div>
                      )}
                    </div>
                  )
                })}
                <Form.Text muted>
                  Added on top of the room after its discounts, and billed with the room charge.
                </Form.Text>
              </Form.Group>
            )}
            {/* The rail carries this on wider screens, where it stays in view;
                on a phone the rail is gone, so it belongs here instead. */}
            {estSubtotal > 0 && (
              <div className="mb-4 border-t border-line pt-3 md:hidden">
                <span className="text-xs font-medium uppercase tracking-[0.04em] text-muted">
                  Est. total
                </span>
                <EstimateBreakdown {...estimate} />
              </div>
            )}
            {downpayment > 0 && !readOnly && (
              <Alert variant="info" className="mb-0 px-4 py-2">
                <strong>Advance booking</strong> — collect a downpayment of{' '}
                <strong>{formatMoney(downpayment)}</strong> (50% of the {formatMoney(estTotal)} total,
                promo rate, discounts and extras included). If the booking is later cancelled, 10% of the
                downpayment is retained.
              </Alert>
            )}
          </section>
          </div>
         </div>
          {reasonNeeded && !readOnly && (
            <Form.Group className="mt-4">
              <Form.Label htmlFor="reservation-reason">Reason</Form.Label>
              <Form.Control id="reservation-reason" as="textarea" rows={2} maxLength={500} required minLength={5}
                value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Why this is being done" />
              <Form.Text>Needed because {reasonNeeded.toLowerCase()}. Saved with the change and can’t be edited later.</Form.Text>
            </Form.Group>
          )}
         </fieldset>
          {editing && <ReservationPrice reservationId={reservation.id} rooms={rooms} rates={rates} />}
          {editing && <ReservationHistory reservationId={reservation.id} rooms={rooms} />}
        </Modal.Body>
        <Modal.Footer>
          {readOnly ? (
            <Button variant="secondary" onClick={onClose}>Close</Button>
          ) : (
            <>
              {editing && canDelete && (
                <Button variant="outline-danger" className="mr-auto" disabled={busy || deleting}
                  onClick={() => setConfirmDelete(true)}>
                  Delete
                </Button>
              )}
              <Button variant="secondary" onClick={onClose}>Cancel</Button>
              <Button type="submit" disabled={busy || deleting}>
                {busy ? <Spinner size="sm" /> : editing ? 'Save changes' : 'Book'}
              </Button>
            </>
          )}
        </Modal.Footer>
      </Form>
    </Modal>
    <ReasonModal show={confirmDelete}
      title="Delete reservation"
      description="Use this only for a reservation entered by mistake. It disappears from every list, but it and its history are kept, and it can’t be restored. To undo a real booking, cancel it instead."
      confirmLabel="Delete reservation"
      onHide={() => setConfirmDelete(false)}
      onConfirm={async (deleteReason) => {
        await deleteReservation(reservation.id, deleteReason)
        setConfirmDelete(false)
        onSaved()
      }} />
    </>
  )
}

