import { useState } from 'react'
import { Modal, Form, Button, Alert, Spinner } from '../ui'
import { useSubmit } from '../../hooks/useSubmit'
import {
  createRoom, updateRoom, createRoomRate, updateRoomRate, createPromoRate, updatePromoRate,
  createExtraCharge, updateExtraCharge,
} from '../../api/frontdesk'
import { roomLabel, sourceLabel } from '../../utils/bookingLabels'
import PriceReasonField from '../PriceReasonField'
import { asksForReason } from '../../utils/configChanges'
import ConfigHistory from './ConfigHistory'

// The Settings dialogs for rooms, room rates, promo rates and extra charges
// (build step 9; they lived in Front Desk before). Editing a price asks why
// in the form (C2), and each dialog ends with the row's own history.

const BOOKED_KEEP_THEIRS = 'Bookings already made keep the rate they were booked at; '
  + 'new bookings, and edits that change a booking’s room or source, use the new one.'

const same = (a, b) => String(a ?? '') === String(b ?? '')

// The reason a save sends, when one was typed (the field shows only when a
// price changed, or the API asked for one).
const withReason = (payload, reason) => (reason.trim() ? { ...payload, reason: reason.trim() } : payload)

function Footer({ onClose, busy, editing }) {
  return (
    <Modal.Footer>
      <Button variant="secondary" onClick={onClose}>Cancel</Button>
      <Button type="submit" disabled={busy}>{busy ? <Spinner size="sm" /> : editing ? 'Save' : 'Create'}</Button>
    </Modal.Footer>
  )
}

