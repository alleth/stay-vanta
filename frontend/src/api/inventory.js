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

export const deleteCategory = (id) =>
  client.delete(`/inventory-categories/${id}`).then((r) => r.data)

export const listItems = (propertyId, params = {}) =>
  client.get('/inventory-items', { params: withProp(params, propertyId) }).then((r) => r.data.items)

// Paginated + searchable form for the Inventory tab's Consumables/Reusables
// tables (mirrors listGuestsPage). Returns { items, children, total, page,
// limit } — `children` (populated only for a `top_level` consumables
// request) maps parent id -> that parent's own sub-items, since they ride
// along with their parent's page instead of needing a second request per
// expand. listItems() above (no page/limit) keeps returning the wide,
// effectively-unpaginated window other callers (Food & Orders' stock
// pickers, this module's own parent-item dropdown) rely on.
export const listItemsPage = (propertyId, params = {}) =>
  client.get('/inventory-items', { params: withProp(params, propertyId) }).then((r) => r.data)

export const createItem = (data, propertyId) =>
  client.post('/inventory-items', withProp(data, propertyId)).then((r) => r.data.item)

export const updateItem = (id, data) =>
  client.patch(`/inventory-items/${id}`, data).then((r) => r.data.item)

export const deleteItem = (id) =>
  client.delete(`/inventory-items/${id}`).then((r) => r.data)

export const listMovements = (propertyId, params = {}) =>
  client.get('/stock-movements', { params: withProp(params, propertyId) }).then((r) => r.data.movements)

// Record a stock movement (the accountability action). Returns { movement, item }.
export const recordMovement = (data, propertyId) =>
  client.post('/stock-movements', withProp(data, propertyId)).then((r) => r.data)

// Receipt booklet series (physical sales invoice / official receipt
// numbers). Paginated + searchable (by prefix) like the other Inventory
// tables: returns { series, total, page, limit }.
export const listReceiptSeries = (propertyId, params = {}) =>
  client.get('/receipt-series', { params: withProp(params, propertyId) }).then((r) => r.data)

export const createReceiptSeries = (data, propertyId) =>
  client.post('/receipt-series', withProp(data, propertyId)).then((r) => r.data.series)

export const updateReceiptSeries = (id, data) =>
  client.patch(`/receipt-series/${id}`, data).then((r) => r.data.series)

export const deleteReceiptSeries = (id) =>
  client.delete(`/receipt-series/${id}`).then((r) => r.data)
