import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Row, Col, Card, Table, Button, Badge, Modal, Form, Alert, Spinner, ButtonGroup, ToggleButton,
} from 'react-bootstrap'
import { useAuth } from '../context/AuthContext'
import { useProperty } from '../context/PropertyContext'
import { useSubmit } from '../hooks/useSubmit'
import {
  listCategories, createCategory, listItems, createItem, updateItem, deleteItem, listMovements, recordMovement,
} from '../api/inventory'

const KINDS = ['food_stock', 'hygiene', 'linen', 'utensil', 'other']

// Stock actions. Consumables deplete (In/Out); reusables are issued out and
// returned (available moves only), or acquired/retired (owned total moves too).
const CONSUMABLE_ACTIONS = {
  in: { label: 'In', direction: 'in', affects_total: false, variant: 'outline-success', reason: 'restock' },
  out: { label: 'Out', direction: 'out', affects_total: false, variant: 'outline-danger', reason: 'consumed' },
}
const REUSABLE_ACTIONS = {
  issue: { label: 'Issue', direction: 'out', affects_total: false, variant: 'outline-danger', reason: 'issued' },
  return: { label: 'Return', direction: 'in', affects_total: false, variant: 'outline-success', reason: 'returned' },
  acquire: { label: '+ Stock', direction: 'in', affects_total: true, variant: 'outline-primary', reason: 'acquired' },
  retire: { label: '− Stock', direction: 'out', affects_total: true, variant: 'outline-secondary', reason: 'retired' },
}

const trackingOf = (it) => (it.tracking_type === 'reusable' ? 'reusable' : 'consumable')

