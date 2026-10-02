import client from './client'

// Operations (Manager + Front Desk Staff): rooms, today's arrivals and
// departures, POS, stock alerts and the "Needs attention" counts. `staff` and
// `activity` come back null for Front Desk Staff.
export const operationsToday = () =>
  client.get('/operations/today').then((r) => r.data.operations)

// The Staff card's full activity feed (Manager only), 25 per page →
// { activity, page, has_more }.
export const staffActivity = (page = 1) =>
  client.get('/operations/activity', { params: { page } }).then((r) => r.data)
