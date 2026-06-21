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

// Reservations
export const listReservations = (propertyId, params = {}) =>
  client.get('/reservations', { params: withProp(params, propertyId) }).then((r) => r.data.reservations)

export const createReservation = (data, propertyId) =>
  client.post('/reservations', withProp(data, propertyId)).then((r) => r.data.reservation)

// transition: 'check-in' | 'check-out' | 'cancel'
export const transitionReservation = (id, transition) =>
  client.post(`/reservations/${id}/${transition}`).then((r) => r.data.reservation)
