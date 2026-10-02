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

// Flip a subscription active/inactive, or edit its fee. Platform Owner only.
export const updateProperty = (id, data) =>
  client.patch(`/properties/${id}`, data).then((r) => r.data.property)
