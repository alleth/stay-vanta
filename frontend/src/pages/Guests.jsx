import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Row, Col, Card, Table, Button, Badge, Modal, Form, InputGroup, Alert, Spinner, ListGroup,
} from 'react-bootstrap'
import { useProperty } from '../context/PropertyContext'
import { useSubmit } from '../hooks/useSubmit'
import { listGuests, guestStats, getGuest, createGuest, updateGuest } from '../api/guests'

const TYPE_VARIANT = { local: 'info', foreign: 'warning' }

export default function Guests() {
  const { propertyId } = useProperty()
  const [guests, setGuests] = useState([])
  const [stats, setStats] = useState({ total: 0, local: 0, foreign: 0, inHouse: 0 })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [search, setSearch] = useState('')
  const [typeFilter, setTypeFilter] = useState('all')
  const [modal, setModal] = useState(null) // 'add' | { type:'edit', guest } | { type:'view', id }

  const refresh = useCallback(async () => {
    if (!propertyId) return
    try {
      const [g, s] = await Promise.all([listGuests(propertyId), guestStats(propertyId)])
      setGuests(g)
      setStats(s)
      setError(null)
    } catch {
      setError('Could not load guests.')
    } finally {
      setLoading(false)
    }
  }, [propertyId])

  useEffect(() => {
    // State updates run after the awaited fetch; safe data effect.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    refresh()
  }, [refresh])

  // Search + type filtering happen client-side over the loaded list.
  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase()
    return guests.filter(
      (g) =>
        (typeFilter === 'all' || g.guest_type === typeFilter) &&
        (q === '' || g.full_name.toLowerCase().includes(q)),
    )
  }, [guests, search, typeFilter])

  if (!propertyId)
    return <Alert variant="info">Select or create a property to view guests.</Alert>

  return (
    <div>
      <div className="d-flex justify-content-between align-items-center mb-3">
        <h1 className="h3 fw-bold mb-0">Guests</h1>
        <Button onClick={() => setModal('add')}>Add guest</Button>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}

      <Row className="g-3 mb-4">
        <StatCard label="Total guests" value={stats.total} variant="dark" />
        <StatCard label="Local" value={stats.local} variant="info" />
        <StatCard label="Foreign" value={stats.foreign} variant="warning" />
        <StatCard label="Currently in-house" value={stats.inHouse} variant="success" />
      </Row>

      <Card className="shadow-sm">
        <Card.Header className="d-flex gap-2 flex-wrap align-items-center">
          <InputGroup style={{ maxWidth: 280 }}>
            <InputGroup.Text>Search</InputGroup.Text>
            <Form.Control value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Guest name" />
          </InputGroup>
          <Form.Select style={{ maxWidth: 180 }} value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)}>
            <option value="all">All types</option>
            <option value="local">Local</option>
            <option value="foreign">Foreign</option>
          </Form.Select>
          <span className="text-muted small ms-auto">{filtered.length} shown</span>
        </Card.Header>

        {loading ? (
          <div className="text-center py-5"><Spinner /></div>
        ) : (
          <Table responsive hover className="mb-0 align-middle">
            <thead>
              <tr><th>Name</th><th>Type</th><th>Nationality</th><th className="text-end">Actions</th></tr>
            </thead>
            <tbody>
              {filtered.length === 0 && (
                <tr><td colSpan={4} className="text-center text-muted py-4">No guests found.</td></tr>
              )}
              {filtered.map((g) => (
                <tr key={g.id}>
                  <td className="fw-semibold">{g.full_name}</td>
                  <td><Badge bg={TYPE_VARIANT[g.guest_type]}>{g.guest_type}</Badge></td>
                  <td>{g.nationality ?? '—'}</td>
                  <td className="text-end text-nowrap">
                    <Button size="sm" variant="outline-secondary" className="me-1"
                      onClick={() => setModal({ type: 'view', id: g.id })}>History</Button>
                    <Button size="sm" variant="outline-primary"
                      onClick={() => setModal({ type: 'edit', guest: g })}>Edit</Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>

      {(modal === 'add' || modal?.type === 'edit') && (
        <GuestModal
          guest={modal?.guest}
          propertyId={propertyId}
          onClose={() => setModal(null)}
          onSaved={() => { setModal(null); refresh() }}
        />
      )}
      {modal?.type === 'view' && (
        <HistoryModal id={modal.id} onClose={() => setModal(null)} />
      )}
    </div>
  )
}

function StatCard({ label, value, variant }) {
  return (
    <Col xs={6} lg={3}>
      <Card className="shadow-sm h-100">
        <Card.Body>
          <div className="text-muted small">{label}</div>
          <div className={`fs-2 fw-bold text-${variant}`}>{value}</div>
        </Card.Body>
      </Card>
    </Col>
  )
}

function GuestModal({ guest, propertyId, onClose, onSaved }) {
  const editing = Boolean(guest)
  const [form, setForm] = useState({
    full_name: guest?.full_name ?? '',
    guest_type: guest?.guest_type ?? 'local',
    nationality: guest?.nationality ?? '',
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const { run, busy, err } = useSubmit(async () => {
    if (editing) await updateGuest(guest.id, form)
    else await createGuest(form, propertyId)
    onSaved()
  })

  return (
    <Modal show onHide={onClose} centered>
      <Form onSubmit={run}>
        <Modal.Header closeButton><Modal.Title>{editing ? 'Edit guest' : 'Add guest'}</Modal.Title></Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          <Form.Group className="mb-3">
            <Form.Label>Full name</Form.Label>
            <Form.Control value={form.full_name} onChange={set('full_name')} required autoFocus />
          </Form.Group>
          <Row>
            <Col><Form.Group className="mb-3">
              <Form.Label>Type</Form.Label>
              <Form.Select value={form.guest_type} onChange={set('guest_type')}>
                <option value="local">Local</option>
                <option value="foreign">Foreign</option>
              </Form.Select>
            </Form.Group></Col>
            <Col><Form.Group className="mb-3">
              <Form.Label>Nationality</Form.Label>
              <Form.Control value={form.nationality} onChange={set('nationality')} placeholder="Optional" />
            </Form.Group></Col>
          </Row>
        </Modal.Body>
        <Modal.Footer>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={busy}>{busy ? <Spinner size="sm" /> : editing ? 'Save' : 'Create'}</Button>
        </Modal.Footer>
      </Form>
    </Modal>
  )
}

function HistoryModal({ id, onClose }) {
  const [guest, setGuest] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    // setState happens only in the async .then/.catch, so this is a safe effect.
    getGuest(id).then(setGuest).catch(() => setError('Could not load guest history.'))
  }, [id])

  return (
    <Modal show onHide={onClose} centered>
      <Modal.Header closeButton>
        <Modal.Title>{guest ? guest.full_name : 'Guest'} — stay history</Modal.Title>
      </Modal.Header>
      <Modal.Body>
        {error && <Alert variant="danger">{error}</Alert>}
        {!guest && !error && <div className="text-center py-3"><Spinner /></div>}
        {guest && guest.reservations.length === 0 && (
          <p className="text-muted mb-0">No reservations on record.</p>
        )}
        {guest && guest.reservations.length > 0 && (
          <ListGroup variant="flush">
            {guest.reservations.map((r) => (
              <ListGroup.Item key={r.id} className="d-flex justify-content-between">
                <span>
                  Room {r.room?.room_number ?? '—'}
                  <span className="text-muted small ms-2">{r.check_in} → {r.check_out}</span>
                </span>
                <Badge bg="secondary">{r.status.replace('_', ' ')}</Badge>
              </ListGroup.Item>
            ))}
          </ListGroup>
        )}
      </Modal.Body>
      <Modal.Footer>
        <Button variant="secondary" onClick={onClose}>Close</Button>
      </Modal.Footer>
    </Modal>
  )
}
