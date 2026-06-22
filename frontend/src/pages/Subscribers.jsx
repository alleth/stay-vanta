import { useEffect, useState } from 'react'
import { Card, Table, Badge, Button, Spinner, Alert, Row, Col, Form, Collapse } from 'react-bootstrap'
import {
  listSubscribers,
  createSubscriber,
  createSubscriberAdmin,
  updateProperty,
} from '../api/reports'
import { SkeletonTable } from '../components/Skeleton'
import { formatMoney } from '../utils/format'

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
      await createSubscriberAdmin(property.id, {
        name: form.admin_name,
        email: form.admin_email,
        password: form.admin_password,
      })
      setForm(BLANK)
      setShowForm(false)
      await load()
    } catch (err) {
      setFormError(
        err?.response?.data?.message ||
          'Could not create the subscriber. Check the fields (email must be unique).'
      )
    } finally {
      setSaving(false)
    }
  }

  if (error) return <Alert variant="danger">{error}</Alert>
  if (!rows) return <SkeletonTable rows={5} />

  return (
    <div>
      <div className="d-flex justify-content-between align-items-center mb-1">
        <h1 className="sv-serif fw-bold m-0" style={{ fontSize: '2rem' }}>Subscribers</h1>
        <Button variant="primary" size="sm" onClick={() => setShowForm((s) => !s)}>
          {showForm ? 'Cancel' : 'Add subscriber'}
        </Button>
      </div>
      <p className="mb-4" style={{ color: 'var(--sv-muted)' }}>
        Hotels &amp; resorts subscribed to the platform, each with its admin and monthly fee.
      </p>

      <Collapse in={showForm}>
        <div>
          <Card className="mb-4">
            <Card.Header>New subscriber</Card.Header>
            <Card.Body>
              {formError && <Alert variant="danger">{formError}</Alert>}
              <Form onSubmit={submit}>
                <Row className="g-3">
                  <Col md={6}>
                    <Form.Label>Hotel / resort name</Form.Label>
                    <Form.Control value={form.name} onChange={set('name')} required />
                  </Col>
                  <Col md={3}>
                    <Form.Label>Type</Form.Label>
                    <Form.Select value={form.type} onChange={set('type')}>
                      <option value="hotel">Hotel</option>
                      <option value="resort">Resort</option>
                    </Form.Select>
                  </Col>
                  <Col md={3}>
                    <Form.Label>Monthly fee (₱)</Form.Label>
                    <Form.Control
                      type="number"
                      min="0"
                      step="0.01"
                      value={form.subscription_fee}
                      onChange={set('subscription_fee')}
                    />
                  </Col>
                  <Col md={12}>
                    <Form.Label>Address</Form.Label>
                    <Form.Control value={form.address} onChange={set('address')} />
                  </Col>
                  <Col md={12}>
                    <hr className="my-1" />
                    <div className="small text-uppercase" style={{ color: 'var(--sv-muted)', letterSpacing: '0.04em' }}>
                      Admin (hotel/resort head)
                    </div>
                  </Col>
                  <Col md={4}>
                    <Form.Label>Admin name</Form.Label>
                    <Form.Control value={form.admin_name} onChange={set('admin_name')} required />
                  </Col>
                  <Col md={4}>
                    <Form.Label>Admin email</Form.Label>
                    <Form.Control type="email" value={form.admin_email} onChange={set('admin_email')} required />
                  </Col>
                  <Col md={4}>
                    <Form.Label>Temp password</Form.Label>
                    <Form.Control type="text" value={form.admin_password} onChange={set('admin_password')} required />
                  </Col>
                </Row>
                <div className="mt-3 text-end">
                  <Button type="submit" variant="primary" disabled={saving}>
                    {saving ? <Spinner size="sm" /> : 'Create subscriber'}
                  </Button>
                </div>
              </Form>
            </Card.Body>
          </Card>
        </div>
      </Collapse>

      <Card>
        <Card.Header>Registered hotels &amp; resorts</Card.Header>
        <Table responsive hover className="align-middle mb-0">
          <thead>
            <tr>
              <th>Name</th>
              <th>Type</th>
              <th>Admin</th>
              <th>Subscription</th>
              <th className="text-end">Monthly fee</th>
              <th>Expires</th>
              <th className="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && (
              <tr>
                <td colSpan={7} className="text-center py-4" style={{ color: 'var(--sv-muted)' }}>
                  No subscribers yet.
                </td>
              </tr>
            )}
            {rows.map((p) => {
              const admin = (p.users || [])[0]
              return (
                <tr key={p.id}>
                  <td className="fw-medium">{p.name}</td>
                  <td className="text-capitalize">{p.type}</td>
                  <td>
                    {admin ? (
                      <div>
                        <div>{admin.name}</div>
                        <div className="small" style={{ color: 'var(--sv-muted)' }}>{admin.email}</div>
                      </div>
                    ) : (
                      <span style={{ color: 'var(--sv-muted)' }}>— none —</span>
                    )}
                  </td>
                  <td>
                    {p.subscription_active ? (
                      <Badge bg="warning">Active</Badge>
                    ) : (
                      <Badge bg="secondary">Inactive</Badge>
                    )}
                  </td>
                  <td className="text-end">{formatMoney(p.subscription_fee)}</td>
                  <td style={{ color: 'var(--sv-muted)' }}>{p.subscription_expires_at ?? '—'}</td>
                  <td className="text-end">
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
    </div>
  )
}
