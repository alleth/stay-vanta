import { useCallback, useEffect, useState } from 'react'
import { Alert, Badge, Button, Card, Form, Modal, Spinner, Table } from '../components/ui'
import { SkeletonTable } from '../components/Skeleton'
import RoleEditor, { PermissionPicker } from '../components/platform/RoleEditor'
import { membershipsText } from '../utils/permissionLabels'
import { useAuth } from '../context/AuthContext'
import { P } from '../auth/permissions'
import { createCatalogRole, getCatalogRole, listRoleCatalog } from '../api/platform'
import { useSubmit } from '../hooks/useSubmit'
import { describeError } from '../utils/apiError'

/**
 * Roles (Permissions Phase 3b, the Platform Owner, E3b-D1): every role with
 * who holds it. Built-ins are locked and change only with a release; custom
 * roles are created blank or by copying any role, then edited in the editor.
 * Giving people a custom role arrives in Phase 3c.
 */
export default function PlatformRoles() {
  const { can } = useAuth()
  const canManage = can(P.PLATFORM_ROLE_MANAGE)
  const [roles, setRoles] = useState(null)
  const [error, setError] = useState(null)
  const [openId, setOpenId] = useState(null)
  const [creating, setCreating] = useState(false)

  const load = useCallback(async () => {
    try {
      setRoles(await listRoleCatalog())
      setError(null)
    } catch (ex) {
      setError(describeError(ex, 'Could not load roles.'))
    }
  }, [])

  useEffect(() => {
    // load() sets state only after the request resolves.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load()
  }, [load])

  return (
    <div>
      <div className="mb-4 flex items-center justify-between">
        <div>
          <h1 className="mb-0 text-2xl font-bold">Roles</h1>
          <small className="text-muted">
            What each role may do at a property. Built-in roles change only with a release; copy one to customise it.
          </small>
        </div>
        {canManage && roles && <Button onClick={() => setCreating(true)}>New role</Button>}
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {!roles && !error && <SkeletonTable rows={4} />}
      {roles && (
        <Card>
          <Table hover>
            <thead>
              <tr><th>Role</th><th>Kind</th><th className="text-right">Permissions</th><th>Held by</th><th>Copied from</th></tr>
            </thead>
            <tbody>
              {roles.map((r) => (
                <tr key={r.id} className="cursor-pointer" onClick={() => setOpenId(r.id)}>
                  <td>
                    <div className="font-semibold">{r.name}</div>
                    {r.description && <div className="text-xs text-muted">{r.description}</div>}
                  </td>
                  <td><KindBadge role={r} /></td>
                  <td className="text-right tabular-nums">{r.permission_count}</td>
                  <td className={r.memberships.total ? '' : 'text-muted'}>{membershipsText(r.memberships)}</td>
                  <td className="text-muted">{r.copied_from?.name ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Card>
      )}

      {openId !== null && (
        <Modal show onHide={() => { setOpenId(null); load() }} size="xl">
          <Modal.Header closeButton><Modal.Title>Role</Modal.Title></Modal.Header>
          <Modal.Body className="max-h-[75vh] overflow-y-auto">
            <RoleEditor roleId={openId} onChanged={load} />
          </Modal.Body>
        </Modal>
      )}
      {creating && (
        <NewRoleModal
          roles={roles ?? []}
          onClose={() => setCreating(false)}
          onCreated={(role) => { setCreating(false); load(); setOpenId(role.id) }}
        />
      )}
    </div>
  )
}

export function KindBadge({ role }) {
  if (role.archived_at) return <Badge bg="secondary">Archived</Badge>
  return role.kind === 'system' ? <Badge bg="primary">Built-in</Badge> : <Badge bg="info">Custom</Badge>
}

/**
 * A new custom role: blank (pick its permissions) or a copy of any role, whose
 * permissions at this moment become its template (E3b-D4).
 */
function NewRoleModal({ roles, onClose, onCreated }) {
  const [form, setForm] = useState({ name: '', description: '', copyFrom: roles.find((r) => r.kind === 'system')?.id ?? '', reason: '' })
  const [catalog, setCatalog] = useState(null)
  const [picked, setPicked] = useState(new Set())
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const blank = form.copyFrom === ''

  useEffect(() => {
    // The catalog of property permissions, read from any role's editor data.
    if (!blank || catalog || roles.length === 0) return
    getCatalogRole(roles[0].id).then((r) => setCatalog(r.permissions.map((p) => ({ ...p, held: false, mark: null }))))
  }, [blank, catalog, roles])

  const { run, busy, err } = useSubmit(async () => {
    const role = await createCatalogRole({
      name: form.name,
      description: form.description,
      reason: form.reason,
      ...(blank ? { permissions: [...picked] } : { copy_from: form.copyFrom }),
    })
    onCreated(role)
  })

  return (
    <Modal show onHide={onClose} size={blank ? 'lg' : undefined} centered>
      <Form onSubmit={run}>
        <Modal.Header closeButton><Modal.Title>New role</Modal.Title></Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          <Form.Group className="mb-3">
            <Form.Label>Start from</Form.Label>
            <Form.Select value={form.copyFrom} onChange={set('copyFrom')}>
              {roles.filter((r) => !r.archived_at).map((r) => (
                <option key={r.id} value={r.id}>Copy of {r.name} ({r.permission_count} permissions)</option>
              ))}
              <option value="">Blank: choose permissions</option>
            </Form.Select>
            <Form.Text muted>A copy keeps the source&apos;s permissions as they are now; it doesn&apos;t follow later changes to it.</Form.Text>
          </Form.Group>
          <Form.Group className="mb-3">
            <Form.Label>Name</Form.Label>
            <Form.Control value={form.name} onChange={set('name')} maxLength={60} required autoFocus />
          </Form.Group>
          <Form.Group className="mb-3">
            <Form.Label>Description</Form.Label>
            <Form.Control as="textarea" rows={2} value={form.description} onChange={set('description')} maxLength={500} />
          </Form.Group>
          {blank && (
            catalog
              ? <PermissionPicker permissions={catalog} selected={picked} onChange={setPicked} />
              : <Spinner size="sm" />
          )}
          <Form.Group className="mt-3">
            <Form.Label>Reason</Form.Label>
            <Form.Control as="textarea" rows={2} value={form.reason} onChange={set('reason')} required
              placeholder="Why this role is needed (recorded with your name)" />
          </Form.Group>
        </Modal.Body>
        <Modal.Footer>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={busy || (blank && picked.size === 0)}>
            {busy ? <Spinner size="sm" /> : 'Create role'}
          </Button>
        </Modal.Footer>
      </Form>
    </Modal>
  )
}
