import client from './client'

// Owner dashboard: subscription revenue + active-user counts.
export const ownerDashboard = () =>
  client.get('/reports/owner-dashboard').then((r) => r.data.dashboard)

// Admin dashboard: operational cards + collected revenue for the property.
export const adminDashboard = () =>
  client.get('/reports/admin-dashboard').then((r) => r.data.dashboard)

// Collection report: settled invoices + paid food orders in the window.
// Pass { date } for a single day (any staff role), or { month, year } for a
// whole month (owner/admin only).
export const dailyCollection = (params = {}) =>
  client.get('/reports/daily-collection', { params }).then((r) => r.data.collection)

// Seasonality: per month of the given year (defaults to current year) —
// non-cancelled reservation count (by check-in date) and collected revenue
// (settled invoices + paid food orders, same definition as adminDashboard's
// revenue buckets). Admin only.
export const monthlySummary = (year) =>
  client.get('/reports/monthly-summary', { params: year ? { year } : {} }).then((r) => r.data.report)

// Subscribers (owner view of properties, each with its admin user(s)).
export const listSubscribers = () =>
  client.get('/properties').then((r) => r.data.properties)

export const createSubscriber = (data) =>
  client.post('/properties', data).then((r) => r.data.property)

// Create the admin (hotel/resort head) for a subscriber.
export const createSubscriberAdmin = (propertyId, data) =>
  client.post('/users', { ...data, role: 'admin', property_id: propertyId }).then((r) => r.data.user)

// Flip a subscription active/inactive, or edit its fee. Owner only.
export const updateProperty = (id, data) =>
  client.patch(`/properties/${id}`, data).then((r) => r.data.property)
