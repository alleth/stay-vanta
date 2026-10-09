import { useEffect, useState } from 'react'
import { Alert, Badge, Button, ListGroup } from './ui'
import { Skeleton } from './Skeleton'
import { guestHistory } from '../api/guests'
import { describeError } from '../utils/apiError'
import { roleLabel } from '../utils/roles'
import { guestEventDetails, guestEventLabel } from '../utils/guestEvents'

const ATTENTION = new Set(['renamed', 'registered_despite_matches'])

/**
 * Who changed a guest's record, what, when and why (final review G3).
 * Managers only (GU1): the caller checks `guests.guest.view_history`.
 */
export default function GuestRecordHistory({ guestId }) {
  const [events, setEvents] = useState(null)
  const [page, setPage] = useState(1)
  const [hasMore, setHasMore] = useState(false)
  const [error, setError] = useState(null)

  useEffect(() => {
    guestHistory(guestId, page)
      .then((d) => {
        setEvents((prev) => (page === 1 ? d.events : [...(prev ?? []), ...d.events]))
        setHasMore(d.has_more)
      })
      .catch((ex) => setError(describeError(ex, 'Could not load the guest’s record history.')))
  }, [guestId, page])

  if (error) return <Alert variant="danger">{error}</Alert>
  if (!events) return <Skeleton className="h-5 w-2/3" />
  if (events.length === 0) return <p className="mb-0 text-sm text-muted">Nothing recorded yet.</p>

  return (
    <>
      <ListGroup>
        {events.map((e) => (
          <ListGroup.Item key={e.id} className="px-0 py-3">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-medium">{guestEventLabel(e.event)}</span>
              {ATTENTION.has(e.event) && <Badge bg="warning">Reason given</Badge>}
              <span className="ml-auto text-xs text-muted">{new Date(e.at).toLocaleString()}</span>
            </div>
            <div className="text-sm text-muted">
              {e.recorded === false
                ? 'Not recorded (from before guest history began)'
                : `${e.actor ?? 'Unknown user'}${e.actor_role ? ` (${roleLabel(e.actor_role)})` : ''}`}
            </div>
            {guestEventDetails(e).map((line) => <div key={line} className="text-sm">{line}</div>)}
            {e.reason && <div className="text-sm">“{e.reason}”</div>}
          </ListGroup.Item>
        ))}
      </ListGroup>
      {hasMore && (
        <Button size="sm" variant="outline-secondary" className="mt-2" onClick={() => setPage((p) => p + 1)}>Older</Button>
      )}
    </>
  )
}
