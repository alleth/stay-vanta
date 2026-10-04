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

export const deleteMenuItem = (id, reason) =>
  client.delete(`/food-menu-items/${id}`, { data: { reason } }).then((r) => r.data)

// Orders — returns { orders, total, page, limit } for pagination.
export const listOrders = (propertyId, params = {}) =>
  client.get('/food-orders', { params: withProp(params, propertyId) }).then((r) => r.data)

export const createOrder = (data, propertyId) =>
  client.post('/food-orders', withProp(data, propertyId)).then((r) => r.data.order)

export const serveOrder = (id) =>
  client.post(`/food-orders/${id}/serve`).then((r) => r.data.order)

// `reason` is asked for when a paid sale is cancelled; `refund` says whether
// money went back to the guest ({ returned, method }, build step 7c) and
// `refundKey` keeps a repeated request from recording it twice.
export const cancelOrder = (id, reason, refund, refundKey) =>
  client.post(`/food-orders/${id}/cancel`, {
    ...(reason ? { reason } : {}),
    ...(refund ? { refund, refund_key: refundKey } : {}),
  }).then((r) => r.data.order)

// Invoices
export const listInvoices = (propertyId, params = {}) =>
  client.get('/invoices', { params: withProp(params, propertyId) }).then((r) => r.data.invoices)

export const getInvoice = (id) =>
  client.get(`/invoices/${id}`).then((r) => r.data.invoice)

// data: { use_invoice?: bool, use_or?: bool } — assigns the next number from
// the property's registered booklet series onto the settled invoice.
export const settleInvoice = (id, data = {}) =>
  client.post(`/invoices/${id}/settle`, data).then((r) => r.data.invoice)

// Manager only (finance.invoice.reverse): adds a negative line pointing at
// the original, with the reason recorded. Open invoices only.
export const reverseInvoiceLine = (invoiceId, lineId, reason) =>
  client.post(`/invoices/${invoiceId}/lines/${lineId}/reverse`, { reason }).then((r) => r.data.invoice)

// Money returned against a settled invoice (finance.invoice.refund, a Manager;
// build step 7c). Never changes the invoice; a repeated refund_key is 409.
export const refundInvoice = (invoiceId, { amount, method, reason, refundKey }) =>
  client.post(`/invoices/${invoiceId}/refund`, { amount, method, reason, refund_key: refundKey })
    .then((r) => r.data.invoice)

// Money returned for a paid sale cancelled earlier with no refund on record.
export const refundSale = (id, { method, reason, refundKey }) =>
  client.post(`/food-orders/${id}/refund`, { method, reason, refund_key: refundKey }).then((r) => r.data.order)
