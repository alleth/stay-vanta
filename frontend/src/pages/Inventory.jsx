import { useCallback, useEffect, useState } from 'react'
import {
  Row, Col, Card, Table, Button, Badge, Modal, Form, Alert, Spinner, ButtonGroup,
} from 'react-bootstrap'
import { useProperty } from '../context/PropertyContext'
import { useSubmit } from '../hooks/useSubmit'
import {
  listCategories, createCategory, listItems, createItem, listMovements, recordMovement,
} from '../api/inventory'

const KINDS = ['food_stock', 'hygiene', 'linen', 'utensil', 'other']

export default function Inventory() {
  const { propertyId } = useProperty()
  const [categories, setCategories] = useState([])
  const [items, setItems] = useState([])
  const [movements, setMovements] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  // Which modal is open and its target.
  const [modal, setModal] = useState(null) // 'category' | 'item' | 'move'
  const [moveTarget, setMoveTarget] = useState(null)

  // All state updates happen after an await (async continuation) so we never
  // call setState synchronously inside the effect.
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
        <div className="d-flex gap-2">
          <Button variant="outline-secondary" onClick={() => setModal('category')}>
            New category
          </Button>
          <Button onClick={() => setModal('item')} disabled={categories.length === 0}>
            New item
          </Button>
        </div>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}

      {loading ? (
        <div className="text-center py-5">
          <Spinner />
        </div>
      ) : (
        <Row className="g-3">
          <Col lg={8}>
            <Card className="shadow-sm">
              <Card.Header className="fw-semibold">Items</Card.Header>
              <Table responsive hover className="mb-0 align-middle">
                <thead>
                  <tr>
                    <th>Item</th>
                    <th>Category</th>
                    <th className="text-end">On hand</th>
                    <th>Last receptionist</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  {items.length === 0 && (
                    <tr>
                      <td colSpan={5} className="text-center text-muted py-4">
                        No items yet.
                      </td>
                    </tr>
                  )}
                  {items.map((it) => {
                    const low = Number(it.quantity) <= Number(it.reorder_level)
                    return (
                      <tr key={it.id}>
                        <td className="fw-semibold">{it.name}</td>
                        <td>{it.inventory_category?.name ?? '—'}</td>
                        <td className="text-end">
                          {Number(it.quantity)} {it.unit}{' '}
                          {low && <Badge bg="warning" text="dark">low</Badge>}
                        </td>
                        <td className="text-muted small">
                          {it.last_receptionist?.name ?? '—'}
                        </td>
                        <td className="text-end">
                          <ButtonGroup size="sm">
                            <Button
                              variant="outline-success"
                              onClick={() => { setMoveTarget({ item: it, direction: 'in' }); setModal('move') }}
                            >
                              In
                            </Button>
                            <Button
                              variant="outline-danger"
                              onClick={() => { setMoveTarget({ item: it, direction: 'out' }); setModal('move') }}
                            >
                              Out
                            </Button>
                          </ButtonGroup>
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
          onClose={() => setModal(null)}
          onSaved={() => { setModal(null); refresh() }}
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

function ItemModal({ propertyId, categories, onClose, onSaved }) {
  const [form, setForm] = useState({
    inventory_category_id: categories[0]?.id ?? '',
    name: '', unit: 'pcs', reorder_level: 0, quantity: 0,
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const { run, busy, err } = useSubmit(async () => {
    await createItem(form, propertyId)
    onSaved()
  })
  return (
    <Modal show onHide={onClose} centered>
      <Form onSubmit={run}>
        <Modal.Header closeButton><Modal.Title>New item</Modal.Title></Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
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
            <Col><Form.Group className="mb-3">
              <Form.Label>Opening qty</Form.Label>
              <Form.Control type="number" min={0} value={form.quantity} onChange={set('quantity')} />
            </Form.Group></Col>
          </Row>
        </Modal.Body>
        <Modal.Footer>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={busy}>{busy ? <Spinner size="sm" /> : 'Create'}</Button>
        </Modal.Footer>
      </Form>
    </Modal>
  )
}

function MoveModal({ propertyId, target, onClose, onSaved }) {
  const { item, direction } = target
  const [quantity, setQuantity] = useState(1)
  const [reason, setReason] = useState('')
  const { run, busy, err } = useSubmit(async () => {
    await recordMovement(
      { inventory_item_id: item.id, direction, quantity: Number(quantity), reason },
      propertyId,
    )
    onSaved()
  })
  return (
    <Modal show onHide={onClose} centered>
      <Form onSubmit={run}>
        <Modal.Header closeButton>
          <Modal.Title>
            Stock {direction === 'in' ? 'in' : 'out'} — {item.name}
          </Modal.Title>
        </Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          <p className="text-muted small">
            On hand: {Number(item.quantity)} {item.unit}. This movement is recorded against you.
          </p>
          <Form.Group className="mb-3">
            <Form.Label>Quantity</Form.Label>
            <Form.Control
              type="number" min={1} value={quantity}
              onChange={(e) => setQuantity(e.target.value)} required autoFocus
            />
          </Form.Group>
          <Form.Group>
            <Form.Label>Reason (optional)</Form.Label>
            <Form.Control value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. minibar restock" />
          </Form.Group>
        </Modal.Body>
        <Modal.Footer>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" variant={direction === 'in' ? 'success' : 'danger'} disabled={busy}>
            {busy ? <Spinner size="sm" /> : `Record ${direction}`}
          </Button>
        </Modal.Footer>
      </Form>
    </Modal>
  )
}
