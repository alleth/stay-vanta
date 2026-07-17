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

// Promo rates (admin-configured OTA nightly prices per booking source)
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

export const createReservation = (data, propertyId) =>
  client.post('/reservations', withProp(data, propertyId)).then((r) => r.data.reservation)

// transition: 'check-in' | 'check-out' | 'cancel'
// `data` carries flags like { early_check_in: true } for the check-in transition.
export const transitionReservation = (id, transition, data = {}) =>
  client.post(`/reservations/${id}/${transition}`, data).then((r) => r.data.reservation)

// Front Desk operational flag — independent of the booking lifecycle and of
// the invoice's own settled status.
export const setReservationPayment = (id, paymentStatus) =>
  client.post(`/reservations/${id}/payment`, { payment_status: paymentStatus }).then((r) => r.data.reservation)

// Extra charges (admin-configurable surcharges, e.g. early check-in).
export const listExtraCharges = (propertyId) =>
  client.get('/extra-charges', { params: withProp({}, propertyId) }).then((r) => r.data.extraCharges)

export const createExtraCharge = (data, propertyId) =>
  client.post('/extra-charges', withProp(data, propertyId)).then((r) => r.data.extraCharge)

export const updateExtraCharge = (id, data) =>
  client.patch(`/extra-charges/${id}`, data).then((r) => r.data.extraCharge)

export const deleteExtraCharge = (id) =>
  client.delete(`/extra-charges/${id}`).then((r) => r.data)
