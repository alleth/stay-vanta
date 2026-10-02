// Surface the real failure instead of a blanket message: prefer the API's JSON
// error, fall back to the HTTP status, then to a network-level hint.
export function apiErrorMessage(err) {
  const res = err?.response
  if (res) return res.data?.message || `Request failed (${res.status}).`
  return 'Could not reach the server. Please try again.'
}
