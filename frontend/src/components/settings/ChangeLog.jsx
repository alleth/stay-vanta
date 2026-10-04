import { useCallback, useEffect, useState } from 'react'
import { Alert, Button, Card, Form, Spinner } from '../ui'
import { SkeletonTable } from '../Skeleton'
import { configChanges } from '../../api/settings'
import { describeError } from '../../utils/apiError'
import { ENTITY_LABELS, IMPACTS } from '../../utils/configChanges'
import ConfigChangeList from './ConfigChangeList'

// Settings → Change log (build step 9): every configuration change at the
// property, newest first: the authoritative record of who changed which
// setting, when, why, and what it was before and after. Operations → Activity
// shows only the price changes and deletions; everything is here.

// Property records are the Platform Owner's log, not a Manager's.
const ENTITY_OPTIONS = Object.entries(ENTITY_LABELS).filter(([type]) => type !== 'property')

export default function ChangeLog({ propertyId, roomName }) {
  const [filters, setFilters] = useState({ entity_type: '', impact: '' })
  const [pages, setPages] = useState([])
  const [hasMore, setHasMore] = useState(false)
  const [busy, setBusy] = useState(true)
  const [error, setError] = useState(null)

  const params = useCallback((page) => {
    const p = { page }
    if (filters.entity_type) p.entity_type = filters.entity_type
    if (filters.impact) p.impact = filters.impact
    return p
  }, [filters])

  useEffect(() => {
    let active = true
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setBusy(true)
    configChanges(propertyId, params(1))
      .then((r) => {
        if (!active) return
        setPages([r.changes])
        setHasMore(r.has_more)
        setError(null)
      })
      .catch((ex) => { if (active) setError(describeError(ex, 'Could not load the change log.')) })
      .finally(() => { if (active) setBusy(false) })
    return () => { active = false }
  }, [propertyId, params])

  async function more() {
    setBusy(true)
    try {
      const r = await configChanges(propertyId, params(pages.length + 1))
      setPages((p) => [...p, r.changes])
      setHasMore(r.has_more)
    } catch (ex) {
      setError(describeError(ex, 'Could not load the change log.'))
    } finally {
      setBusy(false)
    }
  }

  const set = (k) => (e) => setFilters({ ...filters, [k]: e.target.value })
  const changes = pages.flat()

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <Form.Select size="sm" value={filters.entity_type} onChange={set('entity_type')} style={{ maxWidth: 220 }}
          aria-label="What changed">
          <option value="">Everything</option>
          {ENTITY_OPTIONS.map(([type, label]) => <option key={type} value={type}>{label}s</option>)}
        </Form.Select>
        <Form.Select size="sm" value={filters.impact} onChange={set('impact')} style={{ maxWidth: 200 }}
          aria-label="Impact">
          <option value="">Any impact</option>
          {Object.entries(IMPACTS).map(([key, i]) => <option key={key} value={key}>{i.label}</option>)}
        </Form.Select>
      </div>
      {error && <Alert variant="danger">{error}</Alert>}
      {busy && changes.length === 0 && !error && <SkeletonTable rows={5} />}
      {!busy && !error && changes.length === 0 && (
        <p className="text-muted">No changes match.</p>
      )}
      {changes.length > 0 && (
        <Card>
          <Card.Body>
            <ConfigChangeList changes={changes} roomName={roomName} />
            {hasMore && (
              <Button size="sm" variant="outline-secondary" className="mt-4" disabled={busy} onClick={more}>
                {busy ? <Spinner size="sm" /> : 'Load older'}
              </Button>
            )}
          </Card.Body>
        </Card>
      )}
      <p className="mt-2 mb-0 text-sm text-muted">
        Every change to rooms, rates, promo rates, extra charges, booking sources, receipt booklets, the menu
        and inventory categories, with who made it and why. Entries can&apos;t be edited or removed. Rows that
        existed before changes were recorded start with what was on record then.
      </p>
    </div>
  )
}
