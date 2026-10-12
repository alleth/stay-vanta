import { useCallback, useEffect, useMemo, useState } from 'react'
import { Alert, Badge, Button, Form, ListGroup, Modal, Spinner } from '../ui'
import { Skeleton } from '../Skeleton'
import ReasonModal from '../ReasonModal'
import RoleHistory from '../settings/RoleHistory'
import { useAuth } from '../../context/AuthContext'
import { P } from '../../auth/permissions'
import {
  archiveCatalogRole, getCatalogRole, platformRoleHistory, previewCatalogRole, restoreCatalogRole,
  reviewCatalogRole, saveCatalogRole,
} from '../../api/platform'
import { describeError } from '../../utils/apiError'
import { membershipsText, moduleLabel, permissionAction, permissionLabel } from '../../utils/permissionLabels'

const MARKS = {
  template: { label: 'from template', bg: 'secondary' },
  initial: { label: 'since creation', bg: 'secondary' },
  added: { label: 'added since', bg: 'success' },
  removed: { label: 'removed since', bg: 'danger' },
}

/**
 * The permission checklist, grouped by the screen (module) each opens, with
 * each permission marked against what the role started with and elevated ones
 * flagged. `selected` is a Set; `onChange` receives the new Set. Without
 * `onChange` it is read-only.
 */
export function PermissionPicker({ permissions, selected, onChange }) {
  const groups = useMemo(() => {
    const byModule = {}
    permissions.forEach((p) => { (byModule[p.module] ??= []).push(p) })
    return Object.entries(byModule)
  }, [permissions])
  const toggle = (permission) => {
    const next = new Set(selected)
    if (next.has(permission)) next.delete(permission)
    else next.add(permission)
    onChange(next)
  }

  return (
    <div className="grid gap-3 md:grid-cols-2">
      {groups.map(([module, list]) => (
        <div key={module} className="rounded-md border border-line p-3">
          <div className="mb-2 text-sm font-semibold">{moduleLabel(module)}</div>
          {list.map((p) => (
            <label key={p.permission} className="mb-1 flex items-start gap-2 text-sm">
              <input type="checkbox" className="mt-1" checked={selected.has(p.permission)}
                disabled={!onChange} onChange={() => toggle(p.permission)} />
              <span className="min-w-0 flex-1">
                {permissionAction(p.permission)}
                {p.elevated && <Badge bg="warning" className="ml-1">reason required</Badge>}
                {MARKS[p.mark] && <Badge bg={MARKS[p.mark].bg} className="ml-1">{MARKS[p.mark].label}</Badge>}
              </span>
            </label>
          ))}
        </div>
      ))}
    </div>
  )
}

/**
 * One role in the editor (Permissions Phase 3b, the Platform Owner). Before
 * any save it shows who holds the role, its history, what it inherited from
 * its template, what changed since it was created, and, on Save, the impact
 * of the change; the save carries the version that preview saw (E3b-D2).
 * Built-in roles are read-only (PH-D1).
 */
