import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Card, Table, Badge, Button, Spinner, Alert, Form, Modal } from '../components/ui'
import {
  listSubscribers,
  createSubscriber,
  createSubscriberManager,
  updateProperty,
  propertyChanges,
  startSupport,
} from '../api/platform'
import ReasonModal from '../components/ReasonModal'
import PropertyStaffModal from '../components/platform/PropertyStaffModal'
import { useAuth } from '../context/AuthContext'
import ConfigHistory from '../components/settings/ConfigHistory'
import { SkeletonTable } from '../components/Skeleton'
import { formatMoney } from '../utils/format'
import { describeError } from '../utils/apiError'

const BLANK = {
  name: '',
  type: 'hotel',
  address: '',
  subscription_fee: '',
  admin_name: '',
  admin_email: '',
  admin_password: '',
}

export default function Subscribers() {
  const [rows, setRows] = useState(null)
  const [error, setError] = useState(null)
  const [busyId, setBusyId] = useState(null)
  // The property whose change history is open (fee, subscription, name; step 9).
  const [history, setHistory] = useState(null)
  // The property whose people are open (role changes, step 10b).
  const [staffOf, setStaffOf] = useState(null)
  // The property a support session is being started for (A6).
  const [supportFor, setSupportFor] = useState(null)
  const { refresh } = useAuth()
  const navigate = useNavigate()

  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState(BLANK)
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState(null)

  async function load() {
    setError(null)
    try {
      setRows(await listSubscribers())
    } catch {
      setError('Could not load subscribers.')
    }
  }

  useEffect(() => {
    // load() only updates state after awaiting the network; safe data effect.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load()
  }, [])

  async function toggle(p) {
    const next = p.subscription_status === 'active' ? 'inactive' : 'active'
    setBusyId(p.id)
    try {
      await updateProperty(p.id, { subscription_status: next })
      await load()
    } catch {
      setError('Could not update the subscription.')
    } finally {
      setBusyId(null)
    }
  }

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }))

  async function submit(e) {
    e.preventDefault()
    setFormError(null)
    setSaving(true)
    try {
      const property = await createSubscriber({
        name: form.name,
        type: form.type,
        address: form.address,
        subscription_fee: form.subscription_fee || 0,
      })
      await createSubscriberManager(property.id, {
        name: form.admin_name,
        email: form.admin_email,
        password: form.admin_password,
      })
      setForm(BLANK)
      setShowForm(false)
      await load()
    } catch (err) {
      setFormError(
        describeError(err, 'Could not create the subscriber. Check the fields (email must be unique).'),
      )
    } finally {
      setSaving(false)
    }
  }

  if (error) return <Alert variant="danger">{error}</Alert>
  if (!rows) return <SkeletonTable rows={5} />

  return (
    <div>
      <div className="mb-1 flex items-center justify-between">
        <h1 className="sv-serif m-0 text-[2rem] font-bold">Subscribers</h1>
        <Button variant="primary" size="sm" onClick={() => setShowForm((s) => !s)}>
          {showForm ? 'Cancel' : 'Add subscriber'}
        </Button>
      </div>
      <p className="mb-6 text-muted">
        Hotels &amp; resorts subscribed to the platform, each with its Manager and monthly fee.
      </p>

      {showForm && (
        <Card className="mb-6">
          <Card.Header>New subscriber</Card.Header>
          <Card.Body>
            {formError && <Alert variant="danger">{formError}</Alert>}
            <Form onSubmit={submit}>
              <div className="grid grid-cols-1 gap-4 md:grid-cols-12">
                <div className="md:col-span-6">
                  <Form.Label>Hotel / resort name</Form.Label>
                  <Form.Control value={form.name} onChange={set('name')} required />
                </div>
                <div className="md:col-span-3">
                  <Form.Label>Type</Form.Label>
                  <Form.Select value={form.type} onChange={set('type')}>
                    <option value="hotel">Hotel</option>
                    <option value="resort">Resort</option>
                  </Form.Select>
                </div>
                <div className="md:col-span-3">
                  <Form.Label>Monthly fee (₱)</Form.Label>
                  <Form.Control
                    type="number"
                    min="0"
                    step="0.01"
                    value={form.subscription_fee}
                    onChange={set('subscription_fee')}
                  />
                </div>
                <div className="md:col-span-12">
                  <Form.Label>Address</Form.Label>
                  <Form.Control value={form.address} onChange={set('address')} />
                </div>
                <div className="md:col-span-12">
                  <hr className="my-1" />
                  <div className="text-sm uppercase tracking-[0.04em] text-muted">
                    Manager (runs the hotel)
                  </div>
                </div>
                <div className="md:col-span-4">
                  <Form.Label>Manager name</Form.Label>
                  <Form.Control value={form.admin_name} onChange={set('admin_name')} required />
                </div>
                <div className="md:col-span-4">
                  <Form.Label>Manager email</Form.Label>
                  <Form.Control type="email" value={form.admin_email} onChange={set('admin_email')} required />
                </div>
                <div className="md:col-span-4">
                  <Form.Label>Temp password</Form.Label>
                  <Form.Control type="text" value={form.admin_password} onChange={set('admin_password')} required />
                </div>
              </div>
              <div className="mt-4 text-right">
                <Button type="submit" variant="primary" disabled={saving}>
                  {saving ? <Spinner size="sm" /> : 'Create subscriber'}
                </Button>
              </div>
            </Form>
          </Card.Body>
        </Card>
      )}

      <Card>
        <Card.Header>Registered hotels &amp; resorts</Card.Header>
        <Table hover>
          <thead>
            <tr>
              <th>Name</th>
              <th>Type</th>
              <th>Manager</th>
              <th>Subscription</th>
              <th className="text-right">Monthly fee</th>
              <th>Expires</th>
              <th className="text-right">Action</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && (
              <tr>
                <td colSpan={7} className="py-6 text-center text-muted">
                  No subscribers yet.
                </td>
              </tr>
            )}
            {rows.map((p) => {
              const admin = (p.users || [])[0]
              return (
                <tr key={p.id}>
                  <td className="font-medium">{p.name}</td>
                  <td className="capitalize">{p.type}</td>
                  <td>
                    {admin ? (
                      <div>
                        <div>{admin.name}</div>
                        <div className="text-xs text-muted">{admin.email}</div>
                      </div>
                    ) : (
                      <span className="text-muted">— none —</span>
                    )}
                  </td>
                  <td>
                    {p.subscription_active ? (
                      <Badge bg="warning">Active</Badge>
                    ) : (
                      <Badge bg="secondary">Inactive</Badge>
                    )}
                  </td>
                  <td className="text-right">{formatMoney(p.subscription_fee)}</td>
                  <td className="text-muted">{p.subscription_expires_at ?? '—'}</td>
                  <td className="whitespace-nowrap text-right">
                    <Button size="sm" variant="outline-secondary" className="mr-1" onClick={() => setHistory(p)}>
                      History
                    </Button>
                    <Button size="sm" variant="outline-secondary" className="mr-1" onClick={() => setStaffOf(p)}>
                      Staff
                    </Button>
                    <Button size="sm" variant="outline-secondary" className="mr-1" onClick={() => setSupportFor(p)}>
                      Support access
                    </Button>
                    <Button
                      size="sm"
                      variant="outline-secondary"
                      disabled={busyId === p.id}
                      onClick={() => toggle(p)}
                    >
                      {busyId === p.id ? (
                        <Spinner size="sm" />
                      ) : p.subscription_status === 'active' ? (
                        'Deactivate'
                      ) : (
                        'Activate'
                      )}
                    </Button>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </Table>
      </Card>

      {staffOf && <PropertyStaffModal property={staffOf} onHide={() => setStaffOf(null)} />}

      {supportFor && (
        <ReasonModal
          show
          title={`Support access to ${supportFor.name}`}
          description="Read-only, for 60 minutes. Every page you open is recorded, and the property’s Manager sees the session, your reason, and can end it."
          confirmLabel="Start support access"
          onHide={() => setSupportFor(null)}
          onConfirm={async (reason) => {
            await startSupport(supportFor.id, reason)
            setSupportFor(null)
            await refresh()
            navigate('/hub')
          }}
        />
      )}

      {history && (
        <Modal show onHide={() => setHistory(null)} centered>
          <Modal.Header closeButton><Modal.Title>{history.name}</Modal.Title></Modal.Header>
          <Modal.Body className="pt-0">
            <ConfigHistory entityType="property" entityId={history.id}
              load={(page) => propertyChanges(history.id, page)} />
          </Modal.Body>
          <Modal.Footer>
            <Button variant="secondary" onClick={() => setHistory(null)}>Close</Button>
          </Modal.Footer>
        </Modal>
      )}
    </div>
  )
}
