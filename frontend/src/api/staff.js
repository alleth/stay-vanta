import client from './client'

const withProp = (params, propertyId) =>
  propertyId ? { ...params, property_id: propertyId } : params

export const listStaff = (propertyId) =>
  client.get('/users', { params: withProp({}, propertyId) }).then((r) => r.data.users)

export const createStaff = (data, propertyId) =>
  client.post('/users', withProp(data, propertyId)).then((r) => r.data.user)

export const updateStaff = (id, data) =>
  client.patch(`/users/${id}`, data).then((r) => r.data.user)

// Someone else's password needs { reason }; your own needs { current_password }
// (step 10). Either way the person's session ends.
export const resetStaffPassword = (id, password, extra = {}) =>
  client.post(`/users/${id}/reset-password`, { password, ...extra }).then((r) => r.data)

// A staff member's access history (Manager, step 10): same shape as mySignIns.
export const staffAccessHistory = (id, page = 1) =>
  client.get(`/users/${id}/access-history`, { params: { page } }).then((r) => r.data)

// Support access at this property (step 10b, Manager): sessions newest first
// → { sessions, page, has_more }; one session with its requests →
// { session, requests }; end an open one (reason optional).
export const supportSessions = (page = 1) =>
  client.get('/support-sessions', { params: { page } }).then((r) => r.data)

export const supportSession = (id) =>
  client.get(`/support-sessions/${id}`).then((r) => r.data)

export const endSupportSession = (id, reason) =>
  client.post(`/support-sessions/${id}/end`, reason ? { reason } : {}).then((r) => r.data.session)
