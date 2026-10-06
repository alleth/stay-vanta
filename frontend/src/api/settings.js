import client from './client'

const withProp = (params, propertyId) =>
  propertyId ? { ...params, property_id: propertyId } : params

// The configuration change log (step 9, Manager): newest first, 50 a page →
// { changes, page, has_more }. Filters: entity_type, entity_id (one row's
// history), impact, event; latest=1 keeps each row's most recent match
// (impact=price&latest=1: where each current price came from).
export const configChanges = (propertyId, params = {}) =>
  client.get('/config-changes', { params: withProp(params, propertyId) }).then((r) => r.data)

// Role definitions (step 10b, Manager): the presets and their permissions,
// read-only → [{ code, name, preset, permissions }].
export const listRoles = () =>
  client.get('/roles').then((r) => r.data.roles)
