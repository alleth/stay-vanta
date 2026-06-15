import client from './client'

// Inventory module API. `propertyId` is appended for owner accounts (staff are
// scoped server-side and can omit it, but passing it is harmless).
const withProp = (params, propertyId) =>
  propertyId ? { ...params, property_id: propertyId } : params

export const listProperties = () =>
  client.get('/properties').then((r) => r.data.properties)

export const createProperty = (data) =>
  client.post('/properties', data).then((r) => r.data.property)

export const listCategories = (propertyId) =>
  client.get('/inventory-categories', { params: withProp({}, propertyId) }).then((r) => r.data.categories)

export const createCategory = (data, propertyId) =>
  client.post('/inventory-categories', withProp(data, propertyId)).then((r) => r.data.category)

export const listItems = (propertyId, params = {}) =>
  client.get('/inventory-items', { params: withProp(params, propertyId) }).then((r) => r.data.items)

export const createItem = (data, propertyId) =>
  client.post('/inventory-items', withProp(data, propertyId)).then((r) => r.data.item)

export const listMovements = (propertyId, params = {}) =>
  client.get('/stock-movements', { params: withProp(params, propertyId) }).then((r) => r.data.movements)

// Record a stock movement (the accountability action). Returns { movement, item }.
export const recordMovement = (data, propertyId) =>
  client.post('/stock-movements', withProp(data, propertyId)).then((r) => r.data)
