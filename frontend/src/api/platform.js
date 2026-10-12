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

// The same role-definition history for the Platform Owner (G7) → { events, page, has_more };
// `roleId` narrows it to one role (the role editor, Phase 3b).
export const platformRoleHistory = (page = 1, roleId = null) =>
  client.get('/platform/role-history', { params: roleId ? { page, role_id: roleId } : { page } }).then((r) => r.data)

// The role catalog (Phase 3b, Platform Owner): every role → [roles]; one role's
// editor data → role (with `version`); create, preview, save, review, archive,
// restore. A save refused because the role changed since its version is a 409.
export const listRoleCatalog = () =>
  client.get('/platform/roles').then((r) => r.data.roles)

export const getCatalogRole = (id) =>
  client.get(`/platform/roles/${id}`).then((r) => r.data.role)

export const createCatalogRole = (data) =>
  client.post('/platform/roles', data).then((r) => r.data.role)

export const previewCatalogRole = (id, permissions, version) =>
  client.post(`/platform/roles/${id}/preview`, { permissions, version }).then((r) => r.data.preview)

export const saveCatalogRole = (id, data) =>
  client.patch(`/platform/roles/${id}`, data).then((r) => r.data.role)

export const reviewCatalogRole = (id, permission, decision, reason) =>
  client.post(`/platform/roles/${id}/review`, { permission, decision, reason }).then((r) => r.data.role)

export const archiveCatalogRole = (id, reason) =>
  client.post(`/platform/roles/${id}/archive`, { reason }).then((r) => r.data.role)

export const restoreCatalogRole = (id, reason) =>
  client.post(`/platform/roles/${id}/restore`, { reason }).then((r) => r.data.role)
