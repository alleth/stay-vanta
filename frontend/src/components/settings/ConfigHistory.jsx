import { useEffect, useState } from 'react'
import { Alert, Button, Spinner } from '../ui'
import { Skeleton } from '../Skeleton'
import { configChanges } from '../../api/settings'
import { describeError } from '../../utils/apiError'
import ConfigChangeList from './ConfigChangeList'

/**
 * One configuration row's history (build step 9), shown in its edit dialog:
 * every recorded change, newest first. A Manager's view only: render it when
 * `can(P.SETTINGS_CHANGE_LOG_VIEW)`. Pass `load(page)` to read another log
 * (the Platform Owner's property records); by default it reads the
 * property's change log for `entityType` + `entityId`.
 */
export default function ConfigHistory({ entityType, entityId, propertyId, load, roomName }) {
  const [pages, setPages] = useState([])
  const [hasMore, setHasMore] = useState(false)
  const [busy, setBusy] = useState(true)
  const [error, setError] = useState(null)

  // The page reader: the given one, or this row's changes in the change log.
  const read = (page) => (load
    ? load(page)
    : configChanges(propertyId, { entity_type: entityType, entity_id: entityId, page }))

  useEffect(() => {
    let active = true
    read(1)
      .then((r) => {
        if (!active) return
        setPages([r.changes])
        setHasMore(r.has_more)
      })
      .catch((ex) => { if (active) setError(describeError(ex, 'Could not load the history.')) })
      .finally(() => { if (active) setBusy(false) })
    return () => { active = false }
    // The first page, once per row; "Show older" reads the rest. `load` is
    // usually an inline function, so it isn't a dependency.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [entityType, entityId, propertyId])

  async function more() {
    setBusy(true)
    try {
      const r = await read(pages.length + 1)
      setPages((p) => [...p, r.changes])
      setHasMore(r.has_more)
    } catch (ex) {
      setError(describeError(ex, 'Could not load the history.'))
    } finally {
      setBusy(false)
    }
  }

  const changes = pages.flat()
  return (
    <section className="mt-6 border-t border-line pt-4">
      <h3 className="mb-3 text-sm font-semibold uppercase tracking-[0.04em] text-muted">History</h3>
      {error && <Alert variant="danger">{error}</Alert>}
      {busy && changes.length === 0 && !error && (
        <div className="space-y-2">
          <Skeleton className="h-4 w-1/2" />
          <Skeleton className="h-4 w-2/3" />
        </div>
      )}
      {!busy && !error && changes.length === 0 && <p className="mb-0 text-sm text-muted">Nothing recorded yet.</p>}
      {changes.length > 0 && <ConfigChangeList changes={changes} roomName={roomName} showSubject={false} />}
      {hasMore && (
        <Button size="sm" variant="link" className="mt-2 px-0" disabled={busy} onClick={more}>
          {busy ? <Spinner size="sm" /> : 'Show older'}
        </Button>
      )}
    </section>
  )
}
