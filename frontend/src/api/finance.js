import client from './client'

const withProp = (params, propertyId) =>
  propertyId ? { ...params, property_id: propertyId } : params

// Collections: settled invoices + paid POS sales in the window, plus what's
// outstanding right now. Pass { date } for one day (any staff role), or
// { month, year } / { from, to } (Manager only).
export const collections = (params = {}) =>
  client.get('/finance/collections', { params }).then((r) => r.data.collection)

// Collected per period (week, month, year to date, all time) + outstanding.
// Manager only.
export const financeSummary = () =>
  client.get('/finance/summary').then((r) => r.data.summary)

// Seasonality: per month of the year — non-cancelled reservations by check-in
// date and collected revenue. Manager only.
export const seasonality = (year) =>
  client.get('/finance/seasonality', { params: year ? { year } : {} }).then((r) => r.data.report)

// Receipt booklet series (physical sales invoice / official receipt numbers),
// in Finance since step 9. Paginated + searchable by prefix:
// { series, total, page, limit }.
export const listReceiptSeries = (propertyId, params = {}) =>
  client.get('/receipt-series', { params: withProp(params, propertyId) }).then((r) => r.data)

export const createReceiptSeries = (data, propertyId) =>
  client.post('/receipt-series', withProp(data, propertyId)).then((r) => r.data.series)

export const updateReceiptSeries = (id, data) =>
  client.patch(`/receipt-series/${id}`, data).then((r) => r.data.series)

// Only an unused series can be deleted, with a reason (step 9).
export const deleteReceiptSeries = (id, reason) =>
  client.delete(`/receipt-series/${id}`, { data: { reason } }).then((r) => r.data)
