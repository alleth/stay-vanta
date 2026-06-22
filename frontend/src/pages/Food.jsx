import { Fragment, useCallback, useEffect, useMemo, useState } from 'react'
import {
  Tab, Tabs, Card, Table, Button, Badge, Modal, Form, Row, Col, Alert, Spinner, InputGroup,
  Pagination, ListGroup,
} from 'react-bootstrap'
import { useAuth } from '../context/AuthContext'
import { useProperty } from '../context/PropertyContext'
import { useSubmit } from '../hooks/useSubmit'
import { formatMoney } from '../utils/format'
import {
  listMenu, createMenuItem, updateMenuItem, deleteMenuItem,
  listOrders, createOrder, serveOrder, cancelOrder,
  listInvoices, getInvoice, settleInvoice,
} from '../api/food'
import { listItems } from '../api/inventory'
import { listGuests } from '../api/guests'
import { listReservations } from '../api/frontdesk'
import { SkeletonTable, SkeletonTableRows, Skeleton } from '../components/Skeleton'

const PAY_VARIANT = { paid: 'success', charge_to_room: 'warning', unpaid: 'secondary' }
const ORDER_VARIANT = { open: 'primary', served: 'success', cancelled: 'dark' }
const ORDERS_PER_PAGE = 20

const todayStr = () => new Date().toISOString().slice(0, 10)
const fmtDateTime = (s) => (s ? new Date(s).toLocaleString() : '—')

