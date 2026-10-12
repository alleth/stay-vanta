import { useEffect, useState } from 'react'
import { Alert, Button, ListGroup } from '../ui'
import { Skeleton } from '../Skeleton'
import { describeError } from '../../utils/apiError'
import { permissionLabel } from '../../utils/permissionLabels'

const LABELS = {
  role_created: 'Role created',
  role_renamed: 'Role renamed',
  permission_granted: 'Permission granted',
  permission_revoked: 'Permission removed',
  role_imported: 'Role as it stood when history began',
  permission_imported: 'Permission held when history began',
  role_copied: 'Role created as a copy',
  role_description_changed: 'Description changed',
  role_archived: 'Role archived',
  role_restored: 'Role restored',
  permission_declined: 'New permission declined',
}

/**
 * Role-definition history (final review G7): every change to what a role may
 * do, newest first, with why (the approval a release implemented), the
 * release and how many people held the role. A change is a deploy, so no
 * person is named; imported lines say what was never recorded. `load(page)`
 * is the endpoint for the viewer (Managers or the Platform Owner, R-D4).
 */
export default function RoleHistory({ load }) {
  const [events, setEvents] = useState(null)
  const [page, setPage] = useState(1)
  const [hasMore, setHasMore] = useState(false)
  const [error, setError] = useState(null)

  useEffect(() => {
    load(page)
      .then((d) => {
        setEvents((prev) => (page === 1 ? d.events : [...(prev ?? []), ...d.events]))
        setHasMore(d.has_more)
      })
      .catch((ex) => setError(describeError(ex, 'Could not load role history.')))
  }, [load, page])

  if (error) return <Alert variant="danger">{error}</Alert>
  if (!events) return <Skeleton className="h-5 w-2/3" />
  if (events.length === 0) return <p className="mb-0 text-sm text-muted">No role changes recorded.</p>

  return (
    <>
      <ListGroup>
        {events.map((e) => (
          <ListGroup.Item key={e.id} className="px-0 py-3">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-medium">{LABELS[e.event] ?? e.event}</span>
              <span className="text-sm text-muted">{e.role_name ?? e.role}</span>
              <span className="ml-auto text-xs text-muted">{new Date(e.at).toLocaleString()}</span>
            </div>
            {e.permission && <div className="text-sm">{permissionLabel(e.permission)}</div>}
            {e.changes?.name && <div className="text-sm">{e.changes.name.before} → {e.changes.name.after}</div>}
            <div className="text-sm text-muted">
              {e.source === 'import'
                ? 'Not recorded: who approved it and why were never kept'
                : (e.source === 'web'
                  ? `${e.actor ?? 'Unknown user'} (Platform Owner)`
                  : `Release${e.commit ? ` ${e.commit.slice(0, 7)}` : ''}`)
                  + (e.active_memberships !== null && e.active_memberships !== undefined
                    ? ` · ${e.active_memberships} ${e.active_memberships === 1 ? 'person' : 'people'} held this role`
                    : '')}
            </div>
            {e.reason && <div className="text-sm">“{e.reason}”</div>}
            <div className="text-xs text-muted">Reference: {String(e.request_id)}</div>
          </ListGroup.Item>
        ))}
      </ListGroup>
      {hasMore && (
        <Button size="sm" variant="outline-secondary" className="mt-2" onClick={() => setPage((p) => p + 1)}>Older</Button>
      )}
    </>
  )
}
