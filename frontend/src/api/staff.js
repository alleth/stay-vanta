import client from './client'

const withProp = (params, propertyId) =>
  propertyId ? { ...params, property_id: propertyId } : params

export const listStaff = (propertyId) =>
  client.get('/users', { params: withProp({}, propertyId) }).then((r) => r.data.users)

export const createStaff = (data, propertyId) =>
  client.post('/users', withProp(data, propertyId)).then((r) => r.data.user)

export const updateStaff = (id, data) =>
  client.patch(`/users/${id}`, data).then((r) => r.data.user)

export const resetStaffPassword = (id, password) =>
  client.post(`/users/${id}/reset-password`, { password }).then((r) => r.data)
