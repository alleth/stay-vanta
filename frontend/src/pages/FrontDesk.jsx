import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Tab, Tabs, Card, Table, Button, Badge, Modal, Form, Row, Col, Alert, Spinner, ListGroup,
} from 'react-bootstrap'
import { useProperty } from '../context/PropertyContext'
import { useAuth } from '../context/AuthContext'
import { useSubmit } from '../hooks/useSubmit'
import { formatMoney } from '../utils/format'
import { matchGuests, listGuests } from '../api/guests'
import { SkeletonTable, SkeletonCards } from '../components/Skeleton'
import {
  listRooms, createRoom, updateRoom, deleteRoom,
  listRoomRates, createRoomRate, updateRoomRate,
  listPromoRates, createPromoRate, updatePromoRate, deletePromoRate,
  listReservations, createReservation, transitionReservation,
  listExtraCharges, createExtraCharge, updateExtraCharge, deleteExtraCharge,
} from '../api/frontdesk'

// Standard check-in is from noon; arriving earlier in the day is an early check-in.
const isEarlyCheckInNow = () => new Date().getHours() < 12

const todayStr = () => new Date().toISOString().slice(0, 10)
// Monday of the current week as YYYY-MM-DD.
function startOfWeek() {
  const d = new Date()
  d.setDate(d.getDate() - ((d.getDay() + 6) % 7))
  return d.toISOString().slice(0, 10)
}
const dateOf = (ts) => (ts ? new Date(ts).toISOString().slice(0, 10) : null)

const SOURCES = [
  ['walk_in', 'Walk-in'], ['cocotel', 'Cocotel'], ['agoda', 'Agoda'],
  ['trip_com', 'Trip.com'], ['tripadvisor', 'TripAdvisor'],
]
// OTA sources a promo rate can target (walk-ins pay the base rate).
const OTA_SOURCES = SOURCES.filter(([v]) => v !== 'walk_in')
const sourceLabel = (v) => SOURCES.find(([s]) => s === v)?.[1] ?? v

// The promo rate that applies to a source + room: the admin's room-specific
// row wins over a property-wide one; null when none is configured.
function resolvePromoRate(promoRates, source, roomId) {
  const forSource = promoRates.filter((p) => p.source === source)
  const specific = roomId ? forSource.find((p) => p.room_id === Number(roomId)) : null
  const found = specific ?? forSource.find((p) => p.room_id === null)
  return found ? Number(found.rate) : null
}
const ROOM_VARIANT = { available: 'success', occupied: 'danger', maintenance: 'warning' }
const RES_VARIANT = { booked: 'secondary', checked_in: 'primary', checked_out: 'success', cancelled: 'dark' }

const fmtDateTime = (s) => (s ? new Date(s).toLocaleString() : null)

// Allowed manual status changes per current room status. A room becomes
// `occupied` only by booking + checking in a guest (so picking "occupied" opens
// the reservation flow), and returns to `available` automatically on check-out.
function statusOptions(status) {
  if (status === 'available') return ['available', 'occupied', 'maintenance']
  if (status === 'occupied') return ['occupied', 'maintenance']
  if (status === 'maintenance') return ['maintenance', 'available']
  return [status]
}

