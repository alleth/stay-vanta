import client from './client'

const withProp = (params, propertyId) =>
  propertyId ? { ...params, property_id: propertyId } : params

// Rooms
export const listRooms = (propertyId, params = {}) =>
  client.get('/rooms', { params: withProp(params, propertyId) }).then((r) => r.data.rooms)

export const createRoom = (data, propertyId) =>
  client.post('/rooms', withProp(data, propertyId)).then((r) => r.data.room)

export const updateRoom = (id, data) =>
  client.patch(`/rooms/${id}`, data).then((r) => r.data.room)

export const deleteRoom = (id) =>
  client.delete(`/rooms/${id}`).then((r) => r.data)

// Room rates
export const listRoomRates = (propertyId) =>
  client.get('/room-rates', { params: withProp({}, propertyId) }).then((r) => r.data.roomRates)

export const createRoomRate = (data, propertyId) =>
  client.post('/room-rates', withProp(data, propertyId)).then((r) => r.data.roomRate)

export const updateRoomRate = (id, data) =>
  client.patch(`/room-rates/${id}`, data).then((r) => r.data.roomRate)

// Booking sources — read-only from the frontend: the list a property books
// through (Cocotel, Agoda, ...) is created implicitly by typing a new name
// into "Add promo rate" (see createPromoRate/updatePromoRate below), not
// managed separately.
export const listBookingSources = (propertyId) =>
  client.get('/booking-sources', { params: withProp({}, propertyId) }).then((r) => r.data.bookingSources)

// Promo rates (admin-configured OTA nightly prices per booking source).
// `source_name` resolves-or-creates the booking source by name server-side.
export const listPromoRates = (propertyId) =>
  client.get('/promo-rates', { params: withProp({}, propertyId) }).then((r) => r.data.promoRates)

export const createPromoRate = (data, propertyId) =>
  client.post('/promo-rates', withProp(data, propertyId)).then((r) => r.data.promoRate)

export const updatePromoRate = (id, data) =>
  client.patch(`/promo-rates/${id}`, data).then((r) => r.data.promoRate)

export const deletePromoRate = (id) =>
  client.delete(`/promo-rates/${id}`).then((r) => r.data)

// Reservations
export const listReservations = (propertyId, params = {}) =>
  client.get('/reservations', { params: withProp(params, propertyId) }).then((r) => r.data.reservations)

// The Reservations table's paginated view → {reservations, total, page, limit}.
// `since` (YYYY-MM-DD) keeps finished stays from before it out; omit for all.
export const pageReservations = (propertyId, params = {}) =>
  client.get('/reservations', { params: withProp(params, propertyId) }).then((r) => r.data)

// The summary cards → {booked, checked_out_today, cancelled_today, unpaid, open_invoices}.
export const reservationStats = (propertyId) =>
  client.get('/reservations/stats', { params: withProp({}, propertyId) }).then((r) => r.data)

export const createReservation = (data, propertyId) =>
  client.post('/reservations', withProp(data, propertyId)).then((r) => r.data.reservation)

// Fix a mistake made at booking (room, dates, source, discount) while the
// guest hasn't checked in yet. Blocked server-side once checked in, or once
// an advance-booking downpayment has been collected.
export const updateReservation = (id, data) =>
  client.patch(`/reservations/${id}`, data).then((r) => r.data.reservation)

// Admin only, and refused once anything has been transacted against the
// reservation (a downpayment, charges on the invoice, food orders during the stay).
export const deleteReservation = (id) =>
  client.delete(`/reservations/${id}`).then((r) => r.data)

// transition: 'check-in' | 'check-out' | 'cancel'
// `data` carries flags like { early_check_in: true } for the check-in transition.
export const transitionReservation = (id, transition, data = {}) =>
  client.post(`/reservations/${id}/${transition}`, data).then((r) => r.data.reservation)

// Post room charge: bills the stay onto the guest's open invoice (Not billed →
// Billed; Settled once that invoice is settled). The API refuses with a reason
// when nothing can be posted (no guest, no rate, cancelled).
export const postRoomCharge = (id) =>
  client.post(`/reservations/${id}/post-room-charge`).then((r) => r.data.reservation)

// Extra charges (admin-configurable surcharges, e.g. early check-in).
export const listExtraCharges = (propertyId) =>
  client.get('/extra-charges', { params: withProp({}, propertyId) }).then((r) => r.data.extraCharges)

export const createExtraCharge = (data, propertyId) =>
  client.post('/extra-charges', withProp(data, propertyId)).then((r) => r.data.extraCharge)

export const updateExtraCharge = (id, data) =>
  client.patch(`/extra-charges/${id}`, data).then((r) => r.data.extraCharge)

export const deleteExtraCharge = (id) =>
  client.delete(`/extra-charges/${id}`).then((r) => r.data)
