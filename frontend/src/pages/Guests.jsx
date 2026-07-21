import { useCallback, useEffect, useState } from 'react'
import {
  Card, Table, Button, Badge, Modal, Form, InputGroup, Alert, Spinner, ListGroup, Pagination,
} from '../components/ui'
import { useProperty } from '../context/PropertyContext'
import { listGuestsPage, guestStats, getGuest, createGuest, updateGuest, matchGuests } from '../api/guests'
import { SkeletonTable, SkeletonTableRows, Skeleton } from '../components/Skeleton'

const TYPE_VARIANT = { local: 'info', foreign: 'warning' }
// Stat-card number tint per card variant.
const VALUE_COLOR = {
  dark: 'text-gray-900',
  info: 'text-sky-600',
  warning: 'text-amber-600',
  success: 'text-emerald-600',
}

const GUESTS_PER_PAGE = 20

export default function Guests() {
  const { propertyId } = useProperty()
  const [stats, setStats] = useState({ total: 0, local: 0, foreign: 0, inHouse: 0 })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  // The registry can grow large, so search/type filtering and pagination are
  // server-side (GuestsController::index) rather than over a fully-loaded list.
  const [guests, setGuests] = useState([])
  const [total, setTotal] = useState(0)
  const [listLoading, setListLoading] = useState(false)
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('') // raw input, debounced below
  const [q, setQ] = useState('') // debounced value actually sent to the server
  const [typeFilter, setTypeFilter] = useState('all')
  const [modal, setModal] = useState(null) // 'add' | { type:'edit', guest } | { type:'view', id }

  // Debounce the search box so every keystroke doesn't fire a request; jump
  // back to page 1 whenever the effective search text changes.
  useEffect(() => {
    const t = setTimeout(() => { setQ(search.trim()); setPage(1) }, 300)
    return () => clearTimeout(t)
  }, [search])

  const loadStats = useCallback(async () => {
    if (!propertyId) return
    try {
      setStats(await guestStats(propertyId))
      setError(null)
    } catch {
      setError('Could not load guests.')
    } finally {
      setLoading(false)
    }
  }, [propertyId])

  const loadGuests = useCallback(async () => {
    if (!propertyId) return
    setListLoading(true)
    try {
      const params = { page, limit: GUESTS_PER_PAGE }
      if (typeFilter !== 'all') params.guest_type = typeFilter
      if (q) params.q = q
      const data = await listGuestsPage(propertyId, params)
      setGuests(data.guests ?? [])
      setTotal(data.total ?? 0)
      setError(null)
    } catch {
      setError('Could not load guests.')
    } finally {
      setListLoading(false)
    }
  }, [propertyId, page, typeFilter, q])

  // eslint-disable-next-line react-hooks/set-state-in-effect
  useEffect(() => { loadStats() }, [loadStats])
  // eslint-disable-next-line react-hooks/set-state-in-effect
  useEffect(() => { loadGuests() }, [loadGuests])

  function refresh() {
    loadStats()
    loadGuests()
  }

  const totalPages = Math.max(1, Math.ceil(total / GUESTS_PER_PAGE))

  if (!propertyId)
    return <Alert variant="info">Select or create a property to view guests.</Alert>

  return (
    <div>
      <div className="mb-4 flex items-center justify-between">
        <h1 className="mb-0 text-2xl font-bold">Guests</h1>
        <Button onClick={() => setModal('add')}>Add guest</Button>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}

      {/* total/local/foreign are today's registrations — they reset to 0 each day. */}
      <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard label="Total guests today" value={stats.total} variant="dark" />
        <StatCard label="Local today" value={stats.local} variant="info" />
        <StatCard label="Foreign today" value={stats.foreign} variant="warning" />
        <StatCard label="Currently in-house" value={stats.inHouse} variant="success" />
      </div>

      <Card className="shadow-sm">
        <Card.Header className="flex flex-wrap items-center gap-2 px-4 py-3">
          <InputGroup style={{ maxWidth: 280 }}>
            <InputGroup.Text>Search</InputGroup.Text>
            <Form.Control value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Guest name" />
          </InputGroup>
          <Form.Select style={{ maxWidth: 180 }} value={typeFilter}
            onChange={(e) => { setTypeFilter(e.target.value); setPage(1) }}>
            <option value="all">All types</option>
            <option value="local">Local</option>
            <option value="foreign">Foreign</option>
          </Form.Select>
          <span className="ml-auto text-sm font-normal text-muted">{total} guest(s)</span>
        </Card.Header>

        {loading ? (
          <SkeletonTable rows={6} />
        ) : (
          <Table hover>
            <thead>
              <tr>
                <th>Name</th><th>Type</th><th>Nationality</th><th>Contact</th>
                <th className="text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {listLoading && <SkeletonTableRows rows={5} cols={5} />}
              {!listLoading && guests.length === 0 && (
                <tr><td colSpan={5} className="py-6 text-center text-muted">No guests found.</td></tr>
              )}
              {!listLoading && guests.map((g) => (
                <tr key={g.id}>
                  <td className="font-semibold">{g.full_name}</td>
                  <td><Badge bg={TYPE_VARIANT[g.guest_type]}>{g.guest_type}</Badge></td>
                  <td>{g.nationality ?? '—'}</td>
                  <td className="text-xs">
                    {g.contact_number || g.email ? (
                      <>
                        {g.contact_number && <div>{g.contact_number}</div>}
                        {g.email && <div className="text-muted">{g.email}</div>}
                      </>
                    ) : '—'}
                  </td>
                  <td className="whitespace-nowrap text-right">
                    <Button size="sm" variant="outline-secondary" className="mr-1"
                      onClick={() => setModal({ type: 'view', id: g.id })}>History</Button>
                    <Button size="sm" variant="outline-primary"
                      onClick={() => setModal({ type: 'edit', guest: g })}>Edit</Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
        {!loading && totalPages > 1 && (
          <Card.Footer className="flex items-center justify-between px-4 py-3">
            <span className="text-sm text-muted">Page {page} of {totalPages} · {total} guest(s)</span>
            <Pagination>
              <Pagination.Prev disabled={page <= 1 || listLoading}
                onClick={() => setPage((p) => Math.max(1, p - 1))} />
              <Pagination.Next disabled={page >= totalPages || listLoading}
                onClick={() => setPage((p) => Math.min(totalPages, p + 1))} />
            </Pagination>
          </Card.Footer>
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
    <Card className="h-full shadow-sm">
      <Card.Body>
        <div className="text-sm text-muted">{label}</div>
        <div className={`text-3xl font-bold ${VALUE_COLOR[variant] ?? ''}`}>{value}</div>
      </Card.Body>
    </Card>
  )
}

function GuestModal({ guest, propertyId, onClose, onSaved }) {
  const editing = Boolean(guest)
  const [form, setForm] = useState({
    full_name: guest?.full_name ?? '',
    guest_type: guest?.guest_type ?? 'local',
    nationality: guest?.nationality ?? '',
    address: guest?.address ?? '',
    contact_number: guest?.contact_number ?? '',
    email: guest?.email ?? '',
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)
  const [duplicates, setDuplicates] = useState(null) // null = not checked; [] = none

  async function save(force) {
    setBusy(true)
    setErr(null)
    try {
      if (editing) {
        await updateGuest(guest.id, form)
      } else {
        if (!force) {
          // Warn before creating a look-alike guest (same name + email/contact).
          const matches = await matchGuests(form, propertyId)
          if (matches.length) { setDuplicates(matches); setBusy(false); return }
        }
        await createGuest({ ...form, force }, propertyId)
      }
      onSaved()
    } catch (ex) {
      setErr(ex?.response?.data?.message ?? 'Save failed. Check the fields and try again.')
      setBusy(false)
    }
  }

  return (
    <Modal show onHide={onClose} centered>
      <Form onSubmit={(e) => { e.preventDefault(); save(false) }}>
        <Modal.Header closeButton><Modal.Title>{editing ? 'Edit guest' : 'Add guest'}</Modal.Title></Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}

          {duplicates?.length > 0 && (
            <Alert variant="warning">
              <div className="mb-1 font-semibold">A matching guest already exists</div>
              <ListGroup className="mb-2">
                {duplicates.map((d) => (
                  <ListGroup.Item key={d.id} className="bg-transparent px-0 py-1">
                    {d.full_name}
                    <span className="ml-2 text-xs text-muted">
                      {[d.contact_number, d.email].filter(Boolean).join(' · ')}
                    </span>
                  </ListGroup.Item>
                ))}
              </ListGroup>
              <div className="mb-2 text-xs">Reuse the existing guest, or create a separate record anyway.</div>
              <Button size="sm" variant="outline-secondary" className="mr-2" onClick={onClose}>
                Keep existing
              </Button>
              <Button size="sm" variant="warning" disabled={busy} onClick={() => save(true)}>
                Create new anyway
              </Button>
            </Alert>
          )}

          <Form.Group className="mb-4">
            <Form.Label>Full name</Form.Label>
            <Form.Control value={form.full_name} onChange={set('full_name')} required autoFocus />
          </Form.Group>
          <div className="grid grid-cols-2 gap-x-6">
            <Form.Group className="mb-4">
              <Form.Label>Type</Form.Label>
              <Form.Select value={form.guest_type} onChange={set('guest_type')}>
                <option value="local">Local</option>
                <option value="foreign">Foreign</option>
              </Form.Select>
            </Form.Group>
            <Form.Group className="mb-4">
              <Form.Label>Nationality</Form.Label>
              <Form.Control value={form.nationality} onChange={set('nationality')} placeholder="Optional" />
            </Form.Group>
          </div>
          <div className="grid grid-cols-1 gap-x-6 md:grid-cols-2">
            <Form.Group className="mb-4">
              <Form.Label>Contact number</Form.Label>
              <Form.Control value={form.contact_number} onChange={set('contact_number')} placeholder="Optional" />
            </Form.Group>
            <Form.Group className="mb-4">
              <Form.Label>Email</Form.Label>
              <Form.Control type="email" value={form.email} onChange={set('email')} placeholder="Optional" />
            </Form.Group>
          </div>
          <Form.Group>
            <Form.Label>Address</Form.Label>
            <Form.Control value={form.address} onChange={set('address')} placeholder="Optional" />
          </Form.Group>
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
        {!guest && !error && (
          <div className="space-y-2">
            <Skeleton className="h-5 w-3/4" />
            <Skeleton className="h-5 w-full" />
            <Skeleton className="h-5 w-2/3" />
          </div>
        )}
        {guest && guest.reservations.length === 0 && (
          <p className="mb-0 text-muted">No reservations on record.</p>
        )}
        {guest && guest.reservations.length > 0 && (
          <ListGroup>
            {guest.reservations.map((r) => (
              <ListGroup.Item key={r.id} className="flex justify-between px-0 py-3">
                <span>
                  Room {r.room?.room_number ?? '—'}
                  <span className="ml-2 text-xs text-muted">{r.check_in} → {r.check_out}</span>
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
