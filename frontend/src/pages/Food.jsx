import { Fragment, useCallback, useEffect, useMemo, useState } from 'react'
import {
  Tab, Tabs, Card, Table, Button, Badge, Modal, Form, Alert, Spinner, InputGroup,
  Pagination,
} from '../components/ui'
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

// Philippines standard VAT, assumed already included in prices (shown as a
// breakdown on invoices/receipts, not added on top).
const VAT_RATE = 0.12
const vatBreakdown = (total) => {
  const vatable = Number(total) / (1 + VAT_RATE)
  return { vatable, vat: Number(total) - vatable }
}

const PAYMENT_METHOD_LABEL = { cash: 'Cash', gcash: 'GCash', maya: 'Maya', gotyme: 'GoTyme' }

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
  const [modal, setModal] = useState(null) // 'order' | {type:'menu',item?,defaultType?} | {type:'invoice'|'settle',id}

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

  // The Food & Orders menu catalogue splits into two management tabs by
  // type — Food items (linked to Food Stock) and Linens (linked to the
  // Linens category) — while New Order keeps browsing both combined.
  const foodMenu = useMemo(() => menu.filter((m) => m.type !== 'linen'), [menu])
  const linenMenu = useMemo(() => menu.filter((m) => m.type === 'linen'), [menu])

  const totalPages = Math.max(1, Math.ceil(ordersTotal / ORDERS_PER_PAGE))

  if (!propertyId)
    return <Alert variant="info">Select or create a property to use Food &amp; Orders.</Alert>

  return (
    <div>
      <h1 className="mb-4 text-2xl font-bold">Food &amp; Orders</h1>
      {error && <Alert variant="danger" dismissible onClose={() => setError(null)}>{error}</Alert>}

      {loading ? (
        <SkeletonTable rows={6} />
      ) : (
        <Tabs defaultActiveKey="orders" className="mb-4">
          {/* ---- Orders ---- */}
          <Tab eventKey="orders" title={`Orders (${ordersTotal})`}>
            <Card className="mb-2 shadow-sm">
              <Card.Body className="flex flex-wrap items-end gap-4 p-4">
                <Form.Group>
                  <Form.Label className="mb-1 text-muted">Status</Form.Label>
                  <Form.Select size="sm" value={orderStatus} style={{ width: 'auto' }}
                    onChange={(e) => { setOrderStatus(e.target.value); setOrderPage(1) }}>
                    <option value="all">All statuses</option>
                    <option value="open">Open</option>
                    <option value="served">Served</option>
                    <option value="cancelled">Cancelled</option>
                  </Form.Select>
                </Form.Group>
                <Form.Group>
                  <Form.Label className="mb-1 text-muted">Date</Form.Label>
                  <Form.Control type="date" size="sm" value={orderDate} disabled={orderAll} style={{ width: 'auto' }}
                    onChange={(e) => { setOrderDate(e.target.value); setOrderPage(1) }} />
                </Form.Group>
                <Form.Check type="checkbox" label="All dates" className="mb-1" checked={orderAll}
                  onChange={(e) => { setOrderAll(e.target.checked); setOrderPage(1) }} />
                <div className="ml-auto">
                  <Button onClick={() => setModal('order')} disabled={menu.length === 0}>New order</Button>
                </div>
              </Card.Body>
            </Card>
            <Card className="shadow-sm">
              <Table hover>
                <thead>
                  <tr>
                    <th>#</th><th>Date</th><th>Items</th><th>Guest</th><th className="text-right">Total</th>
                    <th>Payment</th><th>Status</th><th>Receptionist</th><th className="text-right">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {ordersLoading && <SkeletonTableRows rows={5} cols={9} />}
                  {!ordersLoading && orders.length === 0 && (
                    <tr><td colSpan={9} className="py-6 text-center text-muted">No orders to show.</td></tr>
                  )}
                  {!ordersLoading && orders.map((o) => (
                    <tr key={o.id}>
                      <td>{o.id}</td>
                      <td className="whitespace-nowrap text-xs text-muted">{fmtDateTime(o.created)}</td>
                      <td className="text-xs">
                        {o.food_order_items?.map((it) =>
                          `${it.quantity}× ${it.food_menu_item?.name ?? it.description ?? 'item'}`).join(', ')}
                      </td>
                      <td>{o.guest?.full_name ?? '—'}{o.room ? ` (Rm ${o.room.room_number})` : ''}</td>
                      <td className="text-right">
                        {formatMoney(o.total)}
                        {o.discount_type && o.discount_type !== 'none' && (
                          <div className="whitespace-nowrap text-[11px] text-muted"
                            title={`${o.discount_name ?? ''} · ID ${o.discount_id_number ?? ''}`}>
                            −20% {o.discount_type}
                          </div>
                        )}
                        {Number(o.cooking_charge) > 0 && (
                          <div className="whitespace-nowrap text-[11px] text-muted">
                            incl. cooking {formatMoney(o.cooking_charge)}
                          </div>
                        )}
                      </td>
                      <td>
                        <Badge bg={PAY_VARIANT[o.payment_status]}>{o.payment_status.replace('_', ' ')}</Badge>
                        {o.payment_method && (
                          <div className="mt-0.5 whitespace-nowrap text-[11px] text-muted">
                            {PAYMENT_METHOD_LABEL[o.payment_method] ?? o.payment_method}
                          </div>
                        )}
                      </td>
                      <td><Badge bg={ORDER_VARIANT[o.status]}>{o.status}</Badge></td>
                      <td className="text-xs text-muted">{o.receptionist?.name ?? '—'}</td>
                      <td className="whitespace-nowrap text-right">
                        {o.status === 'open' && (
                          <Button size="sm" variant="outline-success" className="mr-1"
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
                <Card.Footer className="flex items-center justify-between px-4 py-3">
                  <span className="text-sm text-muted">
                    Page {orderPage} of {totalPages} · {ordersTotal} order(s)
                  </span>
                  <Pagination>
                    <Pagination.Prev disabled={orderPage <= 1 || ordersLoading}
                      onClick={() => setOrderPage((p) => Math.max(1, p - 1))} />
                    <Pagination.Next disabled={orderPage >= totalPages || ordersLoading}
                      onClick={() => setOrderPage((p) => Math.min(totalPages, p + 1))} />
                  </Pagination>
                </Card.Footer>
              )}
            </Card>
          </Tab>

          {/* ---- Food ---- */}
          <Tab eventKey="food" title={`Food (${foodMenu.length})`}>
            <MenuCatalog
              menuType="food" items={foodMenu} canManageMenu={canManageMenu} pending={pending}
              onAdd={() => setModal({ type: 'menu', defaultType: 'food' })}
              onEdit={(m) => setModal({ type: 'menu', item: m })}
              onDelete={(m) => act(`del-menu-${m.id}`, deleteMenuItem, m.id)}
            />
          </Tab>

          {/* ---- Linens ---- */}
          <Tab eventKey="linens" title={`Linens (${linenMenu.length})`}>
            <MenuCatalog
              menuType="linen" items={linenMenu} canManageMenu={canManageMenu} pending={pending}
              onAdd={() => setModal({ type: 'menu', defaultType: 'linen' })}
              onEdit={(m) => setModal({ type: 'menu', item: m })}
              onDelete={(m) => act(`del-menu-${m.id}`, deleteMenuItem, m.id)}
            />
          </Tab>

          {/* ---- Invoices ---- */}
          <Tab eventKey="invoices" title={`Invoices (${invoices.length})`}>
            <Card className="mb-2 shadow-sm">
              <Card.Body className="flex flex-wrap items-end gap-4 p-4">
                <Form.Group>
                  <Form.Label className="mb-1 text-muted">Date</Form.Label>
                  <Form.Control type="date" size="sm" value={invoiceDate} disabled={invoiceAll} style={{ width: 'auto' }}
                    onChange={(e) => setInvoiceDate(e.target.value)} />
                </Form.Group>
                <Form.Check type="checkbox" label="All dates" className="mb-1" checked={invoiceAll}
                  onChange={(e) => setInvoiceAll(e.target.checked)} />
                <span className="mb-1 text-sm text-muted">Open tabs always show.</span>
              </Card.Body>
            </Card>
            <Card className="shadow-sm">
              <Table hover>
                <thead>
                  <tr>
                    <th>#</th><th>Guest</th><th>Opened</th><th>Charges</th>
                    <th className="text-right">Total</th><th>Status</th>
                    <th className="text-right">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {invoicesLoading && <SkeletonTableRows rows={4} cols={7} />}
                  {!invoicesLoading && invoices.length === 0 && (
                    <tr><td colSpan={7} className="py-6 text-center text-muted">No invoices to show.</td></tr>
                  )}
                  {!invoicesLoading && invoices.map((inv) => (
                    <tr key={inv.id}>
                      <td className="text-muted">
                        {String(inv.id).padStart(4, '0')}
                        {inv.invoice_number && (
                          <div className="whitespace-nowrap text-[11px]">SI {inv.invoice_number}</div>
                        )}
                        {inv.or_number && (
                          <div className="whitespace-nowrap text-[11px]">OR {inv.or_number}</div>
                        )}
                      </td>
                      <td className="font-semibold">{inv.guest?.full_name ?? '—'}</td>
                      <td className="whitespace-nowrap text-xs text-muted">{fmtDateTime(inv.created)}</td>
                      <td className="max-w-[260px]">
                        <span className="block truncate text-xs text-muted">
                          {inv.invoice_lines?.length
                            ? `${inv.invoice_lines.length} line(s) · ${inv.invoice_lines.map((l) => l.description).join(', ')}`
                            : 'No charges yet'}
                        </span>
                      </td>
                      <td className="text-right font-semibold">{formatMoney(inv.total)}</td>
                      <td>
                        <Badge bg={inv.status === 'open' ? 'warning' : 'success'}>{inv.status}</Badge>
                        {inv.settled_at && (
                          <div className="mt-0.5 whitespace-nowrap text-[11px] text-muted">
                            {fmtDateTime(inv.settled_at)}
                          </div>
                        )}
                      </td>
                      <td className="whitespace-nowrap text-right">
                        <Button size="sm" variant="outline-secondary" className="mr-1"
                          onClick={() => setModal({ type: 'invoice', id: inv.id })}>View</Button>
                        {inv.status === 'open' && (
                          <Button size="sm" variant="outline-success"
                            disabled={pending !== null}
                            onClick={() => setModal({ type: 'settle', id: inv.id })}>
                            Settle
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
      {modal?.type === 'menu' && (
        <MenuModal item={modal.item} defaultType={modal.defaultType} inventory={inventory} propertyId={propertyId}
          onClose={() => setModal(null)} onSaved={() => { setModal(null); loadBase() }} />
      )}
      {modal?.type === 'invoice' && (
        <InvoiceModal id={modal.id} onClose={() => setModal(null)} />
      )}
      {modal?.type === 'settle' && (
        <SettleModal id={modal.id} onClose={() => setModal(null)}
          onSettled={() => { setModal(null); loadInvoices() }} />
      )}
    </div>
  )
}

// The Food & Orders catalogue for one menu type (food | linen): search +
// linked-stock filter, grouped by the linked stock's category. Rendered once
// per tab with a different `items` slice so Food and Linens stay separate
// management lists (both still show up together, grouped by category, in
// New Order's combined browsing list).
function MenuCatalog({ menuType, items, canManageMenu, pending, onAdd, onEdit, onDelete }) {
  const [search, setSearch] = useState('')
  const [stockFilter, setStockFilter] = useState('all')

  const stockOptions = useMemo(() => {
    const map = new Map()
    for (const m of items) if (m.inventory_item) map.set(m.inventory_item.id, m.inventory_item.name)
    return [...map.entries()].sort((a, b) => a[1].localeCompare(b[1]))
  }, [items])

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase()
    return items.filter((m) => {
      if (q && !m.name.toLowerCase().includes(q)) return false
      if (stockFilter === 'unlinked') return !m.inventory_item_id
      if (stockFilter !== 'all') return String(m.inventory_item_id) === stockFilter
      return true
    })
  }, [items, search, stockFilter])

  const groups = useMemo(() => {
    const map = new Map()
    for (const m of filtered) {
      const cat = m.inventory_item?.inventory_category?.name ?? 'Unlinked / prepared'
      if (!map.has(cat)) map.set(cat, [])
      map.get(cat).push(m)
    }
    return [...map.entries()].sort((a, b) => a[0].localeCompare(b[0]))
  }, [filtered])

  const noun = menuType === 'linen' ? 'linen item' : 'food item'

  return (
    <>
      <Card className="mb-2 shadow-sm">
        <Card.Body className="flex flex-wrap items-end gap-4 p-4">
          <Form.Group>
            <Form.Label className="mb-1 text-muted">Search</Form.Label>
            <Form.Control size="sm" value={search} placeholder="Item name" style={{ width: 200 }}
              onChange={(e) => setSearch(e.target.value)} />
          </Form.Group>
          <Form.Group>
            <Form.Label className="mb-1 text-muted">Linked stock</Form.Label>
            <Form.Select size="sm" value={stockFilter} style={{ width: 'auto' }}
              onChange={(e) => setStockFilter(e.target.value)}>
              <option value="all">All</option>
              <option value="unlinked">Unlinked / prepared</option>
              {stockOptions.map(([id, name]) => <option key={id} value={String(id)}>{name}</option>)}
            </Form.Select>
          </Form.Group>
          <span className="mb-1 text-sm text-muted">{filtered.length} shown</span>
          {canManageMenu && (
            <div className="ml-auto"><Button onClick={onAdd}>Add {noun}</Button></div>
          )}
        </Card.Body>
      </Card>
      <Card className="shadow-sm">
        <Table hover>
          <thead>
            <tr>
              <th>Item</th><th className="text-right">Price</th><th>Linked stock</th>
              <th>Available</th>{canManageMenu && <th></th>}
            </tr>
          </thead>
          <tbody>
            {filtered.length === 0 && (
              <tr><td colSpan={canManageMenu ? 5 : 4} className="py-6 text-center text-muted">No {noun}s yet.</td></tr>
            )}
            {groups.map(([category, groupItems]) => (
              <Fragment key={category}>
                <tr className="bg-subtle">
                  <td colSpan={canManageMenu ? 5 : 4} className="text-xs font-semibold uppercase text-muted">
                    {category}
                  </td>
                </tr>
                {groupItems.map((m) => (
                  <tr key={m.id}>
                    <td className="font-semibold">{m.name}</td>
                    <td className="text-right">{formatMoney(m.price)}</td>
                    <td className="text-xs">{m.inventory_item?.name ?? <span className="text-muted">—</span>}</td>
                    <td>
                      {m.is_available
                        ? <Badge bg="success">yes</Badge>
                        : <Badge bg="secondary">no</Badge>}
                    </td>
                    {canManageMenu && (
                      <td className="whitespace-nowrap text-right">
                        <Button size="sm" variant="outline-primary" className="mr-1"
                          disabled={pending !== null}
                          onClick={() => onEdit(m)}>Edit</Button>
                        <Button size="sm" variant="outline-danger"
                          disabled={pending !== null}
                          onClick={() => { if (window.confirm(`Remove "${m.name}"? Past orders keep their record.`)) onDelete(m) }}>
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
    </>
  )
}

// Charge groups shown on the invoice, in display order. The `downpayment`
// group collects all downpayment lines: the collection itself, the credit
// applied at check-out, and any cancellation refund.
const LINE_GROUPS = {
  reservation: 'Room & stay',
  downpayment: 'Downpayment',
  early_check_in: 'Extra charges',
  food_order: 'Food & orders',
  other: 'Other charges',
}

function InvoiceModal({ id, onClose }) {
  const [invoice, setInvoice] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    getInvoice(id).then(setInvoice).catch(() => setError('Could not load the invoice.'))
  }, [id])

  // Group lines by source_type so the invoice reads like a folio.
  const groups = useMemo(() => {
    const lines = invoice?.invoice_lines ?? []
    const known = ['reservation', 'early_check_in', 'food_order']
    const isDownpayment = (l) => l.source_type.startsWith('downpayment')
    return Object.entries(LINE_GROUPS)
      .map(([key, label]) => {
        const rows = key === 'other'
          ? lines.filter((l) => !known.includes(l.source_type) && !isDownpayment(l))
          : key === 'downpayment'
            ? lines.filter(isDownpayment)
            : lines.filter((l) => l.source_type === key)
        return { key, label, rows, subtotal: rows.reduce((s, l) => s + Number(l.amount), 0) }
      })
      .filter((g) => g.rows.length > 0)
  }, [invoice])

  const settled = invoice?.status === 'settled'

  return (
    <Modal show onHide={onClose} centered size="lg">
      <Modal.Header closeButton className="border-0 px-6 pt-4 pb-0" />
      <Modal.Body className="px-6 pt-0 pb-6">
        {error && <Alert variant="danger">{error}</Alert>}
        {!invoice && !error && (
          <div className="space-y-3">
            <Skeleton className="h-8 w-1/3" />
            <Skeleton className="h-5 w-1/2" />
            <Skeleton className="h-5 w-full" />
            <Skeleton className="h-5 w-full" />
            <Skeleton className="h-5 w-2/3" />
          </div>
        )}
        {invoice && (
          <div>
            {/* ---- Heading: number + status ---- */}
            <div className="flex items-start justify-between gap-3">
              <div>
                <div className="text-[11px] font-semibold uppercase tracking-[0.18em] text-muted">
                  Invoice
                </div>
                <div className="text-2xl font-bold tracking-tight">
                  #{String(invoice.id).padStart(4, '0')}
                </div>
              </div>
              <span className={`rounded-full px-3 py-1 text-xs font-semibold ${
                settled
                  ? 'bg-emerald-50 text-emerald-700'
                  : 'bg-accent-soft text-accent'
              }`}>
                {settled ? 'Settled' : 'Open tab'}
              </span>
            </div>

            {/* ---- Meta: billed to + dates ---- */}
            <div className="mt-6 grid grid-cols-1 gap-4 rounded-xl border border-line bg-subtle p-4 sm:grid-cols-3">
              <div>
                <div className="text-[11px] font-semibold uppercase tracking-wide text-muted">Billed to</div>
                <div className="mt-0.5 text-sm font-semibold">{invoice.guest?.full_name ?? 'Walk-in guest'}</div>
                {(invoice.guest?.contact_number || invoice.guest?.email) && (
                  <div className="text-xs text-muted">
                    {[invoice.guest.contact_number, invoice.guest.email].filter(Boolean).join(' · ')}
                  </div>
                )}
              </div>
              <div>
                <div className="text-[11px] font-semibold uppercase tracking-wide text-muted">Opened</div>
                <div className="mt-0.5 text-sm">{fmtDateTime(invoice.created)}</div>
              </div>
              <div>
                <div className="text-[11px] font-semibold uppercase tracking-wide text-muted">Settled</div>
                <div className="mt-0.5 text-sm">{invoice.settled_at ? fmtDateTime(invoice.settled_at) : '—'}</div>
                {(invoice.invoice_number || invoice.or_number) && (
                  <div className="mt-0.5 text-xs text-muted">
                    {invoice.invoice_number && <div>Sales Invoice {invoice.invoice_number}</div>}
                    {invoice.or_number && <div>Official Receipt {invoice.or_number}</div>}
                  </div>
                )}
              </div>
            </div>

            {/* ---- Charges, grouped ---- */}
            {groups.length === 0 && (
              <p className="mt-6 mb-0 text-sm text-muted">No charges on this invoice yet.</p>
            )}
            {groups.map((g) => (
              <div key={g.key} className="mt-6">
                <div className="flex items-baseline justify-between border-b border-line pb-1">
                  <span className="text-[11px] font-semibold uppercase tracking-wide text-muted">
                    {g.label}
                  </span>
                  <span className="text-xs text-muted">{g.rows.length} item(s)</span>
                </div>
                {g.rows.map((l) => (
                  <div key={l.id} className="flex items-center justify-between gap-3 border-b border-dashed border-line py-2">
                    <div className="min-w-0">
                      <div className="text-sm">{l.description}</div>
                      <div className="text-[11px] text-muted">{fmtDateTime(l.created)}</div>
                    </div>
                    <div className="shrink-0 text-sm tabular-nums">{formatMoney(l.amount)}</div>
                  </div>
                ))}
                <div className="flex justify-between pt-1.5 text-xs text-muted">
                  <span>Subtotal — {g.label}</span>
                  <span className="tabular-nums">{formatMoney(g.subtotal)}</span>
                </div>
              </div>
            ))}

            {/* ---- VAT breakdown (12%, already included in the total) ---- */}
            {Number(invoice.total) > 0 && (() => {
              const { vatable, vat } = vatBreakdown(invoice.total)
              return (
                <div className="mt-4 flex flex-col gap-1 border-t border-line pt-3 text-xs text-muted">
                  <div className="flex justify-between"><span>VATable Sales</span><span className="tabular-nums">{formatMoney(vatable)}</span></div>
                  <div className="flex justify-between"><span>VAT (12%)</span><span className="tabular-nums">{formatMoney(vat)}</span></div>
                </div>
              )
            })()}

            {/* ---- Grand total ---- */}
            <div className="mt-4 flex items-center justify-between rounded-xl bg-ink px-4 py-3 text-white">
              <span className="text-sm font-medium opacity-80">Total due (VAT-inclusive)</span>
              <span className="text-xl font-bold tabular-nums">{formatMoney(invoice.total)}</span>
            </div>
            {settled && (
              <p className="mt-2 mb-0 text-center text-xs text-muted">
                Paid in full · settled {fmtDateTime(invoice.settled_at)}
              </p>
            )}
          </div>
        )}
      </Modal.Body>
      <Modal.Footer className="border-0 px-6 pt-0 pb-6">
        <Button variant="secondary" onClick={onClose}>Close</Button>
      </Modal.Footer>
    </Modal>
  )
}

// Settling shows the itemized charges first, and lets the receptionist mark
// which physical document was issued — the next number from the registered
// booklet series (Inventory → Receipt Booklets) is stamped onto the invoice.
function SettleModal({ id, onClose, onSettled }) {
  const [invoice, setInvoice] = useState(null)
  const [error, setError] = useState(null)
  const [useInvoiceDoc, setUseInvoiceDoc] = useState(false)
  const [useOr, setUseOr] = useState(false)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    getInvoice(id).then(setInvoice).catch(() => setError('Could not load the invoice.'))
  }, [id])

  async function settle() {
    setBusy(true)
    setError(null)
    try {
      await settleInvoice(id, { use_invoice: useInvoiceDoc, use_or: useOr })
      onSettled()
    } catch (ex) {
      setError(ex?.response?.data?.message ?? 'Could not settle the invoice.')
      setBusy(false)
    }
  }

  return (
    <Modal show onHide={onClose} centered>
      <Modal.Header closeButton>
        <Modal.Title>Settle invoice #{String(id).padStart(4, '0')}</Modal.Title>
      </Modal.Header>
      <Modal.Body>
        {error && <Alert variant="danger">{error}</Alert>}
        {!invoice && !error && (
          <div className="space-y-2">
            <Skeleton className="h-5 w-3/4" />
            <Skeleton className="h-5 w-full" />
            <Skeleton className="h-5 w-2/3" />
          </div>
        )}
        {invoice && (
          <>
            <div className="mb-2 text-sm text-muted">
              Billed to <span className="font-semibold text-body">{invoice.guest?.full_name ?? 'Walk-in guest'}</span>
            </div>
            <div className="mb-4 overflow-hidden rounded-lg border border-line">
              {(invoice.invoice_lines ?? []).map((l) => (
                <div key={l.id} className="flex justify-between gap-3 border-b border-line px-3 py-2 text-sm">
                  <span className="min-w-0">{l.description}</span>
                  <span className="shrink-0 tabular-nums">{formatMoney(l.amount)}</span>
                </div>
              ))}
              {(invoice.invoice_lines ?? []).length === 0 && (
                <div className="border-b border-line px-3 py-2 text-sm text-muted">No charges on this invoice.</div>
              )}
              {Number(invoice.total) > 0 && (() => {
                const { vatable, vat } = vatBreakdown(invoice.total)
                return (
                  <div className="flex flex-col gap-0.5 border-b border-line px-3 py-2 text-xs text-muted">
                    <div className="flex justify-between"><span>VATable Sales</span><span className="tabular-nums">{formatMoney(vatable)}</span></div>
                    <div className="flex justify-between"><span>VAT (12%)</span><span className="tabular-nums">{formatMoney(vat)}</span></div>
                  </div>
                )
              })()}
              <div className="flex justify-between bg-subtle px-3 py-2 font-bold">
                <span>Total (VAT-inclusive)</span><span className="tabular-nums">{formatMoney(invoice.total)}</span>
              </div>
            </div>
            <div className="space-y-2">
              <Form.Check label="Issued a physical Sales Invoice (stamps the next invoice number)"
                checked={useInvoiceDoc} onChange={(e) => setUseInvoiceDoc(e.target.checked)} />
              <Form.Check label="Issued an Official Receipt (stamps the next OR number)"
                checked={useOr} onChange={(e) => setUseOr(e.target.checked)} />
            </div>
            <p className="mt-2 mb-0 text-xs text-muted">
              Numbers come from the active booklet series registered in Inventory → Receipt Booklets.
            </p>
          </>
        )}
      </Modal.Body>
      <Modal.Footer>
        <Button variant="secondary" onClick={onClose}>Cancel</Button>
        <Button variant="success" disabled={busy || !invoice} onClick={settle}>
          {busy ? <Spinner size="sm" /> : 'Settle'}
        </Button>
      </Modal.Footer>
    </Modal>
  )
}

function OrderModal({ menu, guests, roomByGuest, propertyId, onClose, onSaved }) {
  const [cart, setCart] = useState({}) // { menuId: qty }
  const [payment, setPayment] = useState('paid')
  const [paymentMethod, setPaymentMethod] = useState('cash')
  const [guestId, setGuestId] = useState('')
  const [search, setSearch] = useState('')
  // Senior/PWD statutory discount — 20% off the items subtotal, with the
  // beneficiary's name + ID number (kept on the order and shown on invoices).
  const [discount, setDiscount] = useState('none')
  const [discountName, setDiscountName] = useState('')
  const [discountId, setDiscountId] = useState('')
  // Cooking charge: guests who bring their own food to be cooked. The amount
  // depends on what was brought, so it's typed per order.
  const [hasCooking, setHasCooking] = useState(false)
  const [cookingCharge, setCookingCharge] = useState('')
  // Custom (off-menu) lines, e.g. the guest-brought dish + ingredients used.
  const [custom, setCustom] = useState([]) // [{ key, name, price, qty }]

  const setQty = (id, qty) => setCart((c) => ({ ...c, [id]: Math.max(0, qty) }))

  const addCustom = () => setCustom((c) => [...c, { key: Date.now(), name: '', price: '', qty: 1 }])
  const setCustomField = (key, field) => (e) =>
    setCustom((c) => c.map((row) => (row.key === key ? { ...row, [field]: e.target.value } : row)))
  const removeCustom = (key) => setCustom((c) => c.filter((row) => row.key !== key))

  const filteredMenu = useMemo(() => {
    const q = search.trim().toLowerCase()
    return q ? menu.filter((m) => m.name.toLowerCase().includes(q)) : menu
  }, [menu, search])

  const lines = useMemo(
    () => menu.filter((m) => (cart[m.id] ?? 0) > 0).map((m) => ({ menu: m, qty: cart[m.id] })),
    [menu, cart],
  )
  const customLines = custom.filter(
    (c) => c.name.trim() !== '' && Number(c.price) >= 0 && Number(c.qty) > 0,
  )
  const itemsSubtotal =
    lines.reduce((sum, l) => sum + Number(l.menu.price) * l.qty, 0)
    + customLines.reduce((sum, c) => sum + Number(c.price) * Number(c.qty), 0)
  const discountAmt = discount !== 'none' ? itemsSubtotal * 0.2 : 0
  const cooking = hasCooking ? Number(cookingCharge) || 0 : 0
  const total = itemsSubtotal - discountAmt + cooking
  const count = lines.reduce((sum, l) => sum + l.qty, 0)
    + customLines.reduce((sum, c) => sum + Number(c.qty), 0)

  const { run, busy, err } = useSubmit(async () => {
    const payload = {
      items: [
        ...lines.map((l) => ({ food_menu_item_id: l.menu.id, quantity: l.qty })),
        ...customLines.map((c) => ({
          description: c.name.trim(), price: Number(c.price), quantity: Number(c.qty),
        })),
      ],
      payment_status: payment,
      payment_method: payment === 'paid' ? paymentMethod : undefined,
      discount_type: discount,
      cooking_charge: cooking,
    }
    if (discount !== 'none') {
      payload.discount_name = discountName
      payload.discount_id_number = discountId
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
          <div className="grid grid-cols-1 gap-4 md:grid-cols-12">
            {/* ---- Menu (searchable) ---- */}
            <div className="md:col-span-7">
              <Form.Control size="sm" className="mb-2" value={search} placeholder="Search the menu…"
                onChange={(e) => setSearch(e.target.value)} />
              <div className="h-[380px] overflow-y-auto rounded-lg border border-line">
                {filteredMenu.length === 0 && (
                  <div className="py-6 text-center text-sm text-muted">No menu items match.</div>
                )}
                {filteredMenu.map((m) => {
                  const qty = cart[m.id] ?? 0
                  return (
                    <div key={m.id} className="flex items-center gap-2 border-b border-line px-2 py-1 last:border-b-0">
                      <button type="button" className="min-w-0 grow text-left"
                        title="Click to add one" onClick={() => setQty(m.id, qty + 1)}>
                        <div className="text-sm font-semibold">{m.name}</div>
                        <div className="text-sm text-muted">{formatMoney(m.price)}</div>
                      </button>
                      {qty > 0 ? (
                        <InputGroup style={{ width: 116 }}>
                          <Button size="sm" variant="outline-secondary" onClick={() => setQty(m.id, qty - 1)}>−</Button>
                          <Form.Control size="sm" className="text-center" value={qty}
                            onChange={(e) => setQty(m.id, parseInt(e.target.value, 10) || 0)} />
                          <Button size="sm" variant="outline-secondary" onClick={() => setQty(m.id, qty + 1)}>+</Button>
                        </InputGroup>
                      ) : (
                        <Button size="sm" variant="outline-primary" onClick={() => setQty(m.id, 1)}>Add</Button>
                      )}
                    </div>
                  )
                })}
              </div>
            </div>

            {/* ---- Order (cart) side pane ---- */}
            <div className="md:col-span-5">
              <div className="flex h-full flex-col">
                <div className="mb-2 font-semibold">
                  Order {count > 0 && <Badge bg="primary" className="ml-1">{count}</Badge>}
                </div>
                <div className="mb-2 max-h-[230px] min-h-[150px] overflow-y-auto rounded-lg border border-line">
                  {lines.length === 0 ? (
                    <div className="py-6 text-center text-sm text-muted">No items yet — add from the menu.</div>
                  ) : lines.map((l) => (
                    <div key={l.menu.id} className="flex items-center gap-2 border-b border-line px-2 py-1 last:border-b-0">
                      <div className="min-w-0 grow">
                        <div className="text-sm font-semibold">{l.menu.name}</div>
                        <div className="text-sm text-muted">
                          {l.qty} × {formatMoney(l.menu.price)} = {formatMoney(Number(l.menu.price) * l.qty)}
                        </div>
                      </div>
                      <InputGroup style={{ width: 104 }}>
                        <Button size="sm" variant="outline-secondary" onClick={() => setQty(l.menu.id, l.qty - 1)}>−</Button>
                        <Form.Control size="sm" className="text-center" value={l.qty}
                          onChange={(e) => setQty(l.menu.id, parseInt(e.target.value, 10) || 0)} />
                        <Button size="sm" variant="outline-secondary" onClick={() => setQty(l.menu.id, l.qty + 1)}>+</Button>
                      </InputGroup>
                      <button type="button" className="px-1 text-red-600 hover:text-red-700" title="Remove"
                        onClick={() => setQty(l.menu.id, 0)}>×</button>
                    </div>
                  ))}
                </div>
                <div className="mb-2">
                  {custom.map((row) => (
                    <div key={row.key} className="mb-1 flex items-center gap-1">
                      <Form.Control size="sm" value={row.name} onChange={setCustomField(row.key, 'name')}
                        placeholder="Custom item — e.g. cooking of guest's fish" required />
                      <Form.Control size="sm" type="number" min={0} step="0.01" value={row.price}
                        onChange={setCustomField(row.key, 'price')} placeholder="Price" required
                        style={{ width: 96 }} />
                      <Form.Control size="sm" type="number" min={1} value={row.qty}
                        onChange={setCustomField(row.key, 'qty')} style={{ width: 60 }} />
                      <button type="button" className="px-1 text-red-600 hover:text-red-700" title="Remove"
                        onClick={() => removeCustom(row.key)}>×</button>
                    </div>
                  ))}
                  <Button size="sm" variant="outline-secondary" onClick={addCustom}>
                    + Custom item (not on the menu)
                  </Button>
                </div>
                <div className="mb-2 border-t border-line pt-2 text-sm">
                  {(discountAmt > 0 || cooking > 0) && (
                    <>
                      <div className="flex justify-between text-muted">
                        <span>Items subtotal</span><span>{formatMoney(itemsSubtotal)}</span>
                      </div>
                      {discountAmt > 0 && (
                        <div className="flex justify-between text-muted">
                          <span>{discount === 'senior' ? 'Senior' : 'PWD'} discount (20%)</span>
                          <span>−{formatMoney(discountAmt)}</span>
                        </div>
                      )}
                      {cooking > 0 && (
                        <div className="flex justify-between text-muted">
                          <span>Cooking charge</span><span>{formatMoney(cooking)}</span>
                        </div>
                      )}
                    </>
                  )}
                  <div className="flex items-center justify-between font-bold">
                    <span>Total</span><span className="text-xl">{formatMoney(total)}</span>
                  </div>
                </div>
                <Form.Group className="mb-2">
                  <Form.Label className="mb-1">Discount</Form.Label>
                  <Form.Select size="sm" value={discount} onChange={(e) => setDiscount(e.target.value)}>
                    <option value="none">None</option>
                    <option value="senior">Senior citizen (20%)</option>
                    <option value="pwd">PWD (20%)</option>
                  </Form.Select>
                </Form.Group>
                {discount !== 'none' && (
                  <div className="mb-2 grid grid-cols-2 gap-2">
                    <Form.Control size="sm" value={discountName} onChange={(e) => setDiscountName(e.target.value)}
                      placeholder="Beneficiary name" required />
                    <Form.Control size="sm" value={discountId} onChange={(e) => setDiscountId(e.target.value)}
                      placeholder="Senior/PWD ID number" required />
                  </div>
                )}
                <Form.Check className="mb-2" label="Add cooking charge (guest-brought food)"
                  checked={hasCooking} onChange={(e) => setHasCooking(e.target.checked)} />
                {hasCooking && (
                  <Form.Control size="sm" type="number" min={0} step="0.01" className="mb-2"
                    value={cookingCharge} onChange={(e) => setCookingCharge(e.target.value)}
                    placeholder="Cooking charge amount" required />
                )}
                <Form.Group className="mb-2">
                  <Form.Label className="mb-1">Payment</Form.Label>
                  <Form.Select size="sm" value={payment} onChange={(e) => setPayment(e.target.value)}>
                    <option value="paid">Paid now</option>
                    <option value="charge_to_room">Charge to room</option>
                    <option value="unpaid">Unpaid (pay later)</option>
                  </Form.Select>
                </Form.Group>
                {payment === 'paid' && (
                  <Form.Group className="mb-2">
                    <Form.Label className="mb-1">Payment method</Form.Label>
                    <Form.Select size="sm" value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value)}>
                      <option value="cash">Cash</option>
                      <option value="gcash">GCash</option>
                      <option value="maya">Maya</option>
                      <option value="gotyme">GoTyme</option>
                    </Form.Select>
                  </Form.Group>
                )}
                <Form.Group>
                  <Form.Label className="mb-1">
                    Guest {needGuest ? <span className="text-red-600">*</span> : <span className="font-normal text-muted">(optional)</span>}
                  </Form.Label>
                  <GuestPicker guests={guests} roomByGuest={roomByGuest} value={guestId}
                    onChange={setGuestId} required={needGuest} propertyId={propertyId} />
                </Form.Group>
              </div>
            </div>
          </div>
        </Modal.Body>
        <Modal.Footer>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={busy || (lines.length === 0 && customLines.length === 0)}>
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
    <div className="relative">
      <InputGroup>
        <Form.Control
          size="sm"
          placeholder="Search name or room #…"
          value={open ? q : (selected ? selected.full_name : '')}
          onChange={(e) => { setQ(e.target.value); setOpen(true) }}
          onFocus={() => setOpen(true)}
          onBlur={() => setTimeout(() => setOpen(false), 120)}
          required={required && !selected} />
        {selected && (
          <Button size="sm" variant="outline-secondary" title="Clear"
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
        <div className="absolute z-10 mt-1 max-h-[220px] w-full overflow-y-auto rounded-lg border border-line bg-surface shadow-md"
          onMouseDown={(e) => e.preventDefault()}>
          {options.length === 0 && <div className="px-3 py-2 text-sm text-muted">No guests found.</div>}
          {options.map((g, i) => (
            <button type="button" key={g.id}
              className="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-subtle"
              onClick={() => pick(g)}>
              <span className="min-w-0 grow text-sm">
                {g.full_name}
                {!q.trim() && i < recent.length && <span className="ml-2 text-muted">· recent</span>}
              </span>
              <Badge bg={rooms(g).length ? 'info' : 'secondary'}>{label(g)}</Badge>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}

// A menu item's Linked Stock is scoped to the matching inventory category —
// Food items link to Food Stock, Linens link to Linens — so, e.g., an "Extra
// Bed" utensil item never shows up as a linkable Food Stock choice.
const STOCK_KIND_FOR_TYPE = { food: 'food_stock', linen: 'linen' }

function MenuModal({ item, defaultType, inventory, propertyId, onClose, onSaved }) {
  const editing = Boolean(item)
  // The type is fixed by which tab (Food/Linens) this modal was opened from —
  // not user-selectable, since that's the whole point of separating the tabs.
  const menuType = item?.type ?? defaultType ?? 'food'
  const [form, setForm] = useState({
    name: item?.name ?? '',
    price: item?.price ?? '',
    inventory_item_id: item?.inventory_item_id ?? '',
    is_available: item?.is_available ?? true,
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  const stockOptions = useMemo(
    () => inventory.filter((i) => i.inventory_category?.kind === STOCK_KIND_FOR_TYPE[menuType]),
    [inventory, menuType],
  )

  const linkedItem = inventory.find((i) => String(i.id) === String(form.inventory_item_id))
  const outOfStock = linkedItem && Number(linkedItem.quantity) <= 0

  const { run, busy, err } = useSubmit(async () => {
    const payload = { ...form, type: menuType, inventory_item_id: form.inventory_item_id || null }
    if (editing) await updateMenuItem(item.id, payload)
    else await createMenuItem(payload, propertyId)
    onSaved()
  })

  return (
    <Modal show onHide={onClose} centered>
      <Form onSubmit={run}>
        <Modal.Header closeButton>
          <Modal.Title>{editing ? 'Edit item' : menuType === 'linen' ? 'Add linen item' : 'Add food item'}</Modal.Title>
        </Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          <Form.Group className="mb-4">
            <Form.Label>Name</Form.Label>
            <Form.Control value={form.name} onChange={set('name')} required autoFocus />
          </Form.Group>
          <div className="grid grid-cols-2 gap-x-6">
            <Form.Group className="mb-4">
              <Form.Label>Price</Form.Label>
              <Form.Control type="number" min={0} step="0.01" value={form.price} onChange={set('price')} required />
            </Form.Group>
            <Form.Group className="mb-4">
              <Form.Label>Availability</Form.Label>
              <Form.Select value={form.is_available ? '1' : '0'}
                onChange={(e) => setForm({ ...form, is_available: e.target.value === '1' })}>
                <option value="1">Available</option>
                <option value="0">Unavailable</option>
              </Form.Select>
            </Form.Group>
          </div>
          <Form.Group>
            <Form.Label>
              Linked {menuType === 'linen' ? 'linen' : 'food'} stock (decrements on order)
            </Form.Label>
            <Form.Select value={form.inventory_item_id} onChange={set('inventory_item_id')}>
              <option value="">Not linked</option>
              {stockOptions.map((i) => (
                <option key={i.id} value={i.id}>{i.name} ({Number(i.quantity)} {i.unit})</option>
              ))}
            </Form.Select>
            {outOfStock && (
              <Form.Text className="text-amber-600">
                This item is out of stock — it will be saved as unavailable.
              </Form.Text>
            )}
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