export function RoomModal({ propertyId, room, canSeeHistory, onClose, onSaved }) {
  const editing = Boolean(room)
  const [form, setForm] = useState({
    room_number: room?.room_number ?? '',
    room_type: room?.room_type ?? '',
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const { run, busy, err } = useSubmit(async () => {
    // A room's status is the desk's (occupancy); Settings sends it unchanged.
    if (editing) await updateRoom(room.id, { ...form, status: room.status })
    else await createRoom({ ...form, status: 'available' }, propertyId)
    onSaved()
  })
  return (
    <Modal show onHide={onClose} centered>
      <Form onSubmit={run}>
        <Modal.Header closeButton><Modal.Title>{editing ? `Room ${room.room_number}` : 'Add room'}</Modal.Title></Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          <Form.Group className="mb-4">
            <Form.Label>Room number</Form.Label>
            <Form.Control value={form.room_number} onChange={set('room_number')} required autoFocus />
          </Form.Group>
          <Form.Group>
            <Form.Label>Room type</Form.Label>
            <Form.Control value={form.room_type} onChange={set('room_type')} placeholder="e.g. Deluxe" />
          </Form.Group>
          {editing && canSeeHistory && <ConfigHistory entityType="room" entityId={room.id} propertyId={propertyId} />}
        </Modal.Body>
        <Footer onClose={onClose} busy={busy} editing={editing} />
      </Form>
    </Modal>
  )
}

export function RateModal({ rooms, propertyId, rate, canSeeHistory, onClose, onSaved }) {
  const editing = Boolean(rate)
  const [form, setForm] = useState({
    description: rate?.description ?? '',
    base_rate: rate?.base_rate ?? '',
    room_id: rate?.room_id ?? '',
  })
  const [reason, setReason] = useState('')
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const priceChanged = editing && (Number(form.base_rate) !== Number(rate.base_rate) || !same(form.room_id, rate.room_id))
  const { run, busy, err } = useSubmit(async () => {
    const payload = withReason({
      description: form.description,
      base_rate: form.base_rate,
      room_id: form.room_id || null,
    }, reason)
    if (editing) await updateRoomRate(rate.id, payload)
    else await createRoomRate(payload, propertyId)
    onSaved()
  })
  const needsReason = priceChanged || (editing && asksForReason(err))
  const roomName = (id) => rooms.find((r) => r.id === Number(id))?.room_number ?? `#${id}`
  return (
    <Modal show onHide={onClose} centered>
      <Form onSubmit={run}>
        <Modal.Header closeButton><Modal.Title>{editing ? 'Edit rate' : 'Add rate'}</Modal.Title></Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          <Form.Group className="mb-4">
            <Form.Label>Amenities &amp; bed type</Form.Label>
            <Form.Control as="textarea" rows={2} maxLength={255} autoFocus
              value={form.description} onChange={set('description')}
              placeholder="What the guest gets — e.g. Queen bed, A/C, hot shower, free breakfast for 2" />
          </Form.Group>
          <Form.Group className="mb-4">
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
          <PriceReasonField show={needsReason} value={reason} onChange={setReason} note={BOOKED_KEEP_THEIRS} />
          {editing && canSeeHistory && (
            <ConfigHistory entityType="room_rate" entityId={rate.id} propertyId={propertyId} roomName={roomName} />
          )}
        </Modal.Body>
        <Footer onClose={onClose} busy={busy} editing={editing} />
      </Form>
    </Modal>
  )
}

export function PromoRateModal({ rooms, bookingSources, propertyId, promoRate, canSeeHistory, onClose, onSaved }) {
  const editing = Boolean(promoRate)
  // Booking source is a free-text field, not a fixed picker: typing an
  // existing source's name reuses it, typing a new one creates it — the
  // backend resolves/creates by name (BookingSourcesTable::slugFor), so
  // there's no separate "add a source first" step.
  const originalSource = promoRate ? sourceLabel(bookingSources, promoRate.source) : ''
  const [form, setForm] = useState({
    sourceName: originalSource,
    multiplier: promoRate?.multiplier ?? '',
    room_id: promoRate?.room_id ?? '',
  })
  const [reason, setReason] = useState('')
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const priceChanged = editing && (
    Number(form.multiplier) !== Number(promoRate.multiplier)
    || !same(form.room_id, promoRate.room_id)
    || form.sourceName.trim().toLowerCase() !== originalSource.toLowerCase()
  )
  const { run, busy, err } = useSubmit(async () => {
    const payload = withReason({
      source_name: form.sourceName,
      multiplier: form.multiplier,
      room_id: form.room_id || null,
    }, reason)
    if (editing) await updatePromoRate(promoRate.id, payload)
    else await createPromoRate(payload, propertyId)
    onSaved()
  })
  const needsReason = priceChanged || (editing && asksForReason(err))
  const roomName = (id) => rooms.find((r) => r.id === Number(id))?.room_number ?? `#${id}`
  return (
    <Modal show onHide={onClose} centered>
      <Form onSubmit={run}>
        <Modal.Header closeButton>
          <Modal.Title>{editing ? 'Edit promo rate' : 'Add promo rate'}</Modal.Title>
        </Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          <Form.Group className="mb-4">
            <Form.Label>Booking source</Form.Label>
            <Form.Control list="booking-source-suggestions" value={form.sourceName} onChange={set('sourceName')}
              placeholder="e.g. Cocotel, Agoda, Booking.com" required autoFocus />
            <datalist id="booking-source-suggestions">
              {bookingSources.map((bs) => <option key={bs.id} value={bs.name} />)}
            </datalist>
            <Form.Text muted>
              Pick an existing source or type a new one — new sources are added automatically and
              immediately show up on the New Reservation form&apos;s Source dropdown.
            </Form.Text>
          </Form.Group>
          <Form.Group className="mb-4">
            <Form.Label>Rate multiplier</Form.Label>
            <Form.Control type="number" min={1} step="0.1" value={form.multiplier}
              onChange={set('multiplier')} required placeholder="e.g. 2 = ×2 the room's original rate" />
            <Form.Text muted>
              The promo price is the room&apos;s original rate × this — e.g. ×2 makes a ₱1,500 room ₱3,000.
            </Form.Text>
          </Form.Group>
          <Form.Group>
            <Form.Label>Applies to</Form.Label>
            <Form.Select value={form.room_id} onChange={set('room_id')}>
              <option value="">All rooms (property-wide)</option>
              {rooms.map((r) => <option key={r.id} value={r.id}>{roomLabel(r)}</option>)}
            </Form.Select>
          </Form.Group>
          <PriceReasonField show={needsReason} value={reason} onChange={setReason} note={BOOKED_KEEP_THEIRS} />
          {editing && canSeeHistory && (
            <ConfigHistory entityType="promo_rate" entityId={promoRate.id} propertyId={propertyId} roomName={roomName} />
          )}
        </Modal.Body>
        <Footer onClose={onClose} busy={busy} editing={editing} />
      </Form>
    </Modal>
  )
}

export function ChargeModal({ charge, propertyId, canSeeHistory, onClose, onSaved }) {
  const editing = Boolean(charge)
  const builtIn = Boolean(charge?.code) // early check-in: name & code are fixed
  const [form, setForm] = useState({
    name: charge?.name ?? '',
    amount: charge?.amount ?? '',
    is_active: charge?.is_active ?? true,
  })
  const [reason, setReason] = useState('')
  const priceChanged = editing && Number(form.amount || 0) !== Number(charge.amount)
  const { run, busy, err } = useSubmit(async () => {
    const payload = withReason(
      { name: form.name, amount: form.amount === '' ? 0 : form.amount, is_active: form.is_active },
      reason,
    )
    if (editing) await updateExtraCharge(charge.id, payload)
    else await createExtraCharge(payload, propertyId)
    onSaved()
  })
  const needsReason = priceChanged || (editing && asksForReason(err))

  return (
    <Modal show onHide={onClose} centered>
      <Form onSubmit={run}>
        <Modal.Header closeButton>
          <Modal.Title>{editing ? `Edit ${builtIn ? 'fee' : 'charge'}` : 'Add charge'}</Modal.Title>
        </Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          <Form.Group className="mb-4">
            <Form.Label>Name</Form.Label>
            <Form.Control value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })}
              required autoFocus={!builtIn} disabled={builtIn}
              placeholder="e.g. Late check-out, Extra towel" />
            {builtIn && <Form.Text muted>This is a built-in charge; its name is fixed.</Form.Text>}
          </Form.Group>
          <div className="grid grid-cols-2 gap-x-6">
            <Form.Group className="mb-4">
              <Form.Label>Amount</Form.Label>
              <Form.Control type="number" min={0} step="0.01" value={form.amount}
                onChange={(e) => setForm({ ...form, amount: e.target.value })} required autoFocus={builtIn} />
            </Form.Group>
            <Form.Group className="mb-4">
              <Form.Label>Status</Form.Label>
              <Form.Select value={form.is_active ? '1' : '0'}
                onChange={(e) => setForm({ ...form, is_active: e.target.value === '1' })}>
                <option value="1">Active</option>
                <option value="0">Inactive</option>
              </Form.Select>
            </Form.Group>
          </div>
          <PriceReasonField show={needsReason} value={reason} onChange={setReason}
            note={builtIn
              ? 'Applies to early check-ins from now on.'
              : 'Bookings that already picked this charge keep the amount they picked.'} />
          {editing && canSeeHistory && <ConfigHistory entityType="extra_charge" entityId={charge.id} propertyId={propertyId} />}
        </Modal.Body>
        <Footer onClose={onClose} busy={busy} editing={editing} />
      </Form>
    </Modal>
  )
}
