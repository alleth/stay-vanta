import { useState } from 'react'

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

function extractError(ex) {
  const data = ex?.response?.data
  const fieldErrors = data?.errors && Object.values(data.errors)[0]
  if (fieldErrors && typeof fieldErrors === 'object') {
    return Object.values(fieldErrors)[0]
  }
  return data?.message ?? 'Save failed. Check the fields and try again.'
}
