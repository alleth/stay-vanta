import { useCallback, useEffect, useState } from 'react'
import {
  Card, Table, Button, Badge, Modal, Form, Alert, Spinner,
} from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { useProperty } from '../context/PropertyContext'
import { useSubmit } from '../hooks/useSubmit'
import {
  listStaff, createStaff, updateStaff, resetStaffPassword,
} from '../api/staff'
import { SkeletonTable } from '../components/Skeleton'

const ROLE_VARIANT = { admin: 'primary', receptionist: 'info' }

export default function Staff() {
  const { role, user } = useAuth()
  const { propertyId } = useProperty()
  // Owners reset anyone; admins change their own password and reset their
  // receptionists, but not a peer admin's.
  const canSetPassword = (u) =>
    role === 'owner' || u.id === user?.id || (role === 'admin' && u.role === 'receptionist')
  const [staff, setStaff] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [pending, setPending] = useState(null) // id of the user whose status is updating
  const [modal, setModal] = useState(null) // 'add' | { type: 'reset', user }

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

  async function toggleActive(u) {
    setPending(u.id)
    setError(null)
    try {
      await updateStaff(u.id, { is_active: !u.is_active })
      await refresh()
    } catch (ex) {
      setError(ex?.response?.data?.message ?? 'Could not update the account.')
    } finally {
      setPending(null)
    }
  }

  if (!propertyId)
    return <Alert variant="info">Select or create a property to manage staff.</Alert>

  return (
    <div>
      <div className="mb-4 flex items-center justify-between">
        <div>
          <h1 className="mb-0 text-2xl font-bold">Staff</h1>
          <small className="text-muted">
            {role === 'owner'
              ? 'Add admins and receptionists for the selected property.'
              : 'Add receptionists for your property.'}
          </small>
        </div>
        <Button onClick={() => setModal('add')}>Add staff</Button>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}

      {loading ? (
        <SkeletonTable rows={5} />
      ) : (
        <Card className="shadow-sm">
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
                  <td><Badge bg={ROLE_VARIANT[u.role] ?? 'secondary'}>{u.role}</Badge></td>
                  <td>
                    {u.is_active
                      ? <Badge bg="success">active</Badge>
                      : <Badge bg="secondary">inactive</Badge>}
                  </td>
                  <td className="whitespace-nowrap text-right">
                    {canSetPassword(u) && (
                      <Button size="sm" variant="outline-secondary" className="mr-2"
                        onClick={() => setModal({ type: 'reset', user: u, self: u.id === user?.id })}>
                        {u.id === user?.id ? 'Change password' : 'Reset password'}
                      </Button>
                    )}
                    {u.id !== user?.id && (
                      <Button size="sm" variant={u.is_active ? 'outline-danger' : 'outline-success'}
                        disabled={pending !== null}
                        onClick={() => toggleActive(u)}>
                        {pending === u.id
                          ? <Spinner size="sm" />
                          : (u.is_active ? 'Deactivate' : 'Reactivate')}
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Card>
      )}

      {modal === 'add' && (
        <AddStaffModal
          role={role}
          propertyId={propertyId}
          onClose={() => setModal(null)}
          onSaved={() => { setModal(null); refresh() }}
        />
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
  // Owners choose the role; admins can only add receptionists.
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
              {allowedRoles.map((r) => <option key={r} value={r}>{r}</option>)}
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
  const [password, setPassword] = useState('')
  const [done, setDone] = useState(false)
  const { run, busy, err } = useSubmit(async () => {
    await resetStaffPassword(user.id, password)
    setDone(true)
  })

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
              Password updated. {self ? 'You may need to sign in again.' : 'Any existing session for this user was revoked.'}
            </Alert>
          ) : (
            <Form.Group>
              <Form.Label>New {self ? '' : 'temporary '}password</Form.Label>
              <Form.Control type="text" value={password} onChange={(e) => setPassword(e.target.value)}
                minLength={8} required autoFocus placeholder="min 8 characters" />
            </Form.Group>
          )}
        </Modal.Body>
        <Modal.Footer>
          {done ? (
            <Button onClick={onSaved}>Done</Button>
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