export default function FrontDesk() {
  const { propertyId } = useProperty()
  const { role } = useAuth()
  const canManageRooms = role === 'owner' || role === 'admin'
  const [rooms, setRooms] = useState([])
  const [rates, setRates] = useState([])
  const [promoRates, setPromoRates] = useState([])
  const [reservations, setReservations] = useState([])
  const [extraCharges, setExtraCharges] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [modal, setModal] = useState(null) // 'reservation' | 'room' | { type:'rate'|'charge', ... }
  const [reservationRoomId, setReservationRoomId] = useState(null)
  const [reservationDate, setReservationDate] = useState(null)
  const [calDate, setCalDate] = useState(todayStr)
  const [resFilter, setResFilter] = useState('today') // today | week | all
  const [pending, setPending] = useState(null) // key of the in-flight inline action
  const [earlyConfirm, setEarlyConfirm] = useState(null) // reservation pending an early check-in
  const today = todayStr()

  const refresh = useCallback(async () => {
    if (!propertyId) return
    try {
      const [rm, rt, pr, rs, ec] = await Promise.all([
        listRooms(propertyId), listRoomRates(propertyId), listPromoRates(propertyId),
        listReservations(propertyId), listExtraCharges(propertyId),
      ])
      setRooms(rm)
      setRates(rt)
      setPromoRates(pr)
      setReservations(rs)
      setExtraCharges(ec)
      setError(null)
    } catch {
      setError('Could not load front desk data.')
    } finally {
      setLoading(false)
    }
  }, [propertyId])

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
    reservations: reservations.filter((r) => r.status === 'booked').length,
    checkedOutToday: reservations.filter((r) => r.status === 'checked_out' && dateOf(r.checked_out_at) === today).length,
    cancelledToday: reservations.filter((r) => r.status === 'cancelled' && dateOf(r.cancelled_at) === today).length,
  }), [rooms, reservations, today])

  // Front Desk shows a "fresh start" each day: pending bookings (booked /
  // checked_in) always show, but completed transactions (checked_out / cancelled)
  // only show if they happened within the selected window (today / this week / all).
  const visibleReservations = useMemo(() => {
    if (resFilter === 'all') return reservations
    const from = resFilter === 'week' ? startOfWeek() : today
    return reservations.filter((r) => {
      if (r.status === 'booked' || r.status === 'checked_in') return true
      const when = r.status === 'cancelled' ? dateOf(r.cancelled_at) : dateOf(r.checked_out_at)
      return when !== null && when >= from
    })
  }, [reservations, resFilter, today])

  // Date filter: which rooms are free, and which reservations fall on calDate.
  // A reservation occupies a room for the nights [check_in, check_out), so the
  // check-out day itself is free again.
  const occupiedOnDate = useMemo(() => {
    const occ = new Set()
    for (const r of reservations) {
      if (r.status === 'cancelled' || !r.check_in || !r.check_out) continue
      if (r.check_in <= calDate && calDate < r.check_out) occ.add(r.room_id)
    }
    return occ
  }, [reservations, calDate])

  const availableOnDate = useMemo(
    () => rooms.filter((r) => r.status !== 'maintenance' && !occupiedOnDate.has(r.id)),
    [rooms, occupiedOnDate],
  )

  // Reservations touching the date (inclusive of arrival & departure days).
  const reservationsOnDate = useMemo(
    () => reservations.filter((r) =>
      r.status !== 'cancelled' && r.check_in && r.check_out
      && r.check_in <= calDate && calDate <= r.check_out),
    [reservations, calDate],
  )

  async function runTransition(id, transition, data = {}) {
    setPending(`${transition}-${id}`)
    setError(null)
    try {
      await transitionReservation(id, transition, data)
      await refresh()
    } catch (ex) {
      setError(ex?.response?.data?.message ?? 'Action failed.')
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
    runTransition(r.id, transition)
  }

  async function doDeleteCharge(charge) {
    if (!window.confirm(`Delete the "${charge.name}" charge?`)) return
    setPending(`charge-${charge.id}`)
    setError(null)
    try {
      await deleteExtraCharge(charge.id)
      await refresh()
    } catch (ex) {
      setError(ex?.response?.data?.message ?? 'Could not delete the charge.')
    } finally {
      setPending(null)
    }
  }

  async function doDeletePromoRate(pr) {
    if (!window.confirm(`Delete the ${sourceLabel(pr.source)} promo rate?`)) return
    setPending(`promo-${pr.id}`)
    setError(null)
    try {
      await deletePromoRate(pr.id)
      await refresh()
    } catch (ex) {
      setError(ex?.response?.data?.message ?? 'Could not delete the promo rate.')
    } finally {
      setPending(null)
    }
  }

  async function changeRoomStatus(room, status) {
    setPending(`room-${room.id}`)
    try {
      await updateRoom(room.id, { room_number: room.room_number, room_type: room.room_type, status })
      await refresh()
    } catch (ex) {
      setError(ex?.response?.data?.message ?? 'Action failed.')
    } finally {
      setPending(null)
    }
  }

  async function doDeleteRoom(room) {
    if (!window.confirm(`Delete room ${room.room_number}? This can't be undone.`)) return
    setPending(`room-${room.id}`)
    setError(null)
    try {
      await deleteRoom(room.id)
      await refresh()
    } catch (ex) {
      setError(ex?.response?.data?.message ?? 'Could not delete the room.')
    } finally {
      setPending(null)
    }
  }

  function onRoomStatusPick(room, value) {
    if (value === room.status) return
    // Putting a guest into a room means making a booking, not flipping a flag.
    if (value === 'occupied') { openReservation(room.id); return }
    changeRoomStatus(room, value)
  }

  function openReservation(roomId = null, date = null) {
    setReservationRoomId(roomId)
    setReservationDate(date)
    setModal('reservation')
  }

  if (!propertyId)
    return <Alert variant="info">Select or create a property to use the front desk.</Alert>

  return (
    <div>
      <h1 className="h3 fw-bold mb-3">Front Desk</h1>
      {error && <Alert variant="danger" onClose={() => setError(null)} dismissible>{error}</Alert>}

      {loading ? (
        <>
          <SkeletonCards count={6} />
          <SkeletonTable rows={5} />
        </>
      ) : (
        <>
        <Row className="g-3 mb-3">
          <SummaryCard label="Available rooms" value={counts.available} variant="success" />
          <SummaryCard label="Occupied rooms" value={counts.occupied} variant="danger" />
          <SummaryCard label="Maintenance" value={counts.maintenance} variant="warning" />
          <SummaryCard label="Reservations" value={counts.reservations} variant="primary" />
          <SummaryCard label="Checked out today" value={counts.checkedOutToday} variant="secondary" />
          <SummaryCard label="Cancelled today" value={counts.cancelledToday} variant="dark" />
        </Row>

        <Tabs defaultActiveKey="reservations" className="mb-3">
          {/* ---- Reservations ---- */}
          <Tab eventKey="reservations" title={`Reservations (${visibleReservations.length})`}>
            <div className="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap">
              <Form.Group className="d-flex align-items-center gap-2 mb-0">
                <Form.Label className="mb-0 small text-muted">Show</Form.Label>
                <Form.Select size="sm" value={resFilter} style={{ width: 'auto' }}
                  onChange={(e) => setResFilter(e.target.value)}>
                  <option value="today">Today&apos;s activity</option>
                  <option value="week">This week</option>
                  <option value="all">All</option>
                </Form.Select>
              </Form.Group>
              <Button onClick={() => openReservation()} disabled={availableRooms.length === 0}>
                New reservation
              </Button>
            </div>
            <Card className="shadow-sm">
              <Table responsive hover className="mb-0 align-middle">
                <thead>
                  <tr>
                    <th>Guest</th><th>Room</th><th>Dates</th><th>Source</th>
                    <th className="text-end">Total</th><th>Status</th>
                    <th>Logs</th><th>Last receptionist</th><th className="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {visibleReservations.length === 0 && (
                    <tr><td colSpan={9} className="text-center text-muted py-4">No reservations to show.</td></tr>
                  )}
                  {visibleReservations.map((r) => (
                    <tr key={r.id} className={r.status === 'cancelled' ? 'text-muted' : undefined}>
                      <td className="fw-semibold">
                        {r.guest?.full_name ?? '—'}{' '}
                        {r.guest && (
                          <Badge bg="light" text="dark" className="fw-normal">{r.guest.guest_type}</Badge>
                        )}
                      </td>
                      <td>{r.room?.room_number ?? '—'}</td>
                      <td className="small">{r.check_in} → {r.check_out}</td>
                      <td className="small">
                        {SOURCES.find((s) => s[0] === r.source)?.[1] ?? r.source}
                        {r.discount_type !== 'none' && (
                          <Badge bg="info" className="ms-1">{r.discount_type}</Badge>
                        )}
                      </td>
                      <td className="text-end">{formatMoney(r.quote?.total)}</td>
                      <td><Badge bg={RES_VARIANT[r.status]}>{r.status.replace('_', ' ')}</Badge></td>
                      <td className="small text-muted" style={{ minWidth: 170 }}>
                        <div>Booked: {fmtDateTime(r.created) ?? '—'}</div>
                        {r.checked_in_at && <div>In: {fmtDateTime(r.checked_in_at)}</div>}
                        {r.checked_out_at && <div>Out: {fmtDateTime(r.checked_out_at)}</div>}
                      </td>
                      <td className="small text-muted">{r.receptionist?.name ?? '—'}</td>
                      <td className="text-end text-nowrap">
                        {r.status === 'booked' && (
                          <Button size="sm" variant="outline-primary" className="me-1"
                            disabled={pending !== null}
                            onClick={() => onTransition(r, 'check-in')}>
                            {pending === `check-in-${r.id}` ? <Spinner size="sm" /> : 'Check in'}
                          </Button>
                        )}
                        {r.status === 'checked_in' && (
                          <Button size="sm" variant="outline-success" className="me-1"
                            disabled={pending !== null}
                            onClick={() => onTransition(r, 'check-out')}>
                            {pending === `check-out-${r.id}` ? <Spinner size="sm" /> : 'Check out'}
                          </Button>
                        )}
                        {(r.status === 'booked' || r.status === 'checked_in') && (
                          <Button size="sm" variant="outline-danger"
                            disabled={pending !== null}
                            onClick={() => onTransition(r, 'cancel')}>
                            {pending === `cancel-${r.id}` ? <Spinner size="sm" /> : 'Cancel'}
                          </Button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            </Card>
          </Tab>

          {/* ---- Rooms ---- */}
          <Tab eventKey="rooms" title={`Rooms (${rooms.length})`}>
            {canManageRooms && (
              <div className="d-flex justify-content-end mb-2">
                <Button onClick={() => setModal('room')}>Add room</Button>
              </div>
            )}
            <Row className="g-3">
              {rooms.length === 0 && <Col><p className="text-muted">No rooms yet.</p></Col>}
              {rooms.map((room) => (
                <Col key={room.id} xs={6} md={4} lg={3}>
                  <Card className="shadow-sm h-100">
                    <Card.Body>
                      <div className="d-flex justify-content-between align-items-start">
                        <div className="fs-4 fw-bold">{room.room_number}</div>
                        <Badge bg={ROOM_VARIANT[room.status]}>{room.status}</Badge>
                      </div>
                      <div className="text-muted small mb-2">{room.room_type ?? 'Room'}</div>
                      <Form.Select size="sm" value={room.status} disabled={pending !== null}
                        onChange={(e) => onRoomStatusPick(room, e.target.value)}>
                        {statusOptions(room.status).map((s) => (
                          <option key={s} value={s}>
                            {s === 'occupied' && room.status === 'available' ? 'occupied → new booking' : s}
                          </option>
                        ))}
                      </Form.Select>
                      {canManageRooms && (
                        <Button size="sm" variant="outline-danger" className="w-100 mt-2"
                          disabled={pending !== null}
                          onClick={() => doDeleteRoom(room)}>
                          {pending === `room-${room.id}` ? <Spinner size="sm" /> : 'Delete room'}
                        </Button>
                      )}
                    </Card.Body>
                  </Card>
                </Col>
              ))}
            </Row>
          </Tab>

          {/* ---- Rates ---- */}
          <Tab eventKey="rates" title={`Rates (${rates.length})`}>
            {canManageRooms && (
              <div className="d-flex justify-content-end mb-2">
                <Button onClick={() => setModal({ type: 'rate' })}>Add rate</Button>
              </div>
            )}
            <Card className="shadow-sm">
              <Table responsive hover className="mb-0 align-middle">
                <thead>
                  <tr>
                    <th>Name</th><th>Applies to</th><th className="text-end">Nightly rate</th>
                    {canManageRooms && <th className="text-end">Actions</th>}
                  </tr>
                </thead>
                <tbody>
                  {rates.length === 0 && (
                    <tr><td colSpan={canManageRooms ? 4 : 3} className="text-center text-muted py-4">No rates yet.</td></tr>
                  )}
                  {rates.map((rt) => (
                    <tr key={rt.id}>
                      <td className="fw-semibold">{rt.name}</td>
                      <td>{rt.room ? `Room ${rt.room.room_number}` : 'All rooms'}</td>
                      <td className="text-end">{formatMoney(rt.base_rate)}</td>
                      {canManageRooms && (
                        <td className="text-end">
                          <Button size="sm" variant="outline-primary"
                            onClick={() => setModal({ type: 'rate', rate: rt })}>Edit</Button>
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </Table>
            </Card>
          </Tab>

          {/* ---- Promo Rates (OTA nightly prices; auto-fill the booking form) ---- */}
          <Tab eventKey="promo-rates" title={`Promo Rates (${promoRates.length})`}>
            {canManageRooms && (
              <div className="d-flex justify-content-end mb-2">
                <Button onClick={() => setModal({ type: 'promo' })}>Add promo rate</Button>
              </div>
            )}
            <Card className="shadow-sm">
              <Table responsive hover className="mb-0 align-middle">
                <thead>
                  <tr>
                    <th>Source</th><th>Applies to</th><th className="text-end">Nightly promo rate</th>
                    {canManageRooms && <th className="text-end">Actions</th>}
                  </tr>
                </thead>
                <tbody>
                  {promoRates.length === 0 && (
                    <tr><td colSpan={canManageRooms ? 4 : 3} className="text-center text-muted py-4">No promo rates yet.</td></tr>
                  )}
                  {promoRates.map((pr) => (
                    <tr key={pr.id}>
                      <td className="fw-semibold">{sourceLabel(pr.source)}</td>
                      <td>{pr.room ? `Room ${pr.room.room_number}` : 'All rooms'}</td>
                      <td className="text-end">{formatMoney(pr.rate)}</td>
                      {canManageRooms && (
                        <td className="text-end text-nowrap">
                          <Button size="sm" variant="outline-primary" className="me-1"
                            disabled={pending !== null}
                            onClick={() => setModal({ type: 'promo', promoRate: pr })}>Edit</Button>
                          <Button size="sm" variant="outline-danger"
                            disabled={pending !== null}
                            onClick={() => doDeletePromoRate(pr)}>
                            {pending === `promo-${pr.id}` ? <Spinner size="sm" /> : 'Delete'}
                          </Button>
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </Table>
            </Card>
            <p className="text-muted small mt-2 mb-0">
              When a reservation&apos;s <strong>Source</strong> is an OTA, its promo rate fills in
              automatically from this list (a room-specific rate wins over an &quot;All rooms&quot; one).
              Receptionists can&apos;t type promo prices by hand.
            </p>
          </Tab>

          {/* ---- Calendar / availability by date ---- */}
          <Tab eventKey="calendar" title="Calendar">
            <Card className="shadow-sm mb-3">
              <Card.Body className="d-flex align-items-center gap-3 flex-wrap">
                <Form.Group className="d-flex align-items-center gap-2 mb-0">
                  <Form.Label className="mb-0 fw-semibold">Date</Form.Label>
                  <Form.Control type="date" value={calDate} style={{ maxWidth: 190 }}
                    onChange={(e) => setCalDate(e.target.value)} />
                </Form.Group>
                <span className="text-muted small">
                  {availableOnDate.length} room(s) free · {reservationsOnDate.length} reservation(s) on this date
                </span>
              </Card.Body>
            </Card>
            <Row className="g-3">
              <Col lg={5}>
                <Card className="shadow-sm h-100">
                  <Card.Header className="fw-semibold">Available rooms</Card.Header>
                  {availableOnDate.length === 0 ? (
                    <Card.Body><p className="text-muted mb-0">No rooms free on this date.</p></Card.Body>
                  ) : (
                    <ListGroup variant="flush">
                      {availableOnDate.map((r) => (
                        <ListGroup.Item key={r.id} className="d-flex justify-content-between align-items-center">
                          <span>
                            <span className="fw-semibold">{r.room_number}</span>
                            <span className="text-muted small ms-2">{r.room_type ?? 'Room'}</span>
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
              </Col>
              <Col lg={7}>
                <Card className="shadow-sm h-100">
                  <Card.Header className="fw-semibold">Reservations on this date</Card.Header>
                  <Table responsive hover className="mb-0 align-middle">
                    <thead>
                      <tr><th>Guest</th><th>Room</th><th>Dates</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                      {reservationsOnDate.length === 0 && (
                        <tr><td colSpan={4} className="text-center text-muted py-4">No reservations on this date.</td></tr>
                      )}
                      {reservationsOnDate.map((r) => (
                        <tr key={r.id}>
                          <td className="fw-semibold">{r.guest?.full_name ?? '—'}</td>
                          <td>{r.room?.room_number ?? '—'}</td>
                          <td className="small">{r.check_in} → {r.check_out}</td>
                          <td><Badge bg={RES_VARIANT[r.status]}>{r.status.replace('_', ' ')}</Badge></td>
                        </tr>
                      ))}
                    </tbody>
                  </Table>
                </Card>
              </Col>
            </Row>
          </Tab>

          {/* ---- Extra Charges (admin/owner only) ---- */}
          {canManageRooms && (
            <Tab eventKey="charges" title="Extra Charges">
              <div className="d-flex justify-content-end mb-2">
                <Button onClick={() => setModal({ type: 'charge' })}>Add charge</Button>
              </div>
              <Card className="shadow-sm">
                <Table responsive hover className="mb-0 align-middle">
                  <thead>
                    <tr>
                      <th>Charge</th><th className="text-end">Amount</th><th>Status</th>
                      <th className="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {extraCharges.length === 0 && (
                      <tr><td colSpan={4} className="text-center text-muted py-4">No extra charges yet.</td></tr>
                    )}
                    {extraCharges.map((c) => (
                      <tr key={c.id}>
                        <td className="fw-semibold">
                          {c.name}
                          {c.code && <Badge bg="info" className="ms-2 fw-normal">built-in</Badge>}
                        </td>
                        <td className="text-end">{formatMoney(c.amount)}</td>
                        <td><Badge bg={c.is_active ? 'success' : 'secondary'}>{c.is_active ? 'active' : 'inactive'}</Badge></td>
                        <td className="text-end text-nowrap">
                          <Button size="sm" variant="outline-primary" className="me-1"
                            disabled={pending !== null}
                            onClick={() => setModal({ type: 'charge', charge: c })}>Edit</Button>
                          {!c.code && (
                            <Button size="sm" variant="outline-danger"
                              disabled={pending !== null}
                              onClick={() => doDeleteCharge(c)}>
                              {pending === `charge-${c.id}` ? <Spinner size="sm" /> : 'Delete'}
                            </Button>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              </Card>
              <p className="text-muted small mt-2 mb-0">
                The <strong>Early check-in</strong> fee is billed automatically to the guest&apos;s invoice
                when a receptionist checks them in before noon. Set it to 0 to disable.
              </p>
            </Tab>
          )}
        </Tabs>
        </>
      )}

      {modal === 'reservation' && (
        <ReservationModal rooms={rooms} promoRates={promoRates} propertyId={propertyId}
          defaultRoomId={reservationRoomId} defaultCheckIn={reservationDate}
          onClose={() => setModal(null)} onSaved={() => { setModal(null); refresh() }} />
      )}
      {modal === 'room' && (
        <RoomModal propertyId={propertyId}
          onClose={() => setModal(null)} onSaved={() => { setModal(null); refresh() }} />
      )}
      {modal?.type === 'rate' && (
        <RateModal rooms={rooms} propertyId={propertyId} rate={modal.rate}
          onClose={() => setModal(null)} onSaved={() => { setModal(null); refresh() }} />
      )}
      {modal?.type === 'promo' && (
        <PromoRateModal rooms={rooms} propertyId={propertyId} promoRate={modal.promoRate}
          onClose={() => setModal(null)} onSaved={() => { setModal(null); refresh() }} />
      )}
      {modal?.type === 'charge' && (
        <ChargeModal charge={modal.charge} propertyId={propertyId}
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

function SummaryCard({ label, value, variant }) {
  return (
    <Col xs={6} md={4} lg={2}>
      <Card className="shadow-sm h-100">
        <Card.Body>
          <div className="text-muted small">{label}</div>
          <div className={`fs-2 fw-bold text-${variant}`}>{value}</div>
        </Card.Body>
      </Card>
    </Col>
  )
}

function ReservationModal({ rooms, promoRates, propertyId, defaultRoomId, defaultCheckIn, onClose, onSaved }) {
  const firstAvailable = rooms.find((r) => r.status === 'available')
  const [form, setForm] = useState({
    room_id: defaultRoomId ?? firstAvailable?.id ?? '',
    check_in: defaultCheckIn ?? '', check_out: '',
    source: 'walk_in', discount_type: 'none', additional_beds: 0,
    guest_name: '', guest_type: 'local', nationality: '',
    contact_number: '', email: '', address: '',
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  // The promo rate is read-only here: it's the admin's configured OTA price
  // for the picked source (the backend resolves the same value on booking).
  const promoRate = form.source === 'walk_in'
    ? null
    : resolvePromoRate(promoRates, form.source, form.room_id)
  const [guestId, setGuestId] = useState(null) // set when reusing an existing guest
  const [duplicates, setDuplicates] = useState(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)
  // Guest name autocomplete over previously-registered guests.
  const [suggestions, setSuggestions] = useState([])
  const [showSug, setShowSug] = useState(false)

  useEffect(() => {
    if (!showSug || guestId) return undefined
    const q = form.guest_name.trim()
    let active = true
    const t = setTimeout(async () => {
      if (q.length < 2) { if (active) setSuggestions([]); return }
      try {
        const list = await listGuests(propertyId, { q })
        if (active) setSuggestions(list.slice(0, 8))
      } catch { /* ignore search errors */ }
    }, 250)
    return () => { active = false; clearTimeout(t) }
  }, [form.guest_name, showSug, guestId, propertyId])

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

  function buildPayload(extra = {}) {
    const payload = { ...form, ...extra }
    if (guestId) { payload.guest_id = guestId; delete payload.guest_name }
    return payload
  }

  async function book(force) {
    setBusy(true)
    setErr(null)
    try {
      // Warn about a duplicate only when creating a new (typed) guest.
      if (!force && !guestId && form.guest_name.trim()) {
        const matches = await matchGuests(
          { full_name: form.guest_name, email: form.email, contact_number: form.contact_number },
          propertyId,
        )
        if (matches.length) { setDuplicates(matches); setBusy(false); return }
      }
      await createReservation(buildPayload(), propertyId)
      onSaved()
    } catch (ex) {
      setErr(ex?.response?.data?.message ?? 'Save failed. Check the fields and try again.')
      setBusy(false)
    }
  }

  return (
    <Modal show onHide={onClose} centered size="lg">
      <Form onSubmit={(e) => { e.preventDefault(); book(false) }}>
        <Modal.Header closeButton><Modal.Title>New reservation</Modal.Title></Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          <Row>
            <Col md={6}><Form.Group className="mb-3">
              <Form.Label>Room</Form.Label>
              <Form.Select value={form.room_id} onChange={set('room_id')} required>
                {rooms.map((r) => (
                  <option key={r.id} value={r.id} disabled={r.status !== 'available'}>
                    {r.room_number} — {r.room_type ?? 'Room'}
                    {r.status !== 'available' ? ` (${r.status})` : ''}
                  </option>
                ))}
              </Form.Select>
            </Form.Group></Col>
            <Col md={3}><Form.Group className="mb-3">
              <Form.Label>Check-in</Form.Label>
              <Form.Control type="date" value={form.check_in} onChange={set('check_in')}
                min={todayStr()} required />
            </Form.Group></Col>
            <Col md={3}><Form.Group className="mb-3">
              <Form.Label>Check-out</Form.Label>
              <Form.Control type="date" value={form.check_out} onChange={set('check_out')}
                min={form.check_in || todayStr()} required />
            </Form.Group></Col>
          </Row>
          <Row>
            <Col md={4}><Form.Group className="mb-3">
              <Form.Label>Source</Form.Label>
              <Form.Select value={form.source} onChange={set('source')}>
                {SOURCES.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
              </Form.Select>
            </Form.Group></Col>
            <Col md={4}><Form.Group className="mb-3">
              <Form.Label>Discount</Form.Label>
              <Form.Select value={form.discount_type} onChange={set('discount_type')}>
                <option value="none">None</option>
                <option value="senior">Senior citizen (20%)</option>
                <option value="pwd">PWD (20%)</option>
              </Form.Select>
            </Form.Group></Col>
            <Col md={2}><Form.Group className="mb-3">
              <Form.Label>Promo rate</Form.Label>
              <Form.Control value={promoRate !== null ? formatMoney(promoRate) : ''}
                disabled readOnly
                placeholder={form.source === 'walk_in' ? '—' : 'Not set'} />
              {form.source !== 'walk_in' && promoRate === null && (
                <Form.Text className="text-muted">
                  No {sourceLabel(form.source)} promo rate is set — the base room rate applies.
                </Form.Text>
              )}
            </Form.Group></Col>
            <Col md={2}><Form.Group className="mb-3">
              <Form.Label>Extra beds</Form.Label>
              <Form.Control type="number" min={0} value={form.additional_beds} onChange={set('additional_beds')} />
            </Form.Group></Col>
          </Row>
          <hr />
          <div className="d-flex justify-content-between align-items-center mb-2">
            <span className="fw-semibold">Guest details</span>
            {guestId && (
              <Badge bg="success">Using existing guest</Badge>
            )}
          </div>

          {duplicates?.length > 0 && (
            <Alert variant="warning">
              <div className="fw-semibold mb-1">A matching guest already exists</div>
              <ListGroup variant="flush" className="mb-2">
                {duplicates.map((d) => (
                  <ListGroup.Item key={d.id} className="px-0 py-1 bg-transparent d-flex justify-content-between align-items-center">
                    <span>
                      {d.full_name}
                      <span className="text-muted small ms-2">
                        {[d.contact_number, d.email].filter(Boolean).join(' · ')}
                      </span>
                    </span>
                    <Button size="sm" variant="outline-success" onClick={() => pickGuest(d)}>Use this guest</Button>
                  </ListGroup.Item>
                ))}
              </ListGroup>
              <Button size="sm" variant="warning" disabled={busy} onClick={() => book(true)}>
                Book with a new guest anyway
              </Button>
            </Alert>
          )}

          <Row>
            <Col md={5}><Form.Group className="mb-3">
              <Form.Label>Guest name</Form.Label>
              <div className="position-relative">
                <Form.Control value={form.guest_name} autoComplete="off"
                  onChange={(e) => { setGuestId(null); setShowSug(true); set('guest_name')(e) }}
                  onFocus={() => setShowSug(true)}
                  onBlur={() => setTimeout(() => setShowSug(false), 150)}
                  placeholder="Search a returning guest, or type a new name" />
                {showSug && !guestId && suggestions.length > 0 && (
                  <div className="position-absolute w-100 bg-white border rounded shadow-sm mt-1"
                    style={{ zIndex: 5, maxHeight: 220, overflowY: 'auto' }}
                    onMouseDown={(e) => e.preventDefault()}>
                    {suggestions.map((g) => (
                      <button type="button" key={g.id}
                        className="d-flex flex-column w-100 border-0 bg-transparent text-start px-3 py-2"
                        onClick={() => pickGuest(g)}>
                        <span className="fw-semibold small">{g.full_name}</span>
                        <span className="text-muted small">
                          {[g.contact_number, g.email].filter(Boolean).join(' · ') || 'No contact on file'}
                        </span>
                      </button>
                    ))}
                  </div>
                )}
              </div>
              {guestId && (
                <Form.Text className="text-muted">
                  Filling any blank field below completes this guest&apos;s record.
                </Form.Text>
              )}
            </Form.Group></Col>
            <Col md={3}><Form.Group className="mb-3">
              <Form.Label>Guest type</Form.Label>
              <Form.Select value={form.guest_type} onChange={set('guest_type')}>
                <option value="local">Local</option>
                <option value="foreign">Foreign</option>
              </Form.Select>
            </Form.Group></Col>
            <Col md={4}><Form.Group className="mb-3">
              <Form.Label>Nationality</Form.Label>
              <Form.Control value={form.nationality} onChange={set('nationality')} placeholder="Optional" />
            </Form.Group></Col>
          </Row>
          <Row>
            <Col md={4}><Form.Group className="mb-3">
              <Form.Label>Contact number</Form.Label>
              <Form.Control value={form.contact_number} onChange={set('contact_number')} placeholder="Optional" />
            </Form.Group></Col>
            <Col md={4}><Form.Group className="mb-3">
              <Form.Label>Email</Form.Label>
              <Form.Control type="email" value={form.email} onChange={set('email')} placeholder="Optional" />
            </Form.Group></Col>
            <Col md={4}><Form.Group className="mb-3">
              <Form.Label>Address</Form.Label>
              <Form.Control value={form.address} onChange={set('address')} placeholder="Optional" />
            </Form.Group></Col>
          </Row>
        </Modal.Body>
        <Modal.Footer>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={busy}>{busy ? <Spinner size="sm" /> : 'Book'}</Button>
        </Modal.Footer>
      </Form>
    </Modal>
  )
}

function RoomModal({ propertyId, onClose, onSaved }) {
  const [form, setForm] = useState({ room_number: '', room_type: '', status: 'available' })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const { run, busy, err } = useSubmit(async () => {
    await createRoom(form, propertyId)
    onSaved()
  })
  return (
    <Modal show onHide={onClose} centered>
      <Form onSubmit={run}>
        <Modal.Header closeButton><Modal.Title>Add room</Modal.Title></Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          <Form.Group className="mb-3">
            <Form.Label>Room number</Form.Label>
            <Form.Control value={form.room_number} onChange={set('room_number')} required autoFocus />
          </Form.Group>
          <Form.Group>
            <Form.Label>Room type</Form.Label>
            <Form.Control value={form.room_type} onChange={set('room_type')} placeholder="e.g. Deluxe" />
          </Form.Group>
        </Modal.Body>
        <Modal.Footer>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={busy}>{busy ? <Spinner size="sm" /> : 'Create'}</Button>
        </Modal.Footer>
      </Form>
    </Modal>
  )
}

function RateModal({ rooms, propertyId, rate, onClose, onSaved }) {
  const editing = Boolean(rate)
  const [form, setForm] = useState({
    name: rate?.name ?? '',
    base_rate: rate?.base_rate ?? '',
    room_id: rate?.room_id ?? '',
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const { run, busy, err } = useSubmit(async () => {
    const payload = { name: form.name, base_rate: form.base_rate, room_id: form.room_id || null }
    if (editing) await updateRoomRate(rate.id, payload)
    else await createRoomRate(payload, propertyId)
    onSaved()
  })
  return (
    <Modal show onHide={onClose} centered>
      <Form onSubmit={run}>
        <Modal.Header closeButton><Modal.Title>{editing ? 'Edit rate' : 'Add rate'}</Modal.Title></Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          <Form.Group className="mb-3">
            <Form.Label>Name</Form.Label>
            <Form.Control value={form.name} onChange={set('name')} required autoFocus placeholder="e.g. Deluxe Standard" />
          </Form.Group>
          <Form.Group className="mb-3">
            <Form.Label>Nightly rate</Form.Label>
            <Form.Control type="number" min={0} step="0.01" value={form.base_rate} onChange={set('base_rate')} required />
          </Form.Group>
          <Form.Group>
            <Form.Label>Applies to</Form.Label>
            <Form.Select value={form.room_id} onChange={set('room_id')}>
              <option value="">All rooms (property-wide)</option>
              {rooms.map((r) => <option key={r.id} value={r.id}>Room {r.room_number}</option>)}
            </Form.Select>
          </Form.Group>
        </Modal.Body>
        <Modal.Footer>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={busy}>{busy ? <Spinner size="sm" /> : editing ? 'Save' : 'Create'}</Button>
        </Modal.Footer>
      </Form>
    </Modal>
  )
}

function PromoRateModal({ rooms, propertyId, promoRate, onClose, onSaved }) {
  const editing = Boolean(promoRate)
  const [form, setForm] = useState({
    source: promoRate?.source ?? OTA_SOURCES[0][0],
    rate: promoRate?.rate ?? '',
    room_id: promoRate?.room_id ?? '',
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const { run, busy, err } = useSubmit(async () => {
    const payload = { source: form.source, rate: form.rate, room_id: form.room_id || null }
    if (editing) await updatePromoRate(promoRate.id, payload)
    else await createPromoRate(payload, propertyId)
    onSaved()
  })
  return (
    <Modal show onHide={onClose} centered>
      <Form onSubmit={run}>
        <Modal.Header closeButton>
          <Modal.Title>{editing ? 'Edit promo rate' : 'Add promo rate'}</Modal.Title>
        </Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          <Form.Group className="mb-3">
            <Form.Label>Booking source</Form.Label>
            <Form.Select value={form.source} onChange={set('source')} autoFocus>
              {OTA_SOURCES.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
            </Form.Select>
          </Form.Group>
          <Form.Group className="mb-3">
            <Form.Label>Nightly promo rate</Form.Label>
            <Form.Control type="number" min={0} step="0.01" value={form.rate} onChange={set('rate')} required />
          </Form.Group>
          <Form.Group>
            <Form.Label>Applies to</Form.Label>
            <Form.Select value={form.room_id} onChange={set('room_id')}>
              <option value="">All rooms (property-wide)</option>
              {rooms.map((r) => <option key={r.id} value={r.id}>Room {r.room_number}</option>)}
            </Form.Select>
          </Form.Group>
        </Modal.Body>
        <Modal.Footer>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={busy}>{busy ? <Spinner size="sm" /> : editing ? 'Save' : 'Create'}</Button>
        </Modal.Footer>
      </Form>
    </Modal>
  )
}

function ChargeModal({ charge, propertyId, onClose, onSaved }) {
  const editing = Boolean(charge)
  const builtIn = Boolean(charge?.code) // early check-in: name & code are fixed
  const [form, setForm] = useState({
    name: charge?.name ?? '',
    amount: charge?.amount ?? '',
    is_active: charge?.is_active ?? true,
  })
  const { run, busy, err } = useSubmit(async () => {
    const payload = { name: form.name, amount: form.amount === '' ? 0 : form.amount, is_active: form.is_active }
    if (editing) await updateExtraCharge(charge.id, payload)
    else await createExtraCharge(payload, propertyId)
    onSaved()
  })

  return (
    <Modal show onHide={onClose} centered>
      <Form onSubmit={run}>
        <Modal.Header closeButton>
          <Modal.Title>{editing ? `Edit ${builtIn ? 'fee' : 'charge'}` : 'Add charge'}</Modal.Title>
        </Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          <Form.Group className="mb-3">
            <Form.Label>Name</Form.Label>
            <Form.Control value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })}
              required autoFocus={!builtIn} disabled={builtIn}
              placeholder="e.g. Late check-out, Extra towel" />
            {builtIn && <Form.Text className="text-muted">This is a built-in charge; its name is fixed.</Form.Text>}
          </Form.Group>
          <Row>
            <Col><Form.Group className="mb-3">
              <Form.Label>Amount</Form.Label>
              <Form.Control type="number" min={0} step="0.01" value={form.amount}
                onChange={(e) => setForm({ ...form, amount: e.target.value })} required autoFocus={builtIn} />
            </Form.Group></Col>
            <Col><Form.Group className="mb-3">
              <Form.Label>Status</Form.Label>
              <Form.Select value={form.is_active ? '1' : '0'}
                onChange={(e) => setForm({ ...form, is_active: e.target.value === '1' })}>
                <option value="1">Active</option>
                <option value="0">Inactive</option>
              </Form.Select>
            </Form.Group></Col>
          </Row>
        </Modal.Body>
        <Modal.Footer>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={busy}>{busy ? <Spinner size="sm" /> : editing ? 'Save' : 'Create'}</Button>
        </Modal.Footer>
      </Form>
    </Modal>
  )
}