export default function Food() {
  const { role } = useAuth()
  const { propertyId } = useProperty()
  const canManageMenu = role === 'owner' || role === 'admin'

  const [menu, setMenu] = useState([])
  const [inventory, setInventory] = useState([])
  const [guests, setGuests] = useState([])
  const [roomByGuest, setRoomByGuest] = useState({}) // guest_id → [room numbers] (checked-in)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [pending, setPending] = useState(null) // key of the in-flight inline action
  const [modal, setModal] = useState(null) // 'order' | 'menu' | {type:'menu',item} | {type:'invoice',id}

  // Orders — server-paginated and filtered (a fresh start each day).
  const [orders, setOrders] = useState([])
  const [ordersTotal, setOrdersTotal] = useState(0)
  const [ordersLoading, setOrdersLoading] = useState(false)
  const [orderPage, setOrderPage] = useState(1)
  const [orderStatus, setOrderStatus] = useState('all')
  const [orderDate, setOrderDate] = useState(todayStr)
  const [orderAll, setOrderAll] = useState(false)

  // Invoices — date-filtered (open tabs always show).
  const [invoices, setInvoices] = useState([])
  const [invoicesLoading, setInvoicesLoading] = useState(false)
  const [invoiceDate, setInvoiceDate] = useState(todayStr)
  const [invoiceAll, setInvoiceAll] = useState(false)

  // Menu — client-side search + linked-stock filter.
  const [menuSearch, setMenuSearch] = useState('')
  const [menuStock, setMenuStock] = useState('all')

  const loadBase = useCallback(async () => {
    if (!propertyId) return
    try {
      const [m, items, g, res] = await Promise.all([
        listMenu(propertyId), listItems(propertyId), listGuests(propertyId),
        listReservations(propertyId, { status: 'checked_in' }),
      ])
      setMenu(m)
      setInventory(items)
      setGuests(g)
      // Map each in-house guest to the room(s) they're currently checked into.
      const rooms = {}
      for (const r of res) {
        if (r.guest_id && r.room) (rooms[r.guest_id] ??= []).push(r.room.room_number)
      }
      setRoomByGuest(rooms)
      setError(null)
    } catch {
      setError('Could not load food data.')
    } finally {
      setLoading(false)
    }
  }, [propertyId])

  const loadOrders = useCallback(async () => {
    if (!propertyId) return
    setOrdersLoading(true)
    try {
      const params = { page: orderPage, limit: ORDERS_PER_PAGE, date: orderAll ? 'all' : orderDate }
      if (orderStatus !== 'all') params.status = orderStatus
      const data = await listOrders(propertyId, params)
      setOrders(data.orders ?? [])
      setOrdersTotal(data.total ?? (data.orders?.length ?? 0))
    } catch {
      setError('Could not load orders.')
    } finally {
      setOrdersLoading(false)
    }
  }, [propertyId, orderPage, orderStatus, orderDate, orderAll])

  const loadInvoices = useCallback(async () => {
    if (!propertyId) return
    setInvoicesLoading(true)
    try {
      setInvoices(await listInvoices(propertyId, { date: invoiceAll ? 'all' : invoiceDate }))
    } catch {
      setError('Could not load invoices.')
    } finally {
      setInvoicesLoading(false)
    }
  }, [propertyId, invoiceDate, invoiceAll])

  // eslint-disable-next-line react-hooks/set-state-in-effect
  useEffect(() => { loadBase() }, [loadBase])
  // eslint-disable-next-line react-hooks/set-state-in-effect
  useEffect(() => { loadOrders() }, [loadOrders])
  // eslint-disable-next-line react-hooks/set-state-in-effect
  useEffect(() => { loadInvoices() }, [loadInvoices])

  // `key` identifies the in-flight button (spinner + disable the rest).
  async function act(key, fn, ...args) {
    setPending(key)
    setError(null)
    try {
      await fn(...args)
      await Promise.all([loadOrders(), loadInvoices(), loadBase()])
    } catch (ex) {
      setError(ex?.response?.data?.message ?? 'Action failed.')
    } finally {
      setPending(null)
    }
  }

  // Distinct linked-stock items for the menu filter.
  const stockOptions = useMemo(() => {
    const map = new Map()
    for (const m of menu) if (m.inventory_item) map.set(m.inventory_item.id, m.inventory_item.name)
    return [...map.entries()].sort((a, b) => a[1].localeCompare(b[1]))
  }, [menu])

  const filteredMenu = useMemo(() => {
    const q = menuSearch.trim().toLowerCase()
    return menu.filter((m) => {
      if (q && !m.name.toLowerCase().includes(q)) return false
      if (menuStock === 'unlinked') return !m.inventory_item_id
      if (menuStock !== 'all') return String(m.inventory_item_id) === menuStock
      return true
    })
  }, [menu, menuSearch, menuStock])

  // Group the (filtered) menu by its linked stock's category.
  const menuGroups = useMemo(() => {
    const groups = new Map()
    for (const m of filteredMenu) {
      const cat = m.inventory_item?.inventory_category?.name ?? 'Unlinked / prepared'
      if (!groups.has(cat)) groups.set(cat, [])
      groups.get(cat).push(m)
    }
    return [...groups.entries()].sort((a, b) => a[0].localeCompare(b[0]))
  }, [filteredMenu])

  const totalPages = Math.max(1, Math.ceil(ordersTotal / ORDERS_PER_PAGE))

  if (!propertyId)
    return <Alert variant="info">Select or create a property to use Food &amp; Orders.</Alert>

  return (
    <div>
      <h1 className="h3 fw-bold mb-3">Food &amp; Orders</h1>
      {error && <Alert variant="danger" dismissible onClose={() => setError(null)}>{error}</Alert>}

      {loading ? (
        <SkeletonTable rows={6} />
      ) : (
        <Tabs defaultActiveKey="orders" className="mb-3">
          {/* ---- Orders ---- */}
          <Tab eventKey="orders" title={`Orders (${ordersTotal})`}>
            <Card className="shadow-sm mb-2">
              <Card.Body className="d-flex flex-wrap align-items-end gap-3">
                <Form.Group>
                  <Form.Label className="small text-muted mb-1">Status</Form.Label>
                  <Form.Select size="sm" value={orderStatus} style={{ width: 'auto' }}
                    onChange={(e) => { setOrderStatus(e.target.value); setOrderPage(1) }}>
                    <option value="all">All statuses</option>
                    <option value="open">Open</option>
                    <option value="served">Served</option>
                    <option value="cancelled">Cancelled</option>
                  </Form.Select>
                </Form.Group>
                <Form.Group>
                  <Form.Label className="small text-muted mb-1">Date</Form.Label>
                  <Form.Control type="date" size="sm" value={orderDate} disabled={orderAll} style={{ width: 'auto' }}
                    onChange={(e) => { setOrderDate(e.target.value); setOrderPage(1) }} />
                </Form.Group>
                <Form.Check type="checkbox" label="All dates" className="mb-1" checked={orderAll}
                  onChange={(e) => { setOrderAll(e.target.checked); setOrderPage(1) }} />
                <div className="ms-auto">
                  <Button onClick={() => setModal('order')} disabled={menu.length === 0}>New order</Button>
                </div>
              </Card.Body>
            </Card>
            <Card className="shadow-sm">
              <Table responsive hover className="mb-0 align-middle">
                <thead>
                  <tr>
                    <th>#</th><th>Date</th><th>Items</th><th>Guest</th><th className="text-end">Total</th>
                    <th>Payment</th><th>Status</th><th>Receptionist</th><th className="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {ordersLoading && <SkeletonTableRows rows={5} cols={9} />}
                  {!ordersLoading && orders.length === 0 && (
                    <tr><td colSpan={9} className="text-center text-muted py-4">No orders to show.</td></tr>
                  )}
                  {!ordersLoading && orders.map((o) => (
                    <tr key={o.id}>
                      <td>{o.id}</td>
                      <td className="small text-muted text-nowrap">{fmtDateTime(o.created)}</td>
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
              {totalPages > 1 && (
                <Card.Footer className="d-flex justify-content-between align-items-center">
                  <span className="small text-muted">
                    Page {orderPage} of {totalPages} · {ordersTotal} order(s)
                  </span>
                  <Pagination size="sm" className="mb-0">
                    <Pagination.Prev disabled={orderPage <= 1 || ordersLoading}
                      onClick={() => setOrderPage((p) => Math.max(1, p - 1))} />
                    <Pagination.Next disabled={orderPage >= totalPages || ordersLoading}
                      onClick={() => setOrderPage((p) => Math.min(totalPages, p + 1))} />
                  </Pagination>
                </Card.Footer>
              )}
            </Card>
          </Tab>

          {/* ---- Menu ---- */}
          <Tab eventKey="menu" title={`Menu (${menu.length})`}>
            <Card className="shadow-sm mb-2">
              <Card.Body className="d-flex flex-wrap align-items-end gap-3">
                <Form.Group>
                  <Form.Label className="small text-muted mb-1">Search</Form.Label>
                  <Form.Control size="sm" value={menuSearch} placeholder="Item name" style={{ width: 200 }}
                    onChange={(e) => setMenuSearch(e.target.value)} />
                </Form.Group>
                <Form.Group>
                  <Form.Label className="small text-muted mb-1">Linked stock</Form.Label>
                  <Form.Select size="sm" value={menuStock} style={{ width: 'auto' }}
                    onChange={(e) => setMenuStock(e.target.value)}>
                    <option value="all">All</option>
                    <option value="unlinked">Unlinked / prepared</option>
                    {stockOptions.map(([id, name]) => <option key={id} value={String(id)}>{name}</option>)}
                  </Form.Select>
                </Form.Group>
                <span className="text-muted small">{filteredMenu.length} shown</span>
                {canManageMenu && (
                  <div className="ms-auto"><Button onClick={() => setModal('menu')}>Add menu item</Button></div>
                )}
              </Card.Body>
            </Card>
            <Card className="shadow-sm">
              <Table responsive hover className="mb-0 align-middle">
                <thead>
                  <tr>
                    <th>Item</th><th className="text-end">Price</th><th>Linked stock</th>
                    <th>Available</th>{canManageMenu && <th></th>}
                  </tr>
                </thead>
                <tbody>
                  {filteredMenu.length === 0 && (
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
                                onClick={() => { if (window.confirm(`Remove "${m.name}" from the menu? Past orders keep their record.`)) act(`del-menu-${m.id}`, deleteMenuItem, m.id) }}>
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
            <Card className="shadow-sm mb-2">
              <Card.Body className="d-flex flex-wrap align-items-end gap-3">
                <Form.Group>
                  <Form.Label className="small text-muted mb-1">Date</Form.Label>
                  <Form.Control type="date" size="sm" value={invoiceDate} disabled={invoiceAll} style={{ width: 'auto' }}
                    onChange={(e) => setInvoiceDate(e.target.value)} />
                </Form.Group>
                <Form.Check type="checkbox" label="All dates" className="mb-1" checked={invoiceAll}
                  onChange={(e) => setInvoiceAll(e.target.checked)} />
                <span className="text-muted small">Open tabs always show.</span>
              </Card.Body>
            </Card>
            <Card className="shadow-sm">
              <Table responsive hover className="mb-0 align-middle">
                <thead>
                  <tr>
                    <th>Guest</th><th>Opened</th><th className="text-end">Total</th><th>Status</th>
                    <th className="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {invoicesLoading && <SkeletonTableRows rows={4} cols={5} />}
                  {!invoicesLoading && invoices.length === 0 && (
                    <tr><td colSpan={5} className="text-center text-muted py-4">No invoices to show.</td></tr>
                  )}
                  {!invoicesLoading && invoices.map((inv) => (
                    <tr key={inv.id}>
                      <td className="fw-semibold">{inv.guest?.full_name ?? '—'}</td>
                      <td className="small text-muted text-nowrap">{fmtDateTime(inv.created)}</td>
                      <td className="text-end">{formatMoney(inv.total)}</td>
                      <td><Badge bg={inv.status === 'open' ? 'warning' : 'success'}>{inv.status}</Badge></td>
                      <td className="text-end text-nowrap">
                        <Button size="sm" variant="outline-secondary" className="me-1"
                          onClick={() => setModal({ type: 'invoice', id: inv.id })}>View</Button>
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
        <OrderModal menu={menu.filter((m) => m.is_available)} guests={guests} roomByGuest={roomByGuest}
          propertyId={propertyId}
          onClose={() => setModal(null)} onSaved={() => { setModal(null); loadOrders(); loadInvoices() }} />
      )}
      {(modal === 'menu' || modal?.type === 'menu') && (
        <MenuModal item={modal?.item} inventory={inventory} propertyId={propertyId}
          onClose={() => setModal(null)} onSaved={() => { setModal(null); loadBase() }} />
      )}
      {modal?.type === 'invoice' && (
        <InvoiceModal id={modal.id} onClose={() => setModal(null)} />
      )}
    </div>
  )
}

function InvoiceModal({ id, onClose }) {
  const [invoice, setInvoice] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    getInvoice(id).then(setInvoice).catch(() => setError('Could not load the invoice.'))
  }, [id])

  return (
    <Modal show onHide={onClose} centered>
      <Modal.Header closeButton>
        <Modal.Title>Invoice #{id}{invoice?.guest ? ` — ${invoice.guest.full_name}` : ''}</Modal.Title>
      </Modal.Header>
      <Modal.Body>
        {error && <Alert variant="danger">{error}</Alert>}
        {!invoice && !error && (
          <div className="space-y-2">
            <Skeleton className="h-5 w-1/2" />
            <Skeleton className="h-5 w-full" />
            <Skeleton className="h-5 w-full" />
            <Skeleton className="h-5 w-2/3" />
          </div>
        )}
        {invoice && (
          <>
            <div className="d-flex justify-content-between mb-2">
              <Badge bg={invoice.status === 'open' ? 'warning' : 'success'}>{invoice.status}</Badge>
              <span className="small text-muted">
                Opened {fmtDateTime(invoice.created)}
                {invoice.settled_at && ` · Settled ${fmtDateTime(invoice.settled_at)}`}
              </span>
            </div>
            {invoice.invoice_lines?.length ? (
              <ListGroup variant="flush">
                {invoice.invoice_lines.map((l) => (
                  <ListGroup.Item key={l.id} className="d-flex justify-content-between px-0">
                    <span>
                      {l.description}
                      {l.source_type && <span className="text-muted small ms-2">{l.source_type.replace('_', ' ')}</span>}
                    </span>
                    <span>{formatMoney(l.amount)}</span>
                  </ListGroup.Item>
                ))}
              </ListGroup>
            ) : (
              <p className="text-muted mb-0">No line items.</p>
            )}
            <div className="d-flex justify-content-between fw-bold border-top pt-2 mt-2">
              <span>Total</span><span>{formatMoney(invoice.total)}</span>
            </div>
          </>
        )}
      </Modal.Body>
      <Modal.Footer>
        <Button variant="secondary" onClick={onClose}>Close</Button>
      </Modal.Footer>
    </Modal>
  )
}

function OrderModal({ menu, guests, roomByGuest, propertyId, onClose, onSaved }) {
  const [cart, setCart] = useState({}) // { menuId: qty }
  const [payment, setPayment] = useState('paid')
  const [guestId, setGuestId] = useState('')
  const [search, setSearch] = useState('')

  const setQty = (id, qty) => setCart((c) => ({ ...c, [id]: Math.max(0, qty) }))

  const filteredMenu = useMemo(() => {
    const q = search.trim().toLowerCase()
    return q ? menu.filter((m) => m.name.toLowerCase().includes(q)) : menu
  }, [menu, search])

  const lines = useMemo(
    () => menu.filter((m) => (cart[m.id] ?? 0) > 0).map((m) => ({ menu: m, qty: cart[m.id] })),
    [menu, cart],
  )
  const total = lines.reduce((sum, l) => sum + Number(l.menu.price) * l.qty, 0)
  const count = lines.reduce((sum, l) => sum + l.qty, 0)

  const { run, busy, err } = useSubmit(async () => {
    const payload = {
      items: lines.map((l) => ({ food_menu_item_id: l.menu.id, quantity: l.qty })),
      payment_status: payment,
    }
    if (guestId) payload.guest_id = Number(guestId)
    await createOrder(payload, propertyId)
    onSaved()
  })

  const needGuest = payment === 'charge_to_room'

  return (
    <Modal show onHide={onClose} centered size="xl">
      <Form onSubmit={run}>
        <Modal.Header closeButton><Modal.Title>New order</Modal.Title></Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          <Row className="g-3">
            {/* ---- Menu (searchable) ---- */}
            <Col md={7}>
              <Form.Control size="sm" className="mb-2" value={search} placeholder="Search the menu…"
                onChange={(e) => setSearch(e.target.value)} />
              <div style={{ height: 380, overflowY: 'auto' }} className="border rounded">
                {filteredMenu.length === 0 && (
                  <div className="text-muted small text-center py-4">No menu items match.</div>
                )}
                {filteredMenu.map((m) => {
                  const qty = cart[m.id] ?? 0
                  return (
                    <div key={m.id} className="d-flex align-items-center gap-2 px-2 py-1 border-bottom">
                      <div className="flex-grow-1">
                        <div className="fw-semibold small">{m.name}</div>
                        <div className="text-muted small">{formatMoney(m.price)}</div>
                      </div>
                      {qty > 0 ? (
                        <InputGroup size="sm" style={{ width: 116 }}>
                          <Button variant="outline-secondary" onClick={() => setQty(m.id, qty - 1)}>−</Button>
                          <Form.Control className="text-center" value={qty}
                            onChange={(e) => setQty(m.id, parseInt(e.target.value, 10) || 0)} />
                          <Button variant="outline-secondary" onClick={() => setQty(m.id, qty + 1)}>+</Button>
                        </InputGroup>
                      ) : (
                        <Button size="sm" variant="outline-primary" onClick={() => setQty(m.id, 1)}>Add</Button>
                      )}
                    </div>
                  )
                })}
              </div>
            </Col>

            {/* ---- Order (cart) side pane ---- */}
            <Col md={5}>
              <div className="d-flex flex-column h-100">
                <div className="fw-semibold mb-2">
                  Order {count > 0 && <Badge bg="primary" className="ms-1">{count}</Badge>}
                </div>
                <div style={{ minHeight: 150, maxHeight: 230, overflowY: 'auto' }} className="border rounded mb-2">
                  {lines.length === 0 ? (
                    <div className="text-muted small text-center py-4">No items yet — add from the menu.</div>
                  ) : lines.map((l) => (
                    <div key={l.menu.id} className="d-flex align-items-center gap-2 px-2 py-1 border-bottom">
                      <div className="flex-grow-1">
                        <div className="small fw-semibold">{l.menu.name}</div>
                        <div className="text-muted small">
                          {l.qty} × {formatMoney(l.menu.price)} = {formatMoney(Number(l.menu.price) * l.qty)}
                        </div>
                      </div>
                      <InputGroup size="sm" style={{ width: 104 }}>
                        <Button variant="outline-secondary" onClick={() => setQty(l.menu.id, l.qty - 1)}>−</Button>
                        <Form.Control className="text-center" value={l.qty}
                          onChange={(e) => setQty(l.menu.id, parseInt(e.target.value, 10) || 0)} />
                        <Button variant="outline-secondary" onClick={() => setQty(l.menu.id, l.qty + 1)}>+</Button>
                      </InputGroup>
                      <Button variant="link" className="text-danger p-0 px-1" title="Remove"
                        onClick={() => setQty(l.menu.id, 0)}>×</Button>
                    </div>
                  ))}
                </div>
                <div className="d-flex justify-content-between fw-bold border-top pt-2 mb-3">
                  <span>Total</span><span className="fs-5">{formatMoney(total)}</span>
                </div>
                <Form.Group className="mb-2">
                  <Form.Label className="small mb-1">Payment</Form.Label>
                  <Form.Select size="sm" value={payment} onChange={(e) => setPayment(e.target.value)}>
                    <option value="paid">Paid now</option>
                    <option value="charge_to_room">Charge to room</option>
                    <option value="unpaid">Unpaid (pay later)</option>
                  </Form.Select>
                </Form.Group>
                <Form.Group>
                  <Form.Label className="small mb-1">
                    Guest {needGuest ? <span className="text-danger">*</span> : <span className="text-muted">(optional)</span>}
                  </Form.Label>
                  <GuestPicker guests={guests} roomByGuest={roomByGuest} value={guestId}
                    onChange={setGuestId} required={needGuest} propertyId={propertyId} />
                </Form.Group>
              </div>
            </Col>
          </Row>
        </Modal.Body>
        <Modal.Footer>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={busy || lines.length === 0}>
            {busy ? <Spinner size="sm" /> : `Place order${count ? ` (${count})` : ''}`}
          </Button>
        </Modal.Footer>
      </Form>
    </Modal>
  )
}

// Searchable guest combobox: filters by name OR room number, tags each guest with
// their current room ("Rm 101") or "Walk-in", and pins the last 3 picked guests on top.
function GuestPicker({ guests, roomByGuest, value, onChange, required, propertyId }) {
  const recentKey = `sv:recentGuests:${propertyId}`
  const [open, setOpen] = useState(false)
  const [q, setQ] = useState('')
  const [recent, setRecent] = useState(() => {
    try { return JSON.parse(localStorage.getItem(recentKey) || '[]') } catch { return [] }
  })

  const rooms = (g) => roomByGuest[g.id] ?? []
  const label = (g) => (rooms(g).length ? `Rm ${rooms(g).join(', ')}` : 'Walk-in')
  const selected = guests.find((g) => String(g.id) === String(value)) || null

  const options = useMemo(() => {
    const query = q.trim().toLowerCase()
    if (query) {
      return guests.filter((g) =>
        g.full_name.toLowerCase().includes(query) ||
        rooms(g).some((r) => String(r).toLowerCase().includes(query)),
      ).slice(0, 50)
    }
    const recentIds = recent.map(String)
    const recentSet = new Set(recentIds)
    const pinned = recentIds.map((id) => guests.find((g) => String(g.id) === id)).filter(Boolean)
    const rest = guests.filter((g) => !recentSet.has(String(g.id)))
    return [...pinned, ...rest].slice(0, 50)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [q, guests, roomByGuest, recent])

  const pick = (g) => {
    onChange(String(g.id))
    const next = [g.id, ...recent.filter((id) => id !== g.id)].slice(0, 3)
    setRecent(next)
    try { localStorage.setItem(recentKey, JSON.stringify(next)) } catch { /* ignore */ }
    setQ('')
    setOpen(false)
  }

  return (
    <div className="position-relative">
      <InputGroup size="sm">
        <Form.Control
          placeholder="Search name or room #…"
          value={open ? q : (selected ? selected.full_name : '')}
          onChange={(e) => { setQ(e.target.value); setOpen(true) }}
          onFocus={() => setOpen(true)}
          onBlur={() => setTimeout(() => setOpen(false), 120)}
          required={required && !selected} />
        {selected && (
          <Button variant="outline-secondary" title="Clear"
            onMouseDown={(e) => e.preventDefault()}
            onClick={() => { onChange(''); setQ('') }}>×</Button>
        )}
      </InputGroup>
      {selected && !open && (
        <div className="mt-1">
          <Badge bg={rooms(selected).length ? 'info' : 'secondary'}>{label(selected)}</Badge>
        </div>
      )}
      {open && (
        <div className="position-absolute w-100 bg-white border rounded shadow-sm mt-1"
          style={{ zIndex: 5, maxHeight: 220, overflowY: 'auto' }}
          onMouseDown={(e) => e.preventDefault()}>
          {options.length === 0 && <div className="text-muted small px-3 py-2">No guests found.</div>}
          {options.map((g, i) => (
            <button type="button" key={g.id}
              className="d-flex w-100 align-items-center gap-2 border-0 bg-transparent text-start px-3 py-2"
              onClick={() => pick(g)}>
              <span className="flex-grow-1 small">
                {g.full_name}
                {!q.trim() && i < recent.length && <span className="text-muted ms-2">· recent</span>}
              </span>
              <Badge bg={rooms(g).length ? 'info' : 'secondary'}>{label(g)}</Badge>
            </button>
          ))}
        </div>
      )}
    </div>
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
