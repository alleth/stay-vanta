import client from './client'

// Platform Owner: subscription revenue + subscriber counts.
export const platformDashboard = () =>
  client.get('/platform/dashboard').then((r) => r.data.dashboard)

// Subscribers (the Platform Owner's view of properties, each with its Manager(s)).
export const listSubscribers = () =>
  client.get('/properties').then((r) => r.data.properties)

export const createSubscriber = (data) =>
  client.post('/properties', data).then((r) => r.data.property)

// Create the Manager for a subscriber (stored role value: admin).
export const createSubscriberManager = (propertyId, data) =>
  client.post('/users', { ...data, role: 'admin', property_id: propertyId }).then((r) => r.data.user)

// Changes to property records (fee, subscription, name), newest first →
// { changes, page, has_more }. Platform Owner only.
export const propertyChanges = (propertyId, page = 1) =>
  client.get('/platform/property-changes', { params: { property_id: propertyId, page } }).then((r) => r.data)

// Flip a subscription active/inactive, or edit its fee (a fee change needs a
// reason, step 9). Platform Owner only.
export const updateProperty = (id, data) =>
  client.patch(`/properties/${id}`, data).then((r) => r.data.property)

// Support access (step 10b, A6): read one property's data for 60 minutes,
// read-only, with a reason; every request is recorded and the Manager sees
// it. → { session }
export const startSupport = (propertyId, reason) =>
  client.post('/platform/support-sessions', { property_id: propertyId, reason }).then((r) => r.data.session)

// End your own support session early.
export const endOwnSupport = (id) =>
  client.post(`/platform/support-sessions/${id}/end`).then((r) => r.data.session)

// Subscription enforcement (G6): the phase, the emergency ceiling, the phase
// in force, the preview (properties per stage, per phase) and the history.
// → { phase, ceiling, effective, phases, preview, history }
export const getEnforcement = () =>
  client.get('/platform/enforcement').then((r) => r.data.enforcement)

// One phase forward or any phase back, with a reason. Answers like getEnforcement().
export const changeEnforcement = (phase, reason) =>
  client.post('/platform/enforcement', { phase, reason }).then((r) => r.data.enforcement)
