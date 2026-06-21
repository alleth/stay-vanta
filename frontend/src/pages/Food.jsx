import { Fragment, useCallback, useEffect, useMemo, useState } from 'react'
import {
  Tab, Tabs, Card, Table, Button, Badge, Modal, Form, Row, Col, Alert, Spinner, InputGroup,
} from 'react-bootstrap'
import { useAuth } from '../context/AuthContext'
import { useProperty } from '../context/PropertyContext'
import { useSubmit } from '../hooks/useSubmit'
import { formatMoney } from '../utils/format'
import {
  listMenu, createMenuItem, updateMenuItem, deleteMenuItem,
  listOrders, createOrder, serveOrder, cancelOrder,
  listInvoices, settleInvoice,
} from '../api/food'
import { listItems } from '../api/inventory'
import { listGuests } from '../api/guests'

const PAY_VARIANT = { paid: 'success', charge_to_room: 'warning', unpaid: 'secondary' }
const ORDER_VARIANT = { open: 'primary', served: 'success', cancelled: 'dark' }

export default function Food() {
  const { role } = useAuth()
  const { propertyId } = useProperty()
  const canManageMenu = role === 'owner' || role === 'admin'

  const [menu, setMenu] = useState([])
  const [orders, setOrders] = useState([])
  const [invoices, setInvoices] = useState([])
  const [inventory, setInventory] = useState([])
  const [guests, setGuests] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [pending, setPending] = useState(null) // key of the in-flight inline action
  const [modal, setModal] = useState(null) // 'order' | 'menu' | { type:'menu', item }

  const refresh = useCallback(async () => {
    if (!propertyId) return
    try {
      const [m, o, inv, items, g] = await Promise.all([
        listMenu(propertyId), listOrders(propertyId), listInvoices(propertyId),
        listItems(propertyId), listGuests(propertyId),
      ])
      setMenu(m)
      setOrders(o)
      setInvoices(inv)
      setInventory(items)
      setGuests(g)
      setError(null)
    } catch {
      setError('Could not load food data.')
    } finally {
      setLoading(false)
    }
  }, [propertyId])

  useEffect(() => {
    // State updates run after the awaited fetch; safe data effect.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    refresh()
  }, [refresh])

  // `key` identifies the in-flight button so we can show a spinner on it and
  // disable the others while the request is running.
  async function act(key, fn, ...args) {
    setPending(key)
    setError(null)
    try {
      await fn(...args)
      await refresh()
    } catch (ex) {
      setError(ex?.response?.data?.message ?? 'Action failed.')
    } finally {
      setPending(null)
    }
  }

  // Group the menu by its linked stock's category (prepared items that aren't
  // linked to stock fall under "Unlinked / prepared").
  const menuGroups = useMemo(() => {
    const groups = new Map()
    for (const m of menu) {
      const cat = m.inventory_item?.inventory_category?.name ?? 'Unlinked / prepared'
      if (!groups.has(cat)) groups.set(cat, [])
      groups.get(cat).push(m)
    }
    return [...groups.entries()].sort((a, b) => a[0].localeCompare(b[0]))
  }, [menu])

  if (!propertyId)
    return <Alert variant="info">Select or create a property to use Food &amp; Orders.</Alert>

  return (
    <div>
      <h1 className="h3 fw-bold mb-3">Food &amp; Orders</h1>
      {error && <Alert variant="danger" dismissible onClose={() => setError(null)}>{error}</Alert>}

      {loading ? (
        <div className="text-center py-5"><Spinner /></div>
      ) : (
        <Tabs defaultActiveKey="orders" className="mb-3">
          {/* ---- Orders ---- */}
          <Tab eventKey="orders" title={`Orders (${orders.length})`}>
            <div className="d-flex justify-content-end mb-2">
              <Button onClick={() => setModal('order')} disabled={menu.length === 0}>New order</Button>
            </div>
            <Card className="shadow-sm">
              <Table responsive hover className="mb-0 align-middle">
                <thead>
                  <tr>
                    <th>#</th><th>Items</th><th>Guest</th><th className="text-end">Total</th>
                    <th>Payment</th><th>Status</th><th>Receptionist</th><th className="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {orders.length === 0 && (
                    <tr><td colSpan={8} className="text-center text-muted py-4">No orders yet.</td></tr>
                  )}
                  {orders.map((o) => (
                    <tr key={o.id}>
                      <td>{o.id}</td>
                      <td className="small">
                        {o.food_order_items?.map((it) =>
                          `${it.quantity}× ${it.food_menu_item?.name ?? 'item'}`).join(', ')}
                      </td>
                      <td>{o.guest?.full_name ?? '—'}{o.room ? ` (Rm ${o.room.room_number})` : ''}</td>
                      <td className="text-end">{formatMoney(o.total)}</td>
                      <td><Badge bg={PAY_VARIANT[o.payment_status]}>{o.payment_status.replace('_', ' ')}</Badge></td>
                      <td><Badge bg={ORDER_VARIANT[o.status]}>{o.status}</Badge></td>
                      <td className="small text-muted">{o.receptionist?.name ?? '—'}</td>
                      <td className="text-end text-nowrap">
                        {o.status === 'open' && (
                          <Button size="sm" variant="outline-success" className="me-1"
                            disabled={pending !== null}
                            onClick={() => act(`serve-${o.id}`, serveOrder, o.id)}>
                            {pending === `serve-${o.id}` ? <Spinner size="sm" /> : 'Serve'}
                          </Button>
                        )}
                        {o.status !== 'cancelled'
                          && !(role === 'receptionist' && o.status === 'served' && o.payment_status === 'paid') && (
                          <Button size="sm" variant="outline-danger"
                            disabled={pending !== null}
                            onClick={() => act(`cancel-${o.id}`, cancelOrder, o.id)}>
                            {pending === `cancel-${o.id}` ? <Spinner size="sm" /> : 'Cancel'}
                          </Button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            </Card>
          </Tab>

          {/* ---- Menu ---- */}
          <Tab eventKey="menu" title={`Menu (${menu.length})`}>
            {canManageMenu && (
              <div className="d-flex justify-content-end mb-2">
                <Button onClick={() => setModal('menu')}>Add menu item</Button>
              </div>
            )}
            <Card className="shadow-sm">
              <Table responsive hover className="mb-0 align-middle">
                <thead>
                  <tr>
                    <th>Item</th><th className="text-end">Price</th><th>Linked stock</th>
                    <th>Available</th>{canManageMenu && <th></th>}
                  </tr>
                </thead>
                <tbody>
                  {menu.length === 0 && (
                    <tr><td colSpan={canManageMenu ? 5 : 4} className="text-center text-muted py-4">No menu items.</td></tr>
                  )}
                  {menuGroups.map(([category, items]) => (
                    <Fragment key={category}>
                      <tr className="table-light">
                        <td colSpan={canManageMenu ? 5 : 4} className="fw-semibold small text-uppercase text-muted">
                          {category}
                        </td>
                      </tr>
                      {items.map((m) => (
                        <tr key={m.id}>
                          <td className="fw-semibold">{m.name}</td>
                          <td className="text-end">{formatMoney(m.price)}</td>
                          <td className="small">{m.inventory_item?.name ?? <span className="text-muted">—</span>}</td>
                          <td>
                            {m.is_available
                              ? <Badge bg="success">yes</Badge>
                              : <Badge bg="secondary">no</Badge>}
                          </td>
                          {canManageMenu && (
                            <td className="text-end text-nowrap">
                              <Button size="sm" variant="outline-primary" className="me-1"
                                disabled={pending !== null}
                                onClick={() => setModal({ type: 'menu', item: m })}>Edit</Button>
                              <Button size="sm" variant="outline-danger"
                                disabled={pending !== null}
                                onClick={() => { if (window.confirm(`Delete "${m.name}"?`)) act(`del-menu-${m.id}`, deleteMenuItem, m.id) }}>
                                {pending === `del-menu-${m.id}` ? <Spinner size="sm" /> : 'Delete'}
                              </Button>
                            </td>
                          )}
                        </tr>
                      ))}
                    </Fragment>
                  ))}
                </tbody>
              </Table>
            </Card>
          </Tab>

          {/* ---- Invoices ---- */}
          <Tab eventKey="invoices" title={`Invoices (${invoices.length})`}>
            <Card className="shadow-sm">
              <Table responsive hover className="mb-0 align-middle">
                <thead>
                  <tr><th>Guest</th><th className="text-end">Total</th><th>Status</th><th className="text-end">Actions</th></tr>
                </thead>
                <tbody>
                  {invoices.length === 0 && (
                    <tr><td colSpan={4} className="text-center text-muted py-4">No invoices.</td></tr>
                  )}
                  {invoices.map((inv) => (
                    <tr key={inv.id}>
                      <td className="fw-semibold">{inv.guest?.full_name ?? '—'}</td>
                      <td className="text-end">{formatMoney(inv.total)}</td>
                      <td><Badge bg={inv.status === 'open' ? 'warning' : 'success'}>{inv.status}</Badge></td>
                      <td className="text-end">
                        {inv.status === 'open' && (
                          <Button size="sm" variant="outline-success"
                            disabled={pending !== null}
                            onClick={() => act(`settle-${inv.id}`, settleInvoice, inv.id)}>
                            {pending === `settle-${inv.id}` ? <Spinner size="sm" /> : 'Settle'}
                          </Button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            </Card>
          </Tab>
        </Tabs>
      )}

      {modal === 'order' && (
        <OrderModal menu={menu.filter((m) => m.is_available)} guests={guests} propertyId={propertyId}
          onClose={() => setModal(null)} onSaved={() => { setModal(null); refresh() }} />
      )}
      {(modal === 'menu' || modal?.type === 'menu') && (
        <MenuModal item={modal?.item} inventory={inventory} propertyId={propertyId}
          onClose={() => setModal(null)} onSaved={() => { setModal(null); refresh() }} />
      )}
    </div>
  )
}

function OrderModal({ menu, guests, propertyId, onClose, onSaved }) {
  const [cart, setCart] = useState({}) // { menuId: qty }
  const [payment, setPayment] = useState('paid')
  const [guestId, setGuestId] = useState('')

  const setQty = (id, qty) => setCart((c) => ({ ...c, [id]: Math.max(0, qty) }))

  const lines = useMemo(
    () => menu.filter((m) => (cart[m.id] ?? 0) > 0).map((m) => ({ menu: m, qty: cart[m.id] })),
    [menu, cart],
  )
  const total = lines.reduce((sum, l) => sum + Number(l.menu.price) * l.qty, 0)

  const { run, busy, err } = useSubmit(async () => {
    const payload = {
      items: lines.map((l) => ({ food_menu_item_id: l.menu.id, quantity: l.qty })),
      payment_status: payment,
    }
    if (payment === 'charge_to_room') payload.guest_id = Number(guestId)
    else if (guestId) payload.guest_id = Number(guestId)
    await createOrder(payload, propertyId)
    onSaved()
  })

  const needGuest = payment === 'charge_to_room'

  return (
    <Modal show onHide={onClose} centered size="lg">
      <Form onSubmit={run}>
        <Modal.Header closeButton><Modal.Title>New order</Modal.Title></Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          <p className="text-muted small">Set quantities, then choose how it's paid.</p>
          <div style={{ maxHeight: 260, overflowY: 'auto' }} className="border rounded mb-3">
            <Table size="sm" className="mb-0 align-middle">
              <tbody>
                {menu.map((m) => (
                  <tr key={m.id}>
                    <td className="fw-semibold">{m.name}</td>
                    <td className="text-muted">{formatMoney(m.price)}</td>
                    <td style={{ width: 130 }}>
                      <InputGroup size="sm">
                        <Button variant="outline-secondary"
                          onClick={() => setQty(m.id, (cart[m.id] ?? 0) - 1)}>−</Button>
                        <Form.Control className="text-center" value={cart[m.id] ?? 0}
                          onChange={(e) => setQty(m.id, parseInt(e.target.value, 10) || 0)} />
                        <Button variant="outline-secondary"
                          onClick={() => setQty(m.id, (cart[m.id] ?? 0) + 1)}>+</Button>
                      </InputGroup>
                    </td>
                  </tr>
                ))}
              </tbody>
            </Table>
          </div>
          <Row className="align-items-end">
            <Col md={4}><Form.Group>
              <Form.Label>Payment</Form.Label>
              <Form.Select value={payment} onChange={(e) => setPayment(e.target.value)}>
                <option value="paid">Paid now</option>
                <option value="charge_to_room">Charge to room</option>
                <option value="unpaid">Unpaid (pay later)</option>
              </Form.Select>
            </Form.Group></Col>
            <Col md={5}><Form.Group>
              <Form.Label>Guest {needGuest && <span className="text-danger">*</span>}</Form.Label>
              <Form.Select value={guestId} onChange={(e) => setGuestId(e.target.value)} required={needGuest}>
                <option value="">{needGuest ? 'Select a guest…' : 'None'}</option>
                {guests.map((g) => <option key={g.id} value={g.id}>{g.full_name}</option>)}
              </Form.Select>
            </Form.Group></Col>
            <Col md={3} className="text-end">
              <div className="text-muted small">Total</div>
              <div className="fs-4 fw-bold">{formatMoney(total)}</div>
            </Col>
          </Row>
        </Modal.Body>
        <Modal.Footer>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={busy || lines.length === 0}>
            {busy ? <Spinner size="sm" /> : 'Place order'}
          </Button>
        </Modal.Footer>
      </Form>
    </Modal>
  )
}

function MenuModal({ item, inventory, propertyId, onClose, onSaved }) {
  const editing = Boolean(item)
  const [form, setForm] = useState({
    name: item?.name ?? '',
    price: item?.price ?? '',
    inventory_item_id: item?.inventory_item_id ?? '',
    is_available: item?.is_available ?? true,
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const { run, busy, err } = useSubmit(async () => {
    const payload = { ...form, inventory_item_id: form.inventory_item_id || null }
    if (editing) await updateMenuItem(item.id, payload)
    else await createMenuItem(payload, propertyId)
    onSaved()
  })

  return (
    <Modal show onHide={onClose} centered>
      <Form onSubmit={run}>
        <Modal.Header closeButton><Modal.Title>{editing ? 'Edit menu item' : 'Add menu item'}</Modal.Title></Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          <Form.Group className="mb-3">
            <Form.Label>Name</Form.Label>
            <Form.Control value={form.name} onChange={set('name')} required autoFocus />
          </Form.Group>
          <Row>
            <Col><Form.Group className="mb-3">
              <Form.Label>Price</Form.Label>
              <Form.Control type="number" min={0} step="0.01" value={form.price} onChange={set('price')} required />
            </Form.Group></Col>
            <Col><Form.Group className="mb-3">
              <Form.Label>Availability</Form.Label>
              <Form.Select value={form.is_available ? '1' : '0'}
                onChange={(e) => setForm({ ...form, is_available: e.target.value === '1' })}>
                <option value="1">Available</option>
                <option value="0">Unavailable</option>
              </Form.Select>
            </Form.Group></Col>
          </Row>
          <Form.Group>
            <Form.Label>Linked food stock (decrements on order)</Form.Label>
            <Form.Select value={form.inventory_item_id} onChange={set('inventory_item_id')}>
              <option value="">Not linked</option>
              {inventory.map((i) => (
                <option key={i.id} value={i.id}>{i.name} ({Number(i.quantity)} {i.unit})</option>
              ))}
            </Form.Select>
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
