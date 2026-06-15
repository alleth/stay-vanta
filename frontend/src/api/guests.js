import client from './client'

const withProp = (params, propertyId) =>
  propertyId ? { ...params, property_id: propertyId } : params

export const listGuests = (propertyId) =>
  client.get('/guests', { params: withProp({}, propertyId) }).then((r) => r.data.guests)

export const guestStats = (propertyId) =>
  client.get('/guests/stats', { params: withProp({}, propertyId) }).then((r) => r.data.stats)

export const getGuest = (id) =>
  client.get(`/guests/${id}`).then((r) => r.data.guest)

export const createGuest = (data, propertyId) =>
  client.post('/guests', withProp(data, propertyId)).then((r) => r.data.guest)

export const updateGuest = (id, data) =>
  client.patch(`/guests/${id}`, data).then((r) => r.data.guest)
