import client from './client'

const withProp = (params, propertyId) =>
  propertyId ? { ...params, property_id: propertyId } : params

export const listGuests = (propertyId, params = {}) =>
  client.get('/guests', { params: withProp(params, propertyId) }).then((r) => r.data.guests)

// Paginated form — returns { guests, total, page, limit } for the Guests
// tab's table. Pass page/limit to opt into the 5-100 clamp; listGuests()
// above (no limit) keeps getting the wide unpaginated window other callers
// (Food & Orders, the Front Desk booking combobox) rely on.
export const listGuestsPage = (propertyId, params = {}) =>
  client.get('/guests', { params: withProp(params, propertyId) }).then((r) => r.data)

export const guestStats = (propertyId) =>
  client.get('/guests/stats', { params: withProp({}, propertyId) }).then((r) => r.data.stats)

// Look for existing guests that look like the same person (name + email/contact).
export const matchGuests = ({ full_name, email, contact_number }, propertyId) =>
  client
    .get('/guests/match', { params: withProp({ full_name, email, contact_number }, propertyId) })
    .then((r) => r.data.duplicates)

export const getGuest = (id) =>
  client.get(`/guests/${id}`).then((r) => r.data.guest)

export const createGuest = (data, propertyId) =>
  client.post('/guests', withProp(data, propertyId)).then((r) => r.data.guest)

export const updateGuest = (id, data) =>
  client.patch(`/guests/${id}`, data).then((r) => r.data.guest)
