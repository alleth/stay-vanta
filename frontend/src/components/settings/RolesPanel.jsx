import { useEffect, useState } from 'react'
import { Card, Alert, Badge } from '../ui'
import { SkeletonCards } from '../Skeleton'
import { listRoles, roleHistory } from '../../api/settings'
import RoleHistory from './RoleHistory'
import { describeError } from '../../utils/apiError'
import { MODULES } from '../../utils/permissionLabels'

/**
 * Settings → Roles (step 10b, Manager): each role and what it may do, read
 * from the grants the server enforces, built-in and custom (E3b-D5).
 * Read-only: the Platform Owner defines roles (Phase 3b).
 */
export default function RolesPanel() {
  const [roles, setRoles] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    listRoles().then(setRoles).catch((ex) => setError(describeError(ex, 'Could not load roles.')))
  }, [])

  if (error) return <Alert variant="danger">{error}</Alert>
  if (!roles) return <SkeletonCards count={2} />

  return (
    <>
      <p className="mb-3 text-sm text-muted">
        What each role may do at this property. The Platform Owner defines roles and changes a
        person&apos;s role; every change and its reason are in the history below.
      </p>
      <div className="grid gap-3 lg:grid-cols-2">
        {roles.map((role) => {
          const byModule = {}
          role.permissions.forEach((p) => {
            const [module, ...rest] = p.split('.')
            ;(byModule[module] ??= []).push(rest.join(' · ').replaceAll('_', ' '))
          })
          return (
            <Card key={role.code}>
              <Card.Header className="flex items-center gap-2">
                <span className="font-semibold">{role.name}</span>
                {role.kind === 'custom'
                  ? <Badge bg="info">Custom</Badge>
                  : <Badge bg="secondary">Built-in</Badge>}
                <span className="ml-auto text-xs text-muted">{role.permissions.length} permissions</span>
              </Card.Header>
              <Card.Body>
                {role.description && <p className="mb-2 text-sm">{role.description}</p>}
                <dl className="m-0 grid gap-2 text-sm">
                  {Object.entries(byModule).map(([module, list]) => (
                    <div key={module}>
                      <dt className="font-medium">{MODULES[module] ?? module}</dt>
                      <dd className="m-0 text-muted">{list.join(', ')}</dd>
                    </div>
                  ))}
                </dl>
              </Card.Body>
            </Card>
          )
        })}
      </div>

      <h2 className="mt-6 mb-2 text-sm font-semibold uppercase tracking-[0.04em] text-muted">History</h2>
      <p className="mb-2 text-sm text-muted">
        Every change to what a role may do, with the approval each release implemented.
      </p>
      <RoleHistory load={roleHistory} />
    </>
  )
}