export default function Inventory() {
  const { role } = useAuth()
  const { propertyId } = useProperty()
  // Receptionists operate the catalogue read-only: no category creation, no
  // manual stock moves, no edits. Stock leaves via Food & Orders instead.
  const canManage = role === 'owner' || role === 'admin'
  const [categories, setCategories] = useState([])
  const [items, setItems] = useState([])
  const [movements, setMovements] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [view, setView] = useState('consumable') // consumable | reusable

  const [modal, setModal] = useState(null) // 'category' | 'item' | 'move'
  const [moveTarget, setMoveTarget] = useState(null)
  const [editTarget, setEditTarget] = useState(null) // item being edited (null = new)

  const refresh = useCallback(async () => {
    if (!propertyId) return
    try {
      const [c, i, m] = await Promise.all([
        listCategories(propertyId),
        listItems(propertyId),
        listMovements(propertyId),
      ])
      setCategories(c)
      setItems(i)
      setMovements(m)
      setError(null)
    } catch {
      setError('Could not load inventory.')
    } finally {
      setLoading(false)
    }
  }, [propertyId])

  useEffect(() => {
    // refresh() only updates state after awaiting the network, but the lint
    // rule can't see through the async boundary. Safe data-loading effect.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    refresh()
  }, [refresh])

  const shown = useMemo(() => items.filter((it) => trackingOf(it) === view), [items, view])
  const reusable = view === 'reusable'

  const openMove = (item, action) => { setMoveTarget({ item, action }); setModal('move') }

  async function doDeleteItem(item) {
    if (!window.confirm(`Delete "${item.name}"? This removes the item and its stock history.`)) return
    setError(null)
    try {
      await deleteItem(item.id)
      refresh()
    } catch (ex) {
      setError(ex?.response?.data?.message ?? 'Could not delete the item.')
    }
  }

  if (!propertyId)
    return <Alert variant="info">Select or create a property to manage inventory.</Alert>

  return (
    <div>
      <div className="d-flex justify-content-between align-items-center mb-3">
        <div>
          <h1 className="h3 fw-bold mb-0">Inventory</h1>
          <small className="text-muted">
            Every in/out movement records the acting receptionist.
          </small>
        </div>
        {canManage && (
          <div className="d-flex gap-2">
            <Button variant="outline-secondary" onClick={() => setModal('category')}>
              New category
            </Button>
            <Button onClick={() => { setEditTarget(null); setModal('item') }} disabled={categories.length === 0}>
              New item
            </Button>
          </div>
        )}
      </div>

      {error && <Alert variant="danger">{error}</Alert>}

      <ButtonGroup className="mb-3">
        {[['consumable', 'Consumables'], ['reusable', 'Reusables']].map(([val, label]) => (
          <ToggleButton
            key={val} id={`view-${val}`} type="radio" name="inv-view"
            value={val} checked={view === val} variant="outline-primary"
            onChange={(e) => setView(e.currentTarget.value)}
          >
            {label}
          </ToggleButton>
        ))}
      </ButtonGroup>

      {loading ? (
        <div className="text-center py-5">
          <Spinner />
        </div>
      ) : (
        <Row className="g-3">
          <Col lg={8}>
            <Card className="shadow-sm">
              <Card.Header className="fw-semibold">
                {reusable ? 'Reusable items (issued & returned)' : 'Consumable items'}
              </Card.Header>
              <Table responsive hover className="mb-0 align-middle">
                <thead>
                  <tr>
                    <th>Item</th>
                    <th>Category</th>
                    {reusable ? (
                      <>
                        <th className="text-end">Available</th>
                        <th className="text-end">In use</th>
                        <th className="text-end">Total</th>
                      </>
                    ) : (
                      <th className="text-end">On hand</th>
                    )}
                    <th>Last receptionist</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  {shown.length === 0 && (
                    <tr>
                      <td colSpan={reusable ? 7 : 5} className="text-center text-muted py-4">
                        No {reusable ? 'reusable' : 'consumable'} items yet.
                      </td>
                    </tr>
                  )}
                  {shown.map((it) => {
                    const available = Number(it.quantity)
                    const total = Number(it.total_quantity ?? 0)
                    const inUse = Math.max(0, total - available)
                    const low = available <= Number(it.reorder_level)
                    return (
                      <tr key={it.id}>
                        <td className="fw-semibold">{it.name}</td>
                        <td>{it.inventory_category?.name ?? '—'}</td>
                        {reusable ? (
                          <>
                            <td className="text-end">
                              {available} {low && <Badge bg="warning" text="dark">low</Badge>}
                            </td>
                            <td className="text-end">{inUse}</td>
                            <td className="text-end">{total}</td>
                          </>
                        ) : (
                          <td className="text-end">
                            {available} {it.unit}{' '}
                            {low && <Badge bg="warning" text="dark">low</Badge>}
                          </td>
                        )}
                        <td className="text-muted small">
                          {it.last_receptionist?.name ?? '—'}
                        </td>
                        <td className="text-end text-nowrap">
                          {canManage && (
                            <Button size="sm" variant="outline-primary" className="me-1"
                              onClick={() => { setEditTarget(it); setModal('item') }}>Edit</Button>
                          )}
                          {canManage && (
                            <Button size="sm" variant="outline-danger" className="me-1"
                              onClick={() => doDeleteItem(it)}>Delete</Button>
                          )}
                          {canManage && (reusable ? (
                            <ButtonGroup size="sm">
                              {Object.entries(REUSABLE_ACTIONS).map(([key, a]) => (
                                <Button key={key} variant={a.variant} onClick={() => openMove(it, key)}>
                                  {a.label}
                                </Button>
                              ))}
                            </ButtonGroup>
                          ) : (
                            <ButtonGroup size="sm">
                              <Button variant="outline-success" onClick={() => openMove(it, 'in')}>In</Button>
                              <Button variant="outline-danger" onClick={() => openMove(it, 'out')}>Out</Button>
                            </ButtonGroup>
                          ))}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </Table>
            </Card>
          </Col>

          <Col lg={4}>
            <Card className="shadow-sm">
              <Card.Header className="fw-semibold">Recent movements</Card.Header>
              <Card.Body className="p-0" style={{ maxHeight: 460, overflowY: 'auto' }}>
                {movements.length === 0 ? (
                  <p className="text-muted small p-3 mb-0">No movements yet.</p>
                ) : (
                  <ul className="list-group list-group-flush">
                    {movements.map((m) => (
                      <li key={m.id} className="list-group-item">
                        <div className="d-flex justify-content-between">
                          <span className="fw-semibold">{m.inventory_item?.name}</span>
                          <Badge bg={m.direction === 'in' ? 'success' : 'danger'}>
                            {m.direction === 'in' ? '+' : '−'}{Number(m.quantity)}
                          </Badge>
                        </div>
                        <div className="small text-muted">
                          {m.receptionist?.name} · {m.reason ?? 'movement'}
                        </div>
                      </li>
                    ))}
                  </ul>
                )}
              </Card.Body>
            </Card>
          </Col>
        </Row>
      )}

      {modal === 'category' && (
        <CategoryModal
          propertyId={propertyId}
          onClose={() => setModal(null)}
          onSaved={() => { setModal(null); refresh() }}
        />
      )}
      {modal === 'item' && (
        <ItemModal
          propertyId={propertyId}
          categories={categories}
          item={editTarget}
          defaultTracking={view}
          onClose={() => { setModal(null); setEditTarget(null) }}
          onSaved={() => { setModal(null); setEditTarget(null); refresh() }}
        />
      )}
      {modal === 'move' && moveTarget && (
        <MoveModal
          propertyId={propertyId}
          target={moveTarget}
          onClose={() => setModal(null)}
          onSaved={() => { setModal(null); refresh() }}
        />
      )}
    </div>
  )
}

function CategoryModal({ propertyId, onClose, onSaved }) {
  const [name, setName] = useState('')
  const [kind, setKind] = useState('food_stock')
  const { run, busy, err } = useSubmit(async () => {
    await createCategory({ name, kind }, propertyId)
    onSaved()
  })
  return (
    <Modal show onHide={onClose} centered>
      <Form onSubmit={run}>
        <Modal.Header closeButton><Modal.Title>New category</Modal.Title></Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          <Form.Group className="mb-3">
            <Form.Label>Name</Form.Label>
            <Form.Control value={name} onChange={(e) => setName(e.target.value)} required autoFocus />
          </Form.Group>
          <Form.Group>
            <Form.Label>Kind</Form.Label>
            <Form.Select value={kind} onChange={(e) => setKind(e.target.value)}>
              {KINDS.map((k) => <option key={k} value={k}>{k.replace('_', ' ')}</option>)}
            </Form.Select>
          </Form.Group>
        </Modal.Body>
        <Modal.Footer>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={busy}>{busy ? <Spinner size="sm" /> : 'Create'}</Button>
        </Modal.Footer>
      </Form>
    </Modal>
  )
}

function ItemModal({ propertyId, categories, item, defaultTracking, onClose, onSaved }) {
  const editing = Boolean(item)
  const [form, setForm] = useState({
    inventory_category_id: item?.inventory_category_id ?? categories[0]?.id ?? '',
    name: item?.name ?? '',
    tracking_type: item?.tracking_type ?? defaultTracking ?? 'consumable',
    unit: item?.unit ?? 'pcs',
    reorder_level: item?.reorder_level ?? 0,
    quantity: 0,
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const reusable = form.tracking_type === 'reusable'
  const typeChanged = editing && form.tracking_type !== item.tracking_type
  const { run, busy, err } = useSubmit(async () => {
    if (editing) {
      const patch = { ...form }
      delete patch.quantity // never edit quantity directly
      await updateItem(item.id, patch)
    } else {
      await createItem(form, propertyId)
    }
    onSaved()
  })
  return (
    <Modal show onHide={onClose} centered>
      <Form onSubmit={run}>
        <Modal.Header closeButton><Modal.Title>{editing ? 'Edit item' : 'New item'}</Modal.Title></Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          <Form.Group className="mb-3">
            <Form.Label>Type</Form.Label>
            <Form.Select value={form.tracking_type} onChange={set('tracking_type')}>
              <option value="consumable">Consumable (depletes when used)</option>
              <option value="reusable">Reusable (issued out & returned)</option>
            </Form.Select>
            {typeChanged && (
              <Form.Text className="text-warning">
                {reusable
                  ? 'Switching to reusable: current on-hand becomes the owned total.'
                  : 'Switching to consumable: the owned-total tracking is dropped.'}
              </Form.Text>
            )}
          </Form.Group>
          <Form.Group className="mb-3">
            <Form.Label>Category</Form.Label>
            <Form.Select value={form.inventory_category_id} onChange={set('inventory_category_id')} required>
              {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Form.Select>
          </Form.Group>
          <Form.Group className="mb-3">
            <Form.Label>Name</Form.Label>
            <Form.Control value={form.name} onChange={set('name')} required autoFocus />
          </Form.Group>
          <Row>
            <Col><Form.Group className="mb-3">
              <Form.Label>Unit</Form.Label>
              <Form.Control value={form.unit} onChange={set('unit')} />
            </Form.Group></Col>
            <Col><Form.Group className="mb-3">
              <Form.Label>Reorder level</Form.Label>
              <Form.Control type="number" min={0} value={form.reorder_level} onChange={set('reorder_level')} />
            </Form.Group></Col>
            {!editing && (
              <Col><Form.Group className="mb-3">
                <Form.Label>{reusable ? 'Units owned' : 'Opening qty'}</Form.Label>
                <Form.Control type="number" min={0} value={form.quantity} onChange={set('quantity')} />
              </Form.Group></Col>
            )}
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

function MoveModal({ propertyId, target, onClose, onSaved }) {
  const { item, action } = target
  const reusable = trackingOf(item) === 'reusable'
  const a = (reusable ? REUSABLE_ACTIONS : CONSUMABLE_ACTIONS)[action]
  const [quantity, setQuantity] = useState(1)
  const [reason, setReason] = useState('')
  const available = Number(item.quantity)
  const total = Number(item.total_quantity ?? 0)
  const inUse = Math.max(0, total - available)
  const { run, busy, err } = useSubmit(async () => {
    await recordMovement(
      {
        inventory_item_id: item.id,
        direction: a.direction,
        quantity: Number(quantity),
        affects_total: a.affects_total,
        reason: reason || a.reason,
      },
      propertyId,
    )
    onSaved()
  })
  return (
    <Modal show onHide={onClose} centered>
      <Form onSubmit={run}>
        <Modal.Header closeButton>
          <Modal.Title>{a.label} — {item.name}</Modal.Title>
        </Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          <p className="text-muted small">
            {reusable
              ? `Available: ${available} · In use: ${inUse} · Total owned: ${total}.`
              : `On hand: ${available} ${item.unit}.`}{' '}
            This movement is recorded against you.
          </p>
          <Form.Group className="mb-3">
            <Form.Label>Quantity</Form.Label>
            <Form.Control
              type="number" min={1} value={quantity}
              onChange={(e) => setQuantity(e.target.value)} required autoFocus
            />
          </Form.Group>
          <Form.Group>
            <Form.Label>
              {reusable && (action === 'issue' || action === 'return') ? 'Room / note (optional)' : 'Reason (optional)'}
            </Form.Label>
            <Form.Control
              value={reason} onChange={(e) => setReason(e.target.value)}
              placeholder={reusable ? 'e.g. Room 203' : 'e.g. minibar restock'}
            />
          </Form.Group>
        </Modal.Body>
        <Modal.Footer>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" variant={a.direction === 'in' ? 'success' : 'danger'} disabled={busy}>
            {busy ? <Spinner size="sm" /> : a.label}
          </Button>
        </Modal.Footer>
      </Form>
    </Modal>
  )
}
