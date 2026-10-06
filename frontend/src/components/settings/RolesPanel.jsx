import { useEffect, useState } from 'react'
import { Card, Alert, Badge } from '../ui'
import { SkeletonCards } from '../Skeleton'
import { listRoles } from '../../api/settings'
import { describeError } from '../../utils/apiError'

// Module part of a permission name, as screens call it.
const MODULES = {
  operations: 'Operations', finance: 'Finance', front_desk: 'Front Desk', guests: 'Guests', pos: 'POS',
  rooms: 'Rooms', inventory: 'Inventory', settings: 'Settings', staff: 'Staff', platform: 'Platform',
}

/**
 * Settings → Roles (step 10b, Manager): each role and what it may do, read
 * from the grants the server enforces. Read-only: editing roles (audited) is
 * Permissions Phase 3.
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
        What each role may do at this property. Roles can&apos;t be edited yet; the Platform Owner changes a
        person&apos;s role.
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
                {role.preset && <Badge bg="secondary">Preset</Badge>}
                <span className="ml-auto text-xs text-muted">{role.permissions.length} permissions</span>
              </Card.Header>
              <Card.Body>
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
    </>
  )
}
