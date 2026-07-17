import client from './client'

const withProp = (params, propertyId) =>
  propertyId ? { ...params, property_id: propertyId } : params

// Menu
export const listMenu = (propertyId, params = {}) =>
  client.get('/food-menu-items', { params: withProp(params, propertyId) }).then((r) => r.data.menuItems)

export const createMenuItem = (data, propertyId) =>
  client.post('/food-menu-items', withProp(data, propertyId)).then((r) => r.data.menuItem)

export const updateMenuItem = (id, data) =>
  client.patch(`/food-menu-items/${id}`, data).then((r) => r.data.menuItem)

export const deleteMenuItem = (id) =>
  client.delete(`/food-menu-items/${id}`).then((r) => r.data)

// Orders — returns { orders, total, page, limit } for pagination.
export const listOrders = (propertyId, params = {}) =>
  client.get('/food-orders', { params: withProp(params, propertyId) }).then((r) => r.data)

export const createOrder = (data, propertyId) =>
  client.post('/food-orders', withProp(data, propertyId)).then((r) => r.data.order)

export const serveOrder = (id) =>
  client.post(`/food-orders/${id}/serve`).then((r) => r.data.order)

export const cancelOrder = (id) =>
  client.post(`/food-orders/${id}/cancel`).then((r) => r.data.order)

// Invoices
export const listInvoices = (propertyId, params = {}) =>
  client.get('/invoices', { params: withProp(params, propertyId) }).then((r) => r.data.invoices)

export const getInvoice = (id) =>
  client.get(`/invoices/${id}`).then((r) => r.data.invoice)

// data: { use_invoice?: bool, use_or?: bool } — assigns the next number from
// the property's registered booklet series onto the settled invoice.
export const settleInvoice = (id, data = {}) =>
  client.post(`/invoices/${id}/settle`, data).then((r) => r.data.invoice)
