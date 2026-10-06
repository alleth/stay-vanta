import { useCallback, useEffect, useState } from 'react'
import { Modal, Table, Badge, Button, Alert, Form } from '../ui'
import { SkeletonTableRows } from '../Skeleton'
import ReasonModal from '../ReasonModal'
import { listStaff, updateStaff } from '../../api/staff'
import { describeError } from '../../utils/apiError'
import { roleLabel } from '../../utils/roles'

/**
 * A property's people, for the Platform Owner (step 10b): only they change a
 * role (Manager ↔ Front Desk Staff), always with a reason, and the change ends
 * that person's session so they sign in with their new permissions.
 */
export default function PropertyStaffModal({ property, onHide }) {
  const [people, setPeople] = useState(null)
  const [error, setError] = useState(null)
  const [changing, setChanging] = useState(null)
  const [role, setRole] = useState('')

  const load = useCallback(async () => {
    setError(null)
    try {
      setPeople(await listStaff(property.id))
    } catch (ex) {
      setError(describeError(ex, 'Could not load this property’s staff.'))
    }
  }, [property.id])

  useEffect(() => {
    // load() only sets state after awaiting the network.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load()
  }, [load])

  function openChange(person) {
    setChanging(person)
    setRole(person.role === 'admin' ? 'receptionist' : 'admin')
  }

  return (
    <>
      <Modal show={!changing} onHide={onHide} size="lg" centered>
        <Modal.Header closeButton><Modal.Title>{property.name} · Staff</Modal.Title></Modal.Header>
        <Modal.Body className="pt-0">
          {error && <Alert variant="danger">{error}</Alert>}
          <Table hover>
            <thead>
              <tr><th>Name</th><th>Role</th><th>Status</th><th className="text-right">Action</th></tr>
            </thead>
            <tbody>
              {!people && !error && <SkeletonTableRows rows={3} cols={4} />}
              {people?.length === 0 && (
                <tr><td colSpan={4} className="py-6 text-center text-muted">No staff yet.</td></tr>
              )}
              {people?.map((p) => (
                <tr key={p.id}>
                  <td>
                    <div className="font-medium">{p.name}</div>
                    <div className="text-xs text-muted">{p.email}</div>
                  </td>
                  <td>{roleLabel(p.role)}</td>
                  <td>{p.is_active ? <Badge bg="success">Active</Badge> : <Badge bg="secondary">Inactive</Badge>}</td>
                  <td className="text-right">
                    <Button size="sm" variant="outline-secondary" onClick={() => openChange(p)}>
                      Change role
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Modal.Body>
        <Modal.Footer>
          <Button variant="secondary" onClick={onHide}>Close</Button>
        </Modal.Footer>
      </Modal>

      {changing && (
        <ReasonModal
          show
          title={`Change ${changing.name}’s role`}
          description="Their session ends at once, so they sign in again with the new role. The change and your reason are recorded in their access history."
          confirmLabel="Change role"
          ready={role !== changing.role}
          onHide={() => setChanging(null)}
          onConfirm={async (reason) => {
            await updateStaff(changing.id, { role, reason })
            setChanging(null)
            await load()
          }}
        >
          <Form.Group className="mb-3">
            <Form.Label>New role</Form.Label>
            <Form.Select value={role} onChange={(e) => setRole(e.target.value)}>
              <option value="admin">{roleLabel('admin')}</option>
              <option value="receptionist">{roleLabel('receptionist')}</option>
            </Form.Select>
          </Form.Group>
        </ReasonModal>
      )}
    </>
  )
}
