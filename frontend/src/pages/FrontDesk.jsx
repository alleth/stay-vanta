import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Tab, Tabs, Card, Table, Button, Badge, Modal, Form, Row, Col, Alert, Spinner, ListGroup,
} from 'react-bootstrap'
import { useProperty } from '../context/PropertyContext'
import { useAuth } from '../context/AuthContext'
import { useSubmit } from '../hooks/useSubmit'
import { formatMoney } from '../utils/format'
import { matchGuests } from '../api/guests'
import {
  listRooms, createRoom, updateRoom,
  listRoomRates, createRoomRate, updateRoomRate,
  listReservations, createReservation, transitionReservation,
} from '../api/frontdesk'

const SOURCES = [
  ['walk_in', 'Walk-in'], ['cocotel', 'Cocotel'], ['agoda', 'Agoda'],
  ['trip_com', 'Trip.com'], ['tripadvisor', 'TripAdvisor'],
]
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
  const [reservations, setReservations] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [modal, setModal] = useState(null) // 'reservation' | 'room' | { type:'rate', rate? }
  const [reservationRoomId, setReservationRoomId] = useState(null)

  const refresh = useCallback(async () => {
    if (!propertyId) return
    try {
      const [rm, rt, rs] = await Promise.all([
        listRooms(propertyId), listRoomRates(propertyId), listReservations(propertyId),
      ])
      setRooms(rm)
      setRates(rt)
      setReservations(rs)
      setError(null)
    } catch {
      setError('Could not load front desk data.')
    } finally {
      setLoading(false)
    }
  }, [propertyId])

  useEffect(() => {
    // State updates occur after the awaited fetch; safe data effect.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    refresh()
  }, [refresh])

  // Cancelled reservations don't count toward the active total.
  const activeCount = useMemo(
    () => reservations.filter((r) => r.status !== 'cancelled').length,
    [reservations],
  )
  const availableRooms = useMemo(() => rooms.filter((r) => r.status === 'available'), [rooms])

  async function doTransition(id, transition) {
    setError(null)
    try {
      await transitionReservation(id, transition)
      refresh()
    } catch (ex) {
      setError(ex?.response?.data?.message ?? 'Action failed.')
    }
  }

  async function changeRoomStatus(room, status) {
    await updateRoom(room.id, { room_number: room.room_number, room_type: room.room_type, status })
    refresh()
  }

  function onRoomStatusPick(room, value) {
    if (value === room.status) return
    // Putting a guest into a room means making a booking, not flipping a flag.
    if (value === 'occupied') { openReservation(room.id); return }
    changeRoomStatus(room, value)
  }

  function openReservation(roomId = null) {
    setReservationRoomId(roomId)
    setModal('reservation')
  }

  if (!propertyId)
    return <Alert variant="info">Select or create a property to use the front desk.</Alert>

  return (
    <div>
      <h1 className="h3 fw-bold mb-3">Front Desk</h1>
      {error && <Alert variant="danger" onClose={() => setError(null)} dismissible>{error}</Alert>}

      {loading ? (
        <div className="text-center py-5"><Spinner /></div>
      ) : (
        <Tabs defaultActiveKey="reservations" className="mb-3">
          {/* ---- Reservations ---- */}
          <Tab eventKey="reservations" title={`Reservations (${activeCount})`}>
            <div className="d-flex justify-content-end mb-2">
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
                  {reservations.length === 0 && (
                    <tr><td colSpan={9} className="text-center text-muted py-4">No reservations.</td></tr>
                  )}
                  {reservations.map((r) => (
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
                            onClick={() => doTransition(r.id, 'check-in')}>Check in</Button>
                        )}
                        {r.status === 'checked_in' && (
                          <Button size="sm" variant="outline-success" className="me-1"
                            onClick={() => doTransition(r.id, 'check-out')}>Check out</Button>
                        )}
                        {(r.status === 'booked' || r.status === 'checked_in') && (
                          <Button size="sm" variant="outline-danger"
                            onClick={() => doTransition(r.id, 'cancel')}>Cancel</Button>
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
                      <Form.Select size="sm" value={room.status}
                        onChange={(e) => onRoomStatusPick(room, e.target.value)}>
                        {statusOptions(room.status).map((s) => (
                          <option key={s} value={s}>
                            {s === 'occupied' && room.status === 'available' ? 'occupied → new booking' : s}
                          </option>
                        ))}
                      </Form.Select>
                    </Card.Body>
                  </Card>
                </Col>
              ))}
            </Row>
          </Tab>

          {/* ---- Rates ---- */}
          <Tab eventKey="rates" title={`Rates (${rates.length})`}>
            <div className="d-flex justify-content-end mb-2">
              <Button onClick={() => setModal({ type: 'rate' })}>Add rate</Button>
            </div>
            <Card className="shadow-sm">
              <Table responsive hover className="mb-0 align-middle">
                <thead>
                  <tr>
                    <th>Name</th><th>Applies to</th><th className="text-end">Nightly rate</th>
                    <th className="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {rates.length === 0 && (
                    <tr><td colSpan={4} className="text-center text-muted py-4">No rates yet.</td></tr>
                  )}
                  {rates.map((rt) => (
                    <tr key={rt.id}>
                      <td className="fw-semibold">{rt.name}</td>
                      <td>{rt.room ? `Room ${rt.room.room_number}` : 'All rooms'}</td>
                      <td className="text-end">{formatMoney(rt.base_rate)}</td>
                      <td className="text-end">
                        <Button size="sm" variant="outline-primary"
                          onClick={() => setModal({ type: 'rate', rate: rt })}>Edit</Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            </Card>
          </Tab>
        </Tabs>
      )}

      {modal === 'reservation' && (
        <ReservationModal rooms={rooms} propertyId={propertyId} defaultRoomId={reservationRoomId}
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
    </div>
  )
}

function ReservationModal({ rooms, propertyId, defaultRoomId, onClose, onSaved }) {
  const firstAvailable = rooms.find((r) => r.status === 'available')
  const [form, setForm] = useState({
    room_id: defaultRoomId ?? firstAvailable?.id ?? '',
    check_in: '', check_out: '',
    source: 'walk_in', discount_type: 'none', promo_rate: '', additional_beds: 0,
    guest_name: '', guest_type: 'local', nationality: '',
    contact_number: '', email: '', address: '',
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const [guestId, setGuestId] = useState(null) // set when reusing an existing guest
  const [duplicates, setDuplicates] = useState(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)

  function buildPayload(extra = {}) {
    const payload = { ...form, ...extra }
    if (payload.promo_rate === '') delete payload.promo_rate
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

  function reuseGuest(guest) {
    setGuestId(guest.id)
    setDuplicates(null)
    setForm((f) => ({ ...f, guest_name: guest.full_name }))
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
              <Form.Control type="date" value={form.check_in} onChange={set('check_in')} required />
            </Form.Group></Col>
            <Col md={3}><Form.Group className="mb-3">
              <Form.Label>Check-out</Form.Label>
              <Form.Control type="date" value={form.check_out} onChange={set('check_out')} required />
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
              <Form.Control type="number" min={0} step="0.01" value={form.promo_rate}
                onChange={set('promo_rate')} placeholder="OTA" />
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
                    <Button size="sm" variant="outline-success" onClick={() => reuseGuest(d)}>Use this guest</Button>
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
              <Form.Control value={form.guest_name}
                onChange={(e) => { setGuestId(null); set('guest_name')(e) }}
                placeholder="Optional" />
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
