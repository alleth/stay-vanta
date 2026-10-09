import { useEffect, useState } from 'react'
import { Alert, Button, ListGroup } from './ui'
import { Skeleton } from './Skeleton'
import { roomHistory } from '../api/frontdesk'
import { describeError } from '../utils/apiError'
import { roleLabel, roomServiceLabel } from '../utils/roles'

const LABELS = {
  maintenance_started: 'Maintenance started',
  maintenance_completed: 'Maintenance completed',
  taken_out_of_service: 'Taken out of service',
  returned_to_service: 'Returned to service',
  imported: 'Under maintenance when room history began',
}

/**
 * A room's service history (final review G4, Managers only, R1): the status
 * before and after, who changed it, why, when, and the request id to quote
 * to support. An imported line has no actor, reason or start time.
 */
export default function RoomServiceHistory({ roomId }) {
  const [events, setEvents] = useState(null)
  const [page, setPage] = useState(1)
  const [hasMore, setHasMore] = useState(false)
  const [error, setError] = useState(null)

  useEffect(() => {
    roomHistory(roomId, page)
      .then((d) => {
        setEvents((prev) => (page === 1 ? d.events : [...(prev ?? []), ...d.events]))
        setHasMore(d.has_more)
      })
      .catch((ex) => setError(describeError(ex, 'Could not load the room’s history.')))
  }, [roomId, page])

  if (error) return <Alert variant="danger">{error}</Alert>
  if (!events) return <Skeleton className="h-5 w-2/3" />
  if (events.length === 0) return <p className="mb-0 text-sm text-muted">No service changes recorded.</p>

  return (
    <>
      <ListGroup>
        {events.map((e) => (
          <ListGroup.Item key={e.id} className="px-0 py-3">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-medium">{LABELS[e.event] ?? e.event}</span>
              <span className="ml-auto text-xs text-muted">{new Date(e.at).toLocaleString()}</span>
            </div>
            <div className="text-sm">
              {e.before ? `${roomServiceLabel(e.before)} → ` : ''}{roomServiceLabel(e.after)}
            </div>
            <div className="text-sm text-muted">
              {e.recorded === false
                ? 'Not recorded: who, why and since when were never kept'
                : `${e.actor ?? 'Unknown user'}${e.actor_role ? ` (${roleLabel(e.actor_role)})` : ''}`}
            </div>
            {e.reason && <div className="text-sm">“{e.reason}”</div>}
            {e.recorded !== false && (
              <div className="text-xs text-muted">Reference: {String(e.request_id).slice(0, 8)}</div>
            )}
          </ListGroup.Item>
        ))}
      </ListGroup>
      {hasMore && (
        <Button size="sm" variant="outline-secondary" className="mt-2" onClick={() => setPage((p) => p + 1)}>Older</Button>
      )}
    </>
  )
}
