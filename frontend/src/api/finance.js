import client from './client'

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
