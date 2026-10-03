import { useState } from 'react'
import { describeError } from '../utils/apiError'

/**
 * Form-submit helper shared by the modal forms. Wraps an async action with
 * busy/error state and extracts a readable message from CakePHP's validation
 * error shape ({ errors: { field: { rule: message } } }) or a plain message.
 */
export function useSubmit(fn) {
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)

  async function run(e) {
    e?.preventDefault()
    setBusy(true)
    setErr(null)
    try {
      await fn()
    } catch (ex) {
      setErr(extractError(ex))
      setBusy(false)
    }
  }

  return { run, busy, err, setErr }
}

// The first field validation error, else the API's message, with the
// request's reference; <Alert> renders both (utils/apiError.js).
function extractError(ex) {
  return describeError(ex, 'Save failed. Check the fields and try again.')
}
