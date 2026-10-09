import { useEffect, useState } from 'react'
import { Alert, Button, Spinner } from './ui'
import { Skeleton } from './Skeleton'
import { describeError } from '../utils/apiError'
import {
  ACCESS_WARNINGS, accessActor, accessDetail, accessLabel, deviceName,
} from '../utils/accessEvents'

const fmtWhen = (s) => (s ? new Date(s).toLocaleString() : '—')

/**
 * A person's access history (build step 10), newest first: sign-ins and
 * their devices, failed attempts, and account changes with who made them and
 * why. `load(page)` reads it: your own (`mySignIns`) or a staff member's
 * (`staffAccessHistory`).
 */
export default function AccessHistory({ load }) {
  const [pages, setPages] = useState([])
  const [hasMore, setHasMore] = useState(false)
  const [busy, setBusy] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    let active = true
    load(1)
      .then((r) => { if (active) { setPages([r.events]); setHasMore(r.has_more) } })
      .catch((ex) => { if (active) setError(describeError(ex, 'Could not load the history.')) })
      .finally(() => { if (active) setBusy(false) })
    return () => { active = false }
    // `load` is an inline function; the history loads once per dialog.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function more() {
    setBusy(true)
    try {
      const r = await load(pages.length + 1)
      setPages((p) => [...p, r.events])
      setHasMore(r.has_more)
    } catch (ex) {
      setError(describeError(ex, 'Could not load the history.'))
    } finally {
      setBusy(false)
    }
  }

  const events = pages.flat()
  if (error) return <Alert variant="danger">{error}</Alert>
  if (busy && events.length === 0) {
    return (
      <div className="space-y-2">
        <Skeleton className="h-4 w-1/2" />
        <Skeleton className="h-4 w-2/3" />
        <Skeleton className="h-4 w-1/3" />
      </div>
    )
  }
  if (events.length === 0) return <p className="mb-0 text-sm text-muted">Nothing recorded yet.</p>

  return (
    <div>
      <ol className="m-0 list-none space-y-2 p-0">
        {events.map((e) => {
          const warn = ACCESS_WARNINGS.has(e.event)
          const detail = accessDetail(e)
          const device = deviceName(e.user_agent)
          return (
            <li key={e.id}
              className={`border-l-2 pl-3 text-sm ${warn ? 'border-amber-500 dark:border-amber-400' : 'border-line'}`}>
              <div className={`font-semibold ${warn ? 'text-amber-700 dark:text-amber-300' : ''}`}>{accessLabel(e.event)}</div>
              <div className="text-xs text-muted">{accessActor(e)} · {fmtWhen(e.at)}</div>
              {e.reason && <div className="text-xs">Reason: {e.reason}</div>}
              {detail && <div className="text-xs text-muted">{detail}</div>}
              {(device || e.client_address) && (
                <div className="text-xs text-muted">
                  {[device, e.client_address && `reported address ${e.client_address}`].filter(Boolean).join(' · ')}
                </div>
              )}
              {/* Kept 12 months, then cleared by the retention routine (G5);
                  the event itself stays. */}
              {e.redacted_at && (
                <div className="text-xs text-muted">Device details cleared after 12 months</div>
              )}
            </li>
          )
        })}
      </ol>
      {hasMore && (
        <Button size="sm" variant="link" className="mt-2 px-0" disabled={busy} onClick={more}>
          {busy ? <Spinner size="sm" /> : 'Show older'}
        </Button>
      )}
      <p className="mt-3 mb-0 text-xs text-muted">
        Devices and addresses are as the browser and network reported them. Sign-ins from before this
        history began weren&apos;t recorded.
      </p>
    </div>
  )
}
