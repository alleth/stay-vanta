import { useCallback, useEffect, useState } from 'react'
import {
  Card, Table, Button, Badge, Modal, Form, Alert, Spinner, Tabs, Tab,
} from '../components/ui'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { useProperty } from '../context/PropertyContext'
import { useSubmit } from '../hooks/useSubmit'
import { P } from '../auth/permissions'
import {
  listStaff, createStaff, updateStaff, resetStaffPassword, staffAccessHistory,
} from '../api/staff'
import ReasonModal from '../components/ReasonModal'
import AccessHistory from '../components/AccessHistory'
import SupportSessions from '../components/SupportSessions'
import { SkeletonTable } from '../components/Skeleton'
import { roleLabel } from '../utils/roles'

const ROLE_VARIANT = { admin: 'primary', receptionist: 'info' }

export default function Staff() {
  const { role, user, can } = useAuth()
  // A staff member's sign-ins and account history (step 10, Manager).
  const canSeeHistory = can(P.STAFF_ACCESS_HISTORY_VIEW)
  // Read-only access (a support session, step 10b) sees the list, not the actions.
  const canManage = can(P.STAFF_ACCOUNT_MANAGE)
  const { propertyId } = useProperty()
  // Owners reset anyone; admins change their own password and reset their
  // receptionists, but not a peer admin's.
  const canSetPassword = (u) => canManage
    && (role === 'owner' || u.id === user?.id || (role === 'admin' && u.role === 'receptionist'))
  const [staff, setStaff] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [modal, setModal] = useState(null) // 'add' | { type: 'reset', user, self } | { type: 'history', user }
  // The account being switched off or on: it asks why (step 10).
  const [switching, setSwitching] = useState(null)

  const refresh = useCallback(async () => {
    if (!propertyId) return
    try {
      setStaff(await listStaff(propertyId))
      setError(null)
    } catch {
      setError('Could not load staff.')
    } finally {
      setLoading(false)
    }
  }, [propertyId])

  useEffect(() => {
    // Loads happen after the awaited request resolves; safe data effect.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    refresh()
  }, [refresh])


  if (!propertyId)
    return <Alert variant="info">Select or create a property to manage staff.</Alert>

  return (
    <div>
      <div className="mb-4 flex items-center justify-between">
        <div>
          <h1 className="mb-0 text-2xl font-bold">Staff</h1>
          <small className="text-muted">
            {role === 'owner'
              ? 'Add Managers and Front Desk Staff for the selected property.'
              : 'Add Front Desk Staff for your property.'}
          </small>
        </div>
        {canManage && <Button onClick={() => setModal('add')}>Add staff</Button>}
      </div>

      {error && <Alert variant="danger">{error}</Alert>}

      <Tabs defaultActiveKey="people">
        <Tab eventKey="people" title="People">
          {loading ? (
            <SkeletonTable rows={5} />
          ) : (
            <Card>
              <Table hover>
                <thead>
                  <tr>
                    <th>Name</th><th>Email</th><th>Role</th><th>Status</th>
                    <th className="text-right">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {staff.length === 0 && (
                    <tr><td colSpan={5} className="py-6 text-center text-muted">No staff yet.</td></tr>
                  )}
                  {staff.map((u) => (
                    <tr key={u.id} className={u.is_active ? '' : 'text-muted'}>
                      <td className="font-semibold">{u.name}</td>
                      <td>{u.email}</td>
                      <td><Badge bg={ROLE_VARIANT[u.role] ?? 'secondary'}>{roleLabel(u.role)}</Badge></td>
                      <td>
                        {u.is_active
                          ? <Badge bg="success">active</Badge>
                          : <Badge bg="secondary">inactive</Badge>}
                      </td>
                      <td className="whitespace-nowrap text-right">
                        {canSeeHistory && (
                          <Button size="sm" variant="outline-secondary" className="mr-2"
                            onClick={() => setModal({ type: 'history', user: u })}>
                            History
                          </Button>
                        )}
                        {canSetPassword(u) && (
                          <Button size="sm" variant="outline-secondary" className="mr-2"
                            onClick={() => setModal({ type: 'reset', user: u, self: u.id === user?.id })}>
                            {u.id === user?.id ? 'Change password' : 'Reset password'}
                          </Button>
                        )}
                        {canManage && u.id !== user?.id && (
                          <Button size="sm" variant={u.is_active ? 'outline-danger' : 'outline-success'}
                            onClick={() => setSwitching(u)}>
                            {u.is_active ? 'Deactivate' : 'Reactivate'}
                          </Button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            </Card>
          )}
        </Tab>
        {canSeeHistory && (
          <Tab eventKey="security" title="Security">
            <SupportSessions />
          </Tab>
        )}
      </Tabs>

      {modal === 'add' && (
        <AddStaffModal
          role={role}
          propertyId={propertyId}
          onClose={() => setModal(null)}
          onSaved={() => { setModal(null); refresh() }}
        />
      )}
      <ReasonModal show={switching !== null}
        title={switching ? `${switching.is_active ? 'Deactivate' : 'Reactivate'} ${switching.name}` : ''}
        description={switching && (switching.is_active
          ? 'They are signed out at once and can’t sign in until the account is reactivated. Their history is kept.'
          : 'They can sign in again with their password. No earlier session comes back.')}
        confirmLabel={switching?.is_active ? 'Deactivate' : 'Reactivate'}
        onHide={() => setSwitching(null)}
        onConfirm={async (reason) => {
          await updateStaff(switching.id, { is_active: !switching.is_active, reason })
          setSwitching(null)
          await refresh()
        }} />
      {modal?.type === 'history' && (
        <Modal show onHide={() => setModal(null)} centered>
          <Modal.Header closeButton><Modal.Title>{modal.user.name}</Modal.Title></Modal.Header>
          <Modal.Body className="max-h-[65vh] overflow-y-auto">
            <AccessHistory load={(page) => staffAccessHistory(modal.user.id, page)} />
          </Modal.Body>
          <Modal.Footer>
            <Button variant="secondary" onClick={() => setModal(null)}>Close</Button>
          </Modal.Footer>
        </Modal>
      )}
      {modal?.type === 'reset' && (
        <ResetPasswordModal
          user={modal.user}
          self={modal.self}
          onClose={() => setModal(null)}
          onSaved={() => setModal(null)}
        />
      )}
    </div>
  )
}

function AddStaffModal({ role, propertyId, onClose, onSaved }) {
  // The Platform Owner chooses the role; Managers can only add Front Desk Staff.
  const allowedRoles = role === 'owner' ? ['admin', 'receptionist'] : ['receptionist']
  const [form, setForm] = useState({
    name: '', email: '', password: '', role: allowedRoles[0],
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const { run, busy, err } = useSubmit(async () => {
    await createStaff(form, propertyId)
    onSaved()
  })

  return (
    <Modal show onHide={onClose} centered>
      <Form onSubmit={run}>
        <Modal.Header closeButton><Modal.Title>Add staff</Modal.Title></Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          <Form.Group className="mb-4">
            <Form.Label>Role</Form.Label>
            <Form.Select value={form.role} onChange={set('role')} disabled={allowedRoles.length === 1}>
              {allowedRoles.map((r) => <option key={r} value={r}>{roleLabel(r)}</option>)}
            </Form.Select>
          </Form.Group>
          <Form.Group className="mb-4">
            <Form.Label>Name</Form.Label>
            <Form.Control value={form.name} onChange={set('name')} required autoFocus />
          </Form.Group>
          <Form.Group className="mb-4">
            <Form.Label>Email</Form.Label>
            <Form.Control type="email" value={form.email} onChange={set('email')} required />
          </Form.Group>
          <Form.Group>
            <Form.Label>Temporary password</Form.Label>
            <Form.Control type="text" value={form.password} onChange={set('password')}
              minLength={8} required placeholder="min 8 characters" />
            <Form.Text muted>Share this with the staff member; they can sign in immediately.</Form.Text>
          </Form.Group>
        </Modal.Body>
        <Modal.Footer>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={busy}>{busy ? <Spinner size="sm" /> : 'Create account'}</Button>
        </Modal.Footer>
      </Form>
    </Modal>
  )
}

function ResetPasswordModal({ user, self, onClose, onSaved }) {
  const { logout } = useAuth()
  const navigate = useNavigate()
  const [password, setPassword] = useState('')
  // Your own: prove it's you. Someone else's: say why (step 10).
  const [current, setCurrent] = useState('')
  const [reason, setReason] = useState('')
  const [done, setDone] = useState(false)
  const { run, busy, err } = useSubmit(async () => {
    await resetStaffPassword(user.id, password, self ? { current_password: current } : { reason: reason.trim() })
    setDone(true)
  })
  // Your session ended with the change: sign in again with the new password.
  const finish = () => {
    if (!self) { onSaved(); return }
    logout()
    navigate('/login')
  }

  return (
    <Modal show onHide={onClose} centered>
      <Form onSubmit={run}>
        <Modal.Header closeButton>
          <Modal.Title>{self ? 'Change my password' : `Reset password — ${user.name}`}</Modal.Title>
        </Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          {done ? (
            <Alert variant="success" className="mb-0">
              Password updated. {self
                ? 'Sign in again with your new password.'
                : 'They were signed out and sign in with the new password.'}
            </Alert>
          ) : (
            <div className="grid gap-4">
              {self && (
                <Form.Group>
                  <Form.Label>Current password</Form.Label>
                  <Form.Control type="password" value={current} onChange={(e) => setCurrent(e.target.value)}
                    required autoFocus autoComplete="current-password" />
                </Form.Group>
              )}
              <Form.Group>
                <Form.Label>New {self ? '' : 'temporary '}password</Form.Label>
                <Form.Control type="text" value={password} onChange={(e) => setPassword(e.target.value)}
                  minLength={8} required autoFocus={!self} placeholder="min 8 characters" />
              </Form.Group>
              {!self && (
                <Form.Group>
                  <Form.Label>Reason</Form.Label>
                  <Form.Control as="textarea" rows={2} maxLength={500} value={reason}
                    onChange={(e) => setReason(e.target.value)} required minLength={5}
                    placeholder="e.g. Forgot their password" />
                  <Form.Text>Saved in their account history and can’t be edited later.</Form.Text>
                </Form.Group>
              )}
            </div>
          )}
        </Modal.Body>
        <Modal.Footer>
          {done ? (
            <Button onClick={finish}>{self ? 'Sign in again' : 'Done'}</Button>
          ) : (
            <>
              <Button variant="secondary" onClick={onClose}>Cancel</Button>
              <Button type="submit" disabled={busy}>{busy ? <Spinner size="sm" /> : 'Reset'}</Button>
            </>
          )}
        </Modal.Footer>
      </Form>
    </Modal>
  )
}