export default function RoleEditor({ roleId, onChanged }) {
  const { can } = useAuth()
  const canManage = can(P.PLATFORM_ROLE_MANAGE)
  const [role, setRole] = useState(null)
  const [error, setError] = useState(null)
  const [form, setForm] = useState(null)
  const [preview, setPreview] = useState(null)
  const [deciding, setDeciding] = useState(null) // { permission, decision }
  const [archiving, setArchiving] = useState(false)
  const [historyKey, setHistoryKey] = useState(0)

  const apply = useCallback((r) => {
    setRole(r)
    setForm({ name: r.name, description: r.description ?? '', selected: new Set(r.permissions.filter((p) => p.held).map((p) => p.permission)) })
    setHistoryKey((k) => k + 1)
  }, [])

  const load = useCallback(async () => {
    try {
      apply(await getCatalogRole(roleId))
      setError(null)
    } catch (ex) {
      setError(describeError(ex, 'Could not load this role.'))
    }
  }, [roleId, apply])

  useEffect(() => {
    // load() sets state only after the request resolves.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load()
  }, [load])

  const history = useCallback((page) => platformRoleHistory(page, roleId), [roleId])

  if (error && !role) return <Alert variant="danger">{error}</Alert>
  if (!role || !form) return <Skeleton className="h-6 w-1/2" />

  const editable = canManage && role.kind === 'custom' && !role.archived_at
  const held = new Set(role.permissions.filter((p) => p.held).map((p) => p.permission))
  const permsChanged = form.selected.size !== held.size || [...form.selected].some((p) => !held.has(p))
  const dirty = permsChanged || form.name !== role.name || form.description !== (role.description ?? '')

  async function openPreview() {
    setError(null)
    try {
      setPreview(await previewCatalogRole(role.id, [...form.selected], role.version))
    } catch (ex) {
      setError(describeError(ex, 'Could not preview this change.'))
    }
  }

  async function changed(r) {
    apply(r)
    onChanged?.()
  }

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <h2 className="mb-0 text-xl font-bold">{role.name}</h2>
        {role.archived_at ? <Badge bg="secondary">Archived</Badge>
          : role.kind === 'system' ? <Badge bg="primary">Built-in</Badge> : <Badge bg="info">Custom</Badge>}
      </div>
      {error && <Alert variant="danger">{error}</Alert>}

      {/* 1. Who holds it: always visible. */}
      <div className="mb-4 rounded-md border border-line p-3">
        <div className="text-xs uppercase tracking-[0.04em] text-muted">Active memberships</div>
        <div className="sv-serif text-xl font-bold">{membershipsText(role.memberships)}</div>
        {role.memberships.properties.length > 0 && (
          <div className="text-sm text-muted">
            {role.memberships.properties.map((p) => `${p.name}: ${p.count}`).join(' · ')}
          </div>
        )}
        {role.kind === 'custom' && role.memberships.total === 0 && (
          <div className="text-xs text-muted">Custom roles can be given to people from the next release (Phase 3c).</div>
        )}
      </div>

      {/* 2. Template and 3. changes since creation. */}
      <div className="mb-4 grid gap-3 md:grid-cols-2">
        <div className="rounded-md border border-line p-3 text-sm">
          <div className="text-xs uppercase tracking-[0.04em] text-muted">Template</div>
          {role.template
            ? <>Copied from <strong>{role.template.source?.name}</strong> on {new Date(role.template.copied_at).toLocaleDateString()}: {role.template.permissions.length} permissions came from it. It doesn&apos;t follow later changes to that role.</>
            : role.kind === 'system' ? 'Built-in: defined by the platform, changed only with a release.' : 'Created blank.'}
        </div>
        <div className="rounded-md border border-line p-3 text-sm">
          <div className="text-xs uppercase tracking-[0.04em] text-muted">Changed since creation</div>
          {role.since_creation.added.length + role.since_creation.removed.length === 0
            ? 'No changes since it was created.'
            : <>
                <div>+{role.since_creation.added.length} added, −{role.since_creation.removed.length} removed</div>
                {[...role.since_creation.added.map((p) => `+ ${permissionLabel(p)}`), ...role.since_creation.removed.map((p) => `− ${permissionLabel(p)}`)]
                  .map((line) => <div key={line} className="text-xs text-muted">{line}</div>)}
              </>}
        </div>
      </div>

      {/* New in the catalog, waiting for a decision (PH-D5). */}
      {role.review.length > 0 && (
        <Alert variant="warning">
          <div className="mb-1 font-semibold">New permissions waiting for a decision</div>
          {role.review.map((r) => (
            <div key={r.permission} className="flex flex-wrap items-center gap-2 py-1 text-sm">
              <span className="flex-1">{permissionLabel(r.permission)} <span className="text-xs">(added to {r.granted_to.join(', ')})</span></span>
              {editable && <>
                <Button size="sm" variant="outline-success" onClick={() => setDeciding({ permission: r.permission, decision: 'add' })}>Add</Button>
                <Button size="sm" variant="outline-secondary" onClick={() => setDeciding({ permission: r.permission, decision: 'decline' })}>Decline</Button>
              </>}
            </div>
          ))}
        </Alert>
      )}

      {editable && (
        <div className="mb-3 grid gap-3 md:grid-cols-2">
          <Form.Group>
            <Form.Label>Name</Form.Label>
            <Form.Control value={form.name} maxLength={60} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          </Form.Group>
          <Form.Group>
            <Form.Label>Description</Form.Label>
            <Form.Control value={form.description} maxLength={500} onChange={(e) => setForm({ ...form, description: e.target.value })} />
          </Form.Group>
        </div>
      )}
      {role.kind === 'system' && (
        <Alert variant="info">Built-in roles change only with a release. Use &ldquo;New role&rdquo; to copy this one and customise the copy.</Alert>
      )}
      {role.archived_at && <Alert variant="secondary">Archived. Restore it to change it.</Alert>}

      <PermissionPicker permissions={role.permissions} selected={form.selected}
        onChange={editable ? (s) => setForm({ ...form, selected: s }) : undefined} />

      {canManage && role.kind === 'custom' && (
        <div className="mt-4 flex flex-wrap gap-2">
          {editable && (
            <Button disabled={!dirty || form.selected.size === 0} onClick={openPreview}>Review and save</Button>
          )}
          {!role.archived_at
            ? <Button variant="outline-danger" onClick={() => setArchiving(true)}>Archive</Button>
            : <Button variant="outline-success" onClick={() => setArchiving(true)}>Restore</Button>}
        </div>
      )}

      {/* History, newest first, from role_events. */}
      <h3 className="mt-6 mb-2 text-sm font-semibold uppercase tracking-[0.04em] text-muted">History</h3>
      <RoleHistory key={historyKey} load={history} />

      {preview && (
        <ImpactModal role={role} form={form} preview={preview} onHide={() => setPreview(null)}
          onReload={async () => { setPreview(null); await load() }}
          onSaved={async (r) => { setPreview(null); await changed(r) }} />
      )}
      <ReasonModal show={deciding !== null}
        title={deciding?.decision === 'add' ? 'Add this permission' : 'Decline this permission'}
        description={deciding && `${permissionLabel(deciding.permission)} for ${role.name}. Your decision and reason are recorded in its history.`}
        confirmLabel={deciding?.decision === 'add' ? 'Add' : 'Decline'}
        onHide={() => setDeciding(null)}
        onConfirm={async (reason) => {
          await changed(await reviewCatalogRole(role.id, deciding.permission, deciding.decision, reason))
          setDeciding(null)
        }} />
      <ReasonModal show={archiving}
        title={role.archived_at ? `Restore ${role.name}` : `Archive ${role.name}`}
        description={role.archived_at
          ? 'It can be edited again.'
          : 'An archived role can’t be given to anyone or changed until it is restored. Its history is kept.'}
        confirmLabel={role.archived_at ? 'Restore' : 'Archive'}
        onHide={() => setArchiving(false)}
        onConfirm={async (reason) => {
          await changed(role.archived_at ? await restoreCatalogRole(role.id, reason) : await archiveCatalogRole(role.id, reason))
          setArchiving(false)
        }} />
    </div>
  )
}

