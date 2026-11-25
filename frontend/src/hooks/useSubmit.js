import { useState } from 'react'
import { describeError } from '../utils/apiError'

/**
 * Form-submit helper shared by the modal forms. Wraps an async action with
 * busy/error state and extracts a readable message from CakePHP's validation
 * error shape ({ errors: { field: { rule: message } } }) or a plain message.
 *
 * `busy` goes back to false when the action ends, success or failure. A modal
 * that stays mounted while hidden (ReasonModal, the CSV export) is reused, so
 * leaving it busy after a success opened it stuck the next time: spinner on
 * Confirm, Back disabled, Escape and ✕ ignored (UI-016 bug B1).
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
    } finally {
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
