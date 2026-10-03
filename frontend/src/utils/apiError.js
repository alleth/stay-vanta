// Surface the real failure instead of a blanket message: prefer the API's JSON
// error, fall back to the HTTP status, then to a network-level hint.
export function apiErrorMessage(err) {
  const res = err?.response
  if (res) return res.data?.message || `Request failed (${res.status}).`
  return 'Could not reach the server. Please try again.'
}

// One description of a failed API call for every error alert: a readable
// message plus the request's X-Request-Id, so users can quote a reference
// support finds in the server logs (docs/EVENTS.md, "Correlation ids").
// <Alert> renders it: the message, then "Reference: 8c2a236e" with a copy button.
const TAG = Symbol('apiError')

/**
 * Message and request id of a failed call. The message is CakePHP's first
 * field validation error, else the API's message, else `fallback`; with no
 * response at all, the network hint.
 */
export function describeError(ex, fallback = 'Something went wrong.') {
  const res = ex?.response
  const fieldErrors = res?.data?.errors && Object.values(res.data.errors)[0]
  const fieldMessage = fieldErrors && typeof fieldErrors === 'object' ? Object.values(fieldErrors)[0] : null
  return {
    [TAG]: true,
    message: res ? (fieldMessage ?? res.data?.message ?? fallback) : apiErrorMessage(ex),
    requestId: ex?.requestId ?? res?.headers?.['x-request-id'] ?? null,
  }
}

/** Whether a value came from describeError(). */
export function isDescribedError(value) {
  return Boolean(value && typeof value === 'object' && value[TAG])
}