/**
 * The impact of a pending change, before it is written: permissions added and
 * removed, screens gained and lost, who holds the role, and a reason. The save
 * carries the version this preview saw; if the role changed since, it is
 * refused and the editor offers to reload (E3b-D2).
 */
function ImpactModal({ role, form, preview, onHide, onReload, onSaved }) {
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)
  const [stale, setStale] = useState(preview.stale)
  const renamed = form.name !== role.name
  const described = form.description !== (role.description ?? '')

  async function save() {
    setBusy(true)
    setErr(null)
    try {
      onSaved(await saveCatalogRole(role.id, {
        version: role.version,
        reason,
        ...(renamed ? { name: form.name } : {}),
        ...(described ? { description: form.description } : {}),
        permissions: [...form.selected],
      }))
    } catch (ex) {
      if (ex.response?.status === 409) setStale(true)
      setErr(describeError(ex, 'Could not save this change.'))
      setBusy(false)
    }
  }

  return (
    <Modal show onHide={onHide} centered>
      <Modal.Header closeButton><Modal.Title>Save changes to {role.name}</Modal.Title></Modal.Header>
      <Modal.Body>
        {stale ? (
          <Alert variant="warning">
            This role changed since you opened it. Reload to see the latest before saving.
          </Alert>
        ) : err && <Alert variant="danger">{err}</Alert>}
        <p className="mb-2 font-semibold">
          {membershipsText(preview.memberships)} {preview.memberships.total ? 'will be affected on their next request; nobody is signed out.' : 'hold this role.'}
        </p>
        {renamed && <p className="mb-1 text-sm">Rename: {role.name} → {form.name}</p>}
        {described && <p className="mb-1 text-sm">New description</p>}
        <ImpactList title={`Permissions added (${preview.added.length})`} items={preview.added.map(permissionLabel)} />
        <ImpactList title={`Permissions removed (${preview.removed.length})`} items={preview.removed.map(permissionLabel)} />
        <ImpactList title="Screens gained" items={preview.modules_gained.map(moduleLabel)} />
        <ImpactList title="Screens lost" items={preview.modules_lost.map(moduleLabel)} />
        {preview.elevated_added.length > 0 && (
          <Alert variant="warning" className="mt-2">Adds actions that require a reason: {preview.elevated_added.map(permissionLabel).join(', ')}.</Alert>
        )}
        <Form.Group className="mt-3">
          <Form.Label>Reason</Form.Label>
          <Form.Control as="textarea" rows={2} value={reason} onChange={(e) => setReason(e.target.value)}
            placeholder="Why (recorded with your name and the request)" disabled={stale} />
        </Form.Group>
      </Modal.Body>
      <Modal.Footer>
        <Button variant="secondary" onClick={onHide}>Cancel</Button>
        {stale
          ? <Button onClick={onReload}>Reload</Button>
          : <Button onClick={save} disabled={busy || reason.trim() === ''}>{busy ? <Spinner size="sm" /> : 'Save change'}</Button>}
      </Modal.Footer>
    </Modal>
  )
}

function ImpactList({ title, items }) {
  if (items.length === 0) return null
  return (
    <div className="mb-2">
      <div className="text-xs uppercase tracking-[0.04em] text-muted">{title}</div>
      <ListGroup>
        {items.map((i) => <ListGroup.Item key={i} className="px-0 py-1 text-sm">{i}</ListGroup.Item>)}
      </ListGroup>
    </div>
  )
}
