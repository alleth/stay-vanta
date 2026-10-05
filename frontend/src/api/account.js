import client from './client'

// Your own account (step 10): your access history — sign-ins and their
// devices, failed attempts on your account, and what others did to it —
// newest first, 25 a page → { events, page, has_more }. Needs no permission.
export const mySignIns = (page = 1) =>
  client.get('/auth/sign-ins', { params: { page } }).then((r) => r.data)
