import client from './client'

const withProp = (params, propertyId) =>
  propertyId ? { ...params, property_id: propertyId } : params

// Search text goes in a POST body, never the URL, so a guest's name stays out
// of server and proxy logs (G5, P6); the other filters stay query params.
function fetchGuests(propertyId, params) {
  const { q, ...rest } = params
  return q
    ? client.post('/guests/search', { q }, { params: withProp(rest, propertyId) })
    : client.get('/guests', { params: withProp(rest, propertyId) })
}

export const listGuests = (propertyId, params = {}) =>
  fetchGuests(propertyId, params).then((r) => r.data.guests)

// Paginated form — returns { guests, total, page, limit } for the Guests
// tab's table. Pass page/limit to opt into the 5-100 clamp; listGuests()
// above (no limit) keeps getting the wide unpaginated window other callers
// (Food & Orders, the Front Desk booking combobox) rely on.
export const listGuestsPage = (propertyId, params = {}) =>
  fetchGuests(propertyId, params).then((r) => r.data)

export const guestStats = (propertyId) =>
  client.get('/guests/stats', { params: withProp({}, propertyId) }).then((r) => r.data.stats)

// Look for existing guests that look like the same person (name + email/contact),
// sent in the body so they never reach a URL or a log (G5, P6).
export const matchGuests = ({ full_name, email, contact_number }, propertyId) =>
  client
    .post('/guests/match', withProp({ full_name, email, contact_number }, propertyId))
    .then((r) => r.data.duplicates)

export const getGuest = (id) =>
  client.get(`/guests/${id}`).then((r) => r.data.guest)

export const createGuest = (data, propertyId) =>
  client.post('/guests', withProp(data, propertyId)).then((r) => r.data.guest)

export const updateGuest = (id, data) =>
  client.patch(`/guests/${id}`, data).then((r) => r.data.guest)

// A guest's record history (final review G3, Managers only): registrations
// with where from, each change with before/after, renames and look-alike
// overrides with their reasons → { events, page, has_more }.
export const guestHistory = (id, page = 1) =>
  client.get(`/guests/${id}/history`, { params: { page } }).then((r) => r.data)
