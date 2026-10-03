import { Fragment, useCallback, useEffect, useMemo, useState } from 'react'
import {
  Tab, Tabs, Card, Table, Button, Badge, Modal, Form, Alert, Spinner, InputGroup,
  Pagination,
} from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { P } from '../auth/permissions'
import { useProperty } from '../context/PropertyContext'
import { useSubmit } from '../hooks/useSubmit'
import { formatMoney } from '../utils/format'
import {
  listMenu, createMenuItem, updateMenuItem, deleteMenuItem,
  listOrders, createOrder, serveOrder, cancelOrder,
} from '../api/food'
import { listItems } from '../api/inventory'
import { listGuests } from '../api/guests'
import { listReservations } from '../api/frontdesk'
import { SkeletonTable, SkeletonTableRows } from '../components/Skeleton'
import { describeError } from '../utils/apiError'
import ReasonModal from '../components/ReasonModal'

const PAY_VARIANT = { paid: 'success', charge_to_room: 'warning', unpaid: 'secondary' }
const PAY_LABEL = { paid: 'Paid', charge_to_room: 'Charged to room', unpaid: 'Unpaid' }
const ORDER_VARIANT = { open: 'primary', served: 'success', cancelled: 'dark' }
const ORDERS_PER_PAGE = 20

const PAYMENT_METHOD_LABEL = { cash: 'Cash', gcash: 'GCash', maya: 'Maya', gotyme: 'GoTyme' }

// Today on the device's own clock (the hotel's), not UTC.
const todayStr = () => new Date().toLocaleDateString('en-CA')
const fmtDateTime = (s) => (s ? new Date(s).toLocaleString() : '—')

export default function Pos() {
  const { can } = useAuth()
  const { propertyId } = useProperty()
  const canManageMenu = can(P.POS_MENU_MANAGE)

  const [menu, setMenu] = useState([])
  const [inventory, setInventory] = useState([])
  const [guests, setGuests] = useState([])
  const [roomByGuest, setRoomByGuest] = useState({}) // guest_id → [room numbers] (checked-in)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [pending, setPending] = useState(null) // key of the in-flight inline action
  // A served, paid sale being cancelled: asks the Manager why first.
  const [cancelPaid, setCancelPaid] = useState(null)
  const [modal, setModal] = useState(null) // 'order' | {type:'menu',item?,defaultType?}

  // Orders — server-paginated and filtered (a fresh start each day).
  const [orders, setOrders] = useState([])
  const [ordersTotal, setOrdersTotal] = useState(0)
  const [ordersLoading, setOrdersLoading] = useState(false)
  const [orderPage, setOrderPage] = useState(1)
  const [orderStatus, setOrderStatus] = useState('all')
  const [orderDate, setOrderDate] = useState(todayStr)
  const [orderAll, setOrderAll] = useState(false)

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

  // eslint-disable-next-line react-hooks/set-state-in-effect
  useEffect(() => { loadBase() }, [loadBase])
  // eslint-disable-next-line react-hooks/set-state-in-effect
  useEffect(() => { loadOrders() }, [loadOrders])

  // `key` identifies the in-flight button (spinner + disable the rest).
  async function act(key, fn, ...args) {
    setPending(key)
    setError(null)
    try {
      await fn(...args)
      await Promise.all([loadOrders(), loadBase()])
    } catch (ex) {
      setError(describeError(ex, 'Action failed.'))
    } finally {
      setPending(null)
    }
  }

  // The POS menu catalogue splits into two management tabs by
  // type — Food items (linked to Food Stock) and Linens (linked to the
  // Linens category) — while New Order keeps browsing both combined.
  const foodMenu = useMemo(() => menu.filter((m) => m.type !== 'linen'), [menu])
  const linenMenu = useMemo(() => menu.filter((m) => m.type === 'linen'), [menu])

  const totalPages = Math.max(1, Math.ceil(ordersTotal / ORDERS_PER_PAGE))

  if (!propertyId)
    return <Alert variant="info">Select or create a property to use POS.</Alert>

  return (
    <div>
      <h1 className="mb-4 text-2xl font-bold">POS</h1>
      {error && <Alert variant="danger" dismissible onClose={() => setError(null)}>{error}</Alert>}

      <ReasonModal
        show={cancelPaid !== null}
        title={`Cancel paid order #${cancelPaid?.id ?? ''}`}
        description={
          `This order was served and paid (${formatMoney(cancelPaid?.total ?? 0)}). Cancelling it restocks `
          + 'what it used and is recorded with your name and this reason.'
        }
        confirmLabel="Cancel the order"
        onHide={() => setCancelPaid(null)}
        onConfirm={async (reason) => {
          await cancelOrder(cancelPaid.id, reason)
          setCancelPaid(null)
          await Promise.all([loadOrders(), loadBase()])
        }}
      />

      {loading ? (
        <SkeletonTable rows={6} />
      ) : (
        <Tabs defaultActiveKey="orders" className="mb-4">
          {/* ---- Orders ---- */}
          <Tab eventKey="orders" title={`Orders (${ordersTotal})`}>
            <Card className="mb-2">
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
            <Card>
              <Table hover>
                <thead>
                  <tr>
                    <th>#</th><th>Date</th><th>Items</th><th>Guest</th><th className="text-right">Total</th>
                    <th>Payment</th><th>Status</th><th>Taken by</th><th className="text-right">Actions</th>
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
                        {o.food_order_discounts?.length > 0 && (
                          <div className="whitespace-nowrap text-[11px] text-muted"
                            title={o.food_order_discounts
                              .map((d) => `${d.beneficiary_name} (${d.discount_type}) · ID ${d.id_number}`)
                              .join(', ')}>
                            −20% × {o.food_order_discounts.length} ({o.total_diners} diner{o.total_diners === 1 ? '' : 's'})
                          </div>
                        )}
                        {Number(o.cooking_charge) > 0 && (
                          <div className="whitespace-nowrap text-[11px] text-muted">
                            incl. cooking {formatMoney(o.cooking_charge)}
                          </div>
                        )}
                      </td>
                      <td>
                        <Badge bg={PAY_VARIANT[o.payment_status]}>{PAY_LABEL[o.payment_status] ?? o.payment_status}</Badge>
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
                          && !(!can(P.POS_SALE_CANCEL_PAID) && o.status === 'served' && o.payment_status === 'paid') && (
                          <Button size="sm" variant="outline-danger"
                            disabled={pending !== null}
                            onClick={() => (o.status === 'served' && o.payment_status === 'paid'
                              ? setCancelPaid(o)
                              : act(`cancel-${o.id}`, cancelOrder, o.id))}>
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

        </Tabs>
      )}

      {modal === 'order' && (
        <OrderModal menu={menu.filter((m) => m.is_available)} guests={guests} roomByGuest={roomByGuest}
          propertyId={propertyId}
          onClose={() => setModal(null)} onSaved={() => { setModal(null); loadOrders() }} />
      )}
      {modal?.type === 'menu' && (
        <MenuModal item={modal.item} defaultType={modal.defaultType} inventory={inventory} propertyId={propertyId}
          onClose={() => setModal(null)} onSaved={() => { setModal(null); loadBase() }} />
      )}
    </div>
  )
}

// The POS catalogue for one menu type (food | linen): search +
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
      <Card className="mb-2">
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
      <Card>
        <Table hover>
          <thead>
            <tr>
              <th>Item</th><th className="text-right">Price</th><th>Linked stock</th><th>Ingredients</th>
              <th>Options</th>
              <th>Available</th>{canManageMenu && <th></th>}
            </tr>
          </thead>
          <tbody>
            {filtered.length === 0 && (
              <tr><td colSpan={canManageMenu ? 7 : 6} className="py-6 text-center text-muted">No {noun}s yet.</td></tr>
            )}
            {groups.map(([category, groupItems]) => (
              <Fragment key={category}>
                <tr className="bg-subtle">
                  <td colSpan={canManageMenu ? 7 : 6} className="text-xs font-semibold uppercase text-muted">
                    {category}
                  </td>
                </tr>
                {groupItems.map((m) => (
                  <tr key={m.id}>
                    <td className="font-semibold">{m.name}</td>
                    <td className="text-right">{formatMoney(m.price)}</td>
                    <td className="text-xs">{m.inventory_item?.name ?? <span className="text-muted">—</span>}</td>
                    <td className="text-xs">
                      {m.food_menu_item_ingredients?.length
                        ? m.food_menu_item_ingredients
                          .map((ing) => `${ing.inventory_item?.name ?? '?'} ×${Number(ing.quantity)}`)
                          .join(', ')
                        : <span className="text-muted">—</span>}
                    </td>
                    <td className="text-xs">
                      {m.food_menu_item_option_groups?.length
                        ? m.food_menu_item_option_groups
                          .map((g) => `${g.name}: ${(g.food_menu_item_options ?? []).map((o) => o.label).join('/')}`)
                          .join('; ')
                        : <span className="text-muted">—</span>}
                    </td>
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

let cartLineKeySeq = 0
const newCartLineKey = () => `cl-${++cartLineKeySeq}`

function OrderModal({ menu, guests, roomByGuest, propertyId, onClose, onSaved }) {
  // Cart lines, not a flat { menuId: qty } map: an item with option groups can
  // be added more than once (e.g. two Breakfast Combos, one with Coffee and
  // one with Juice) — each becomes its own line with its own qty + picks.
  // A plain item (no option groups) still collapses repeat adds into one line,
  // matching the old click-to-bump behavior.
  // [{ key, menuId, qty, choices: {[groupId]: optionId}, addons: {[optionId]: qty} }]
  const [cartLines, setCartLines] = useState([])
  const [payment, setPayment] = useState('paid')
  const [paymentMethod, setPaymentMethod] = useState('cash')
  const [guestId, setGuestId] = useState('')
  const [search, setSearch] = useState('')
  // Senior/PWD statutory discount — an order can carry several beneficiaries
  // (e.g. two senior citizens at the same table); the 20% only covers each
  // beneficiary's own even share of the items subtotal, so it also needs the
  // total diner count. [{key, discount_type, name, id_number}]
  const [beneficiaries, setBeneficiaries] = useState([])
  const [totalDiners, setTotalDiners] = useState(1)
  const addBeneficiary = () => {
    setBeneficiaries((bs) => [...bs, { key: Date.now(), discount_type: 'senior', name: '', id_number: '' }])
    setTotalDiners((td) => Math.max(td, beneficiaries.length + 1))
  }
  const removeBeneficiary = (key) => setBeneficiaries((bs) => bs.filter((b) => b.key !== key))
  const setBeneficiaryField = (key, field) => (e) =>
    setBeneficiaries((bs) => bs.map((b) => (b.key === key ? { ...b, [field]: e.target.value } : b)))
  // Cooking charge: guests who bring their own food to be cooked. The amount
  // depends on what was brought, so it's typed per order.
  const [hasCooking, setHasCooking] = useState(false)
  const [cookingCharge, setCookingCharge] = useState('')
  // Custom (off-menu) lines, e.g. the guest-brought dish + ingredients used.
  const [custom, setCustom] = useState([]) // [{ key, name, price, qty }]

  const addToCart = (m) => {
    if (m.food_menu_item_option_groups?.length) {
      // Always a fresh line — each add gets its own choice/add-on picks.
      setCartLines((ls) => [...ls, { key: newCartLineKey(), menuId: m.id, qty: 1, choices: {}, addons: {} }])
      return
    }
    setCartLines((ls) => {
      const existing = ls.find((l) => l.menuId === m.id)
      return existing
        ? ls.map((l) => (l.menuId === m.id ? { ...l, qty: l.qty + 1 } : l))
        : [...ls, { key: newCartLineKey(), menuId: m.id, qty: 1, choices: {}, addons: {} }]
    })
  }
  const setLineQty = (key, qty) => {
    const next = Math.max(0, qty)
    setCartLines((ls) => (next === 0 ? ls.filter((l) => l.key !== key) : ls.map((l) => (l.key === key ? { ...l, qty: next } : l))))
  }
  const removeLine = (key) => {
    setCartLines((ls) => ls.filter((l) => l.key !== key))
    setCollapsedLines((c) => {
      if (!(key in c)) return c
      const rest = { ...c }
      delete rest[key]
      return rest
    })
  }
  // A configured line starts expanded (fast path: add it, pick its options,
  // it settles once you're done) and folds to a one-line summary on request —
  // keeps the cart pane calm once there are several lines to review.
  const [collapsedLines, setCollapsedLines] = useState({})
  const toggleCollapsed = (key) => setCollapsedLines((c) => ({ ...c, [key]: !c[key] }))
  const missingRequiredChoice = (m, cartLine) => (m.food_menu_item_option_groups ?? []).some(
    (g) => g.kind === 'choice' && g.food_menu_item_options?.length && !cartLine.choices?.[g.id],
  )
  const optionsSummary = (m, cartLine) => {
    const parts = []
    for (const group of m.food_menu_item_option_groups ?? []) {
      if (group.kind === 'choice') {
        const opt = group.food_menu_item_options?.find((o) => String(o.id) === String(cartLine.choices?.[group.id]))
        if (opt) parts.push(opt.label)
      } else {
        for (const opt of group.food_menu_item_options ?? []) {
          const qty = cartLine.addons?.[opt.id] ?? 0
          if (qty > 0) parts.push(`+${qty} ${opt.label}`)
        }
      }
    }
    return parts.join(', ')
  }
  const setChoice = (lineKey, groupId, optionId) => setCartLines((ls) => ls.map((l) => (
    l.key === lineKey ? { ...l, choices: { ...l.choices, [groupId]: optionId } } : l
  )))
  const setAddonQty = (lineKey, optionId, qty) => setCartLines((ls) => ls.map((l) => (
    l.key === lineKey ? { ...l, addons: { ...l.addons, [optionId]: Math.max(0, qty) } } : l
  )))
  // An option group's price contributes once per order line, not per unit
  // ordered — e.g. "+2 extra eggs" on a line of 3 Breakfast Combos adds once.
  const lineOptionsTotal = (m, cartLine) => {
    if (!m.food_menu_item_option_groups?.length) return 0
    let total = 0
    for (const group of m.food_menu_item_option_groups) {
      if (group.kind === 'choice') {
        const opt = group.food_menu_item_options?.find((o) => String(o.id) === String(cartLine.choices?.[group.id]))
        if (opt) total += Number(opt.price_delta) || 0
      } else {
        for (const opt of group.food_menu_item_options ?? []) {
          total += (Number(opt.price_delta) || 0) * (cartLine.addons?.[opt.id] ?? 0)
        }
      }
    }
    return total
  }
  const selectedOptionsFor = (m, cartLine) => {
    const result = []
    for (const group of m.food_menu_item_option_groups ?? []) {
      if (group.kind === 'choice') {
        const chosenId = cartLine.choices?.[group.id]
        if (chosenId) result.push({ option_id: Number(chosenId), quantity: 1 })
      } else {
        for (const opt of group.food_menu_item_options ?? []) {
          const qty = cartLine.addons?.[opt.id] ?? 0
          if (qty > 0) result.push({ option_id: opt.id, quantity: qty })
        }
      }
    }
    return result
  }

  const addCustom = () => setCustom((c) => [...c, { key: Date.now(), name: '', price: '', qty: 1 }])
  const setCustomField = (key, field) => (e) =>
    setCustom((c) => c.map((row) => (row.key === key ? { ...row, [field]: e.target.value } : row)))
  const removeCustom = (key) => setCustom((c) => c.filter((row) => row.key !== key))

  const filteredMenu = useMemo(() => {
    const q = search.trim().toLowerCase()
    return q ? menu.filter((m) => m.name.toLowerCase().includes(q)) : menu
  }, [menu, search])

  const lines = useMemo(
    () => cartLines
      .map((cl) => ({ cartLine: cl, menu: menu.find((m) => m.id === cl.menuId) }))
      .filter((l) => l.menu),
    [menu, cartLines],
  )
  const customLines = custom.filter(
    (c) => c.name.trim() !== '' && Number(c.price) >= 0 && Number(c.qty) > 0,
  )
  const itemsSubtotal =
    lines.reduce((sum, l) => sum + Number(l.menu.price) * l.cartLine.qty + lineOptionsTotal(l.menu, l.cartLine), 0)
    + customLines.reduce((sum, c) => sum + Number(c.price) * Number(c.qty), 0)
  const discountAmt = beneficiaries.length > 0
    ? itemsSubtotal * (beneficiaries.length / Math.max(1, totalDiners)) * 0.2
    : 0
  const cooking = hasCooking ? Number(cookingCharge) || 0 : 0
  const total = itemsSubtotal - discountAmt + cooking
  const count = lines.reduce((sum, l) => sum + l.cartLine.qty, 0)
    + customLines.reduce((sum, c) => sum + Number(c.qty), 0)

  const { run, busy, err } = useSubmit(async () => {
    const payload = {
      items: [
        ...lines.map((l) => ({
          food_menu_item_id: l.menu.id,
          quantity: l.cartLine.qty,
          selected_options: selectedOptionsFor(l.menu, l.cartLine),
        })),
        ...customLines.map((c) => ({
          description: c.name.trim(), price: Number(c.price), quantity: Number(c.qty),
        })),
      ],
      payment_status: payment,
      payment_method: payment === 'paid' ? paymentMethod : undefined,
      cooking_charge: cooking,
    }
    if (beneficiaries.length > 0) {
      payload.total_diners = totalDiners
      payload.discount_beneficiaries = beneficiaries.map((b) => ({
        discount_type: b.discount_type, name: b.name, id_number: b.id_number,
      }))
    }
    if (guestId) payload.guest_id = Number(guestId)
    await createOrder(payload, propertyId)
    onSaved()
  })

  const needGuest = payment === 'charge_to_room'
  // Charging to room requires an active stay — a guest who already checked
  // out (or never checked in) has no room to bill. Restrict the picker
  // accordingly, and drop a selection that's no longer eligible.
  const eligibleGuests = useMemo(
    () => (needGuest ? guests.filter((g) => (roomByGuest[g.id]?.length ?? 0) > 0) : guests),
    [guests, roomByGuest, needGuest],
  )
  useEffect(() => {
    if (needGuest && guestId && !eligibleGuests.some((g) => String(g.id) === String(guestId))) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setGuestId('')
    }
  }, [needGuest, eligibleGuests, guestId])

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
                  const hasOptions = m.food_menu_item_option_groups?.length > 0
                  // A plain item collapses repeat adds into one line (its qty
                  // shows here); an item with option groups can have several
                  // independent lines (e.g. one Coffee, one Juice), so its own
                  // count lives in the cart pane instead — this row is just "Add".
                  const simpleLine = !hasOptions ? cartLines.find((l) => l.menuId === m.id) : null
                  const qty = simpleLine?.qty ?? 0
                  return (
                    <div key={m.id} className="flex items-center gap-2 border-b border-line px-2 py-1 last:border-b-0">
                      <button type="button" className="min-w-0 grow text-left"
                        title="Click to add one" onClick={() => addToCart(m)}>
                        <div className="text-sm font-semibold">{m.name}</div>
                        <div className="text-sm text-muted">{formatMoney(m.price)}</div>
                      </button>
                      {hasOptions ? (
                        <Button size="sm" variant="outline-primary" onClick={() => addToCart(m)}>Add</Button>
                      ) : qty > 0 ? (
                        <InputGroup style={{ width: 116 }}>
                          <Button size="sm" variant="outline-secondary" onClick={() => setLineQty(simpleLine.key, qty - 1)}>−</Button>
                          <Form.Control size="sm" className="text-center" value={qty}
                            onChange={(e) => setLineQty(simpleLine.key, parseInt(e.target.value, 10) || 0)} />
                          <Button size="sm" variant="outline-secondary" onClick={() => setLineQty(simpleLine.key, qty + 1)}>+</Button>
                        </InputGroup>
                      ) : (
                        <Button size="sm" variant="outline-primary" onClick={() => addToCart(m)}>Add</Button>
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
                  ) : lines.map((l) => {
                    const { cartLine } = l
                    const optsTotal = lineOptionsTotal(l.menu, cartLine)
                    const groups = l.menu.food_menu_item_option_groups ?? []
                    const isConfigured = groups.length > 0
                    const complete = !isConfigured || !missingRequiredChoice(l.menu, cartLine)
                    const collapsed = isConfigured && complete && !!collapsedLines[cartLine.key]
                    const lineTotal = formatMoney(Number(l.menu.price) * cartLine.qty + optsTotal)

                    if (collapsed) {
                      const summary = optionsSummary(l.menu, cartLine)
                      return (
                        <div key={cartLine.key} className="flex items-center gap-2 border-b border-line px-2 py-1 last:border-b-0">
                          <div className="min-w-0 grow">
                            <div className="truncate text-sm font-semibold">
                              {l.menu.name}
                              {summary && <span className="font-normal text-muted"> — {summary}</span>}
                            </div>
                            <div className="text-xs text-muted">{lineTotal}</div>
                          </div>
                          <Button size="sm" variant="outline-secondary" onClick={() => toggleCollapsed(cartLine.key)}>Change</Button>
                          <button type="button" className="px-1 text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300" title="Remove"
                            onClick={() => removeLine(cartLine.key)}>×</button>
                        </div>
                      )
                    }

                    return (
                      <div key={cartLine.key} className="border-b border-line px-2 py-1 last:border-b-0">
                        <div className="flex items-center gap-2">
                          <div className="min-w-0 grow">
                            <div className="text-sm font-semibold">{l.menu.name}</div>
                            <div className="text-sm text-muted">
                              {cartLine.qty} × {formatMoney(l.menu.price)}{optsTotal > 0 && ` + ${formatMoney(optsTotal)}`}
                              {' '}= {lineTotal}
                            </div>
                          </div>
                          {isConfigured ? (
                            complete ? (
                              <Button size="sm" variant="outline-secondary" onClick={() => toggleCollapsed(cartLine.key)}>Done</Button>
                            ) : (
                              <span className="text-xs text-muted whitespace-nowrap">Pick required options below</span>
                            )
                          ) : (
                            <InputGroup style={{ width: 104 }}>
                              <Button size="sm" variant="outline-secondary" onClick={() => setLineQty(cartLine.key, cartLine.qty - 1)}>−</Button>
                              <Form.Control size="sm" className="text-center" value={cartLine.qty}
                                onChange={(e) => setLineQty(cartLine.key, parseInt(e.target.value, 10) || 0)} />
                              <Button size="sm" variant="outline-secondary" onClick={() => setLineQty(cartLine.key, cartLine.qty + 1)}>+</Button>
                            </InputGroup>
                          )}
                          <button type="button" className="px-1 text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300" title="Remove"
                            onClick={() => removeLine(cartLine.key)}>×</button>
                        </div>
                        {groups.length > 0 && (
                          <div className="mb-1 mt-1 ml-1 space-y-1 border-l-2 border-line pl-2">
                            {groups.map((group) => (group.kind === 'choice' ? (
                              <Form.Group key={group.id} className="mb-0">
                                <Form.Label className="mb-0.5 text-xs">{group.name}</Form.Label>
                                <Form.Select size="sm" required
                                  value={cartLine.choices?.[group.id] ?? ''}
                                  onChange={(e) => setChoice(cartLine.key, group.id, e.target.value)}>
                                  <option value="" disabled>Select…</option>
                                  {group.food_menu_item_options.map((opt) => (
                                    <option key={opt.id} value={opt.id}>
                                      {opt.label}
                                      {Number(opt.price_delta) > 0 ? ` (+${formatMoney(opt.price_delta)})` : ''}
                                    </option>
                                  ))}
                                </Form.Select>
                              </Form.Group>
                            ) : (
                              <div key={group.id}>
                                <div className="mb-0.5 text-xs text-muted">{group.name}</div>
                                {group.food_menu_item_options.map((opt) => {
                                  const oQty = cartLine.addons?.[opt.id] ?? 0
                                  return (
                                    <div key={opt.id} className="mb-1 flex items-center justify-between gap-2">
                                      <span className="text-sm">
                                        {opt.label}
                                        {Number(opt.price_delta) > 0 ? ` (+${formatMoney(opt.price_delta)} ea)` : ''}
                                      </span>
                                      <InputGroup style={{ width: 96 }}>
                                        <Button size="sm" variant="outline-secondary"
                                          onClick={() => setAddonQty(cartLine.key, opt.id, oQty - 1)}>−</Button>
                                        <Form.Control size="sm" className="text-center" value={oQty}
                                          onChange={(e) => setAddonQty(cartLine.key, opt.id, parseInt(e.target.value, 10) || 0)} />
                                        <Button size="sm" variant="outline-secondary"
                                          onClick={() => setAddonQty(cartLine.key, opt.id, oQty + 1)}>+</Button>
                                      </InputGroup>
                                    </div>
                                  )
                                })}
                              </div>
                            )))}
                          </div>
                        )}
                      </div>
                    )
                  })}
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
                      <button type="button" className="px-1 text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300" title="Remove"
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
                          <span>Senior/PWD discount (20%, {beneficiaries.length}/{totalDiners} diners)</span>
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
                  <div className="mb-1 flex items-center justify-between">
                    <Form.Label className="mb-0">
                      Senior/PWD discount <span className="font-normal text-muted">(optional — any number)</span>
                    </Form.Label>
                    <Button size="sm" variant="outline-secondary" onClick={addBeneficiary}>+ Add beneficiary</Button>
                  </div>
                  {beneficiaries.map((b) => (
                    <div key={b.key} className="mb-1 flex items-center gap-1">
                      <Form.Select size="sm" style={{ width: 90 }} value={b.discount_type}
                        onChange={setBeneficiaryField(b.key, 'discount_type')}>
                        <option value="senior">Senior</option>
                        <option value="pwd">PWD</option>
                      </Form.Select>
                      <Form.Control size="sm" value={b.name} onChange={setBeneficiaryField(b.key, 'name')}
                        placeholder="Beneficiary name" required />
                      <Form.Control size="sm" value={b.id_number} onChange={setBeneficiaryField(b.key, 'id_number')}
                        placeholder="ID number" required />
                      <button type="button" className="px-1 text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300" title="Remove"
                        onClick={() => removeBeneficiary(b.key)}>×</button>
                    </div>
                  ))}
                  {beneficiaries.length > 0 && (
                    <div className="mt-1">
                      <Form.Label className="mb-1">Total diners on this order</Form.Label>
                      <Form.Control size="sm" type="number" min={beneficiaries.length} style={{ width: 100 }}
                        value={totalDiners}
                        onChange={(e) => setTotalDiners(Math.max(beneficiaries.length, parseInt(e.target.value, 10) || beneficiaries.length))}
                        required />
                      <Form.Text muted>
                        Each beneficiary's 20% only covers their own share of the bill — {beneficiaries.length} of{' '}
                        {totalDiners} diner{totalDiners === 1 ? '' : 's'} qualify.
                      </Form.Text>
                    </div>
                  )}
                </Form.Group>
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
                    Guest {needGuest ? <span className="text-red-600 dark:text-red-400">*</span> : <span className="font-normal text-muted">(optional)</span>}
                  </Form.Label>
                  <GuestPicker guests={eligibleGuests} roomByGuest={roomByGuest} value={guestId}
                    onChange={setGuestId} required={needGuest} propertyId={propertyId} />
                  {needGuest && (
                    <Form.Text muted>Only guests currently checked in can be charged to their room.</Form.Text>
                  )}
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

let ingredientKeySeq = 0
const newIngredientKey = () => `ing-${++ingredientKeySeq}`
let optionGroupKeySeq = 0
const newOptionGroupKey = () => `grp-${++optionGroupKeySeq}`
let optionRowKeySeq = 0
const newOptionRowKey = () => `opt-${++optionRowKeySeq}`

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

  // A recipe: extra ingredients this dish consumes on top of (not instead of)
  // the single Linked Stock above — e.g. a menu item made of several
  // stocked ingredients (Eggplant, Hotdog, Egg, ...), each with how much one
  // serving uses. Ordering the item decrements every row here too.
  const [ingredients, setIngredients] = useState(
    () => (item?.food_menu_item_ingredients ?? []).map((ing) => ({
      key: newIngredientKey(),
      inventory_item_id: String(ing.inventory_item_id),
      quantity: ing.quantity,
    })),
  )
  const addIngredient = () => setIngredients([...ingredients, { key: newIngredientKey(), inventory_item_id: '', quantity: 1 }])
  const removeIngredient = (key) => setIngredients(ingredients.filter((r) => r.key !== key))
  const setIngredientField = (key, field) => (e) => {
    const value = e.target.value
    setIngredients(ingredients.map((r) => (r.key === key ? { ...r, [field]: value } : r)))
  }

  // Option groups: guest-facing picks, distinct from the silent recipe above.
  // A `choice` group is a free pick the guest must choose exactly one of (e.g.
  // "Choice of Drink"); an `addon` group lets the guest add any number of each
  // priced option (e.g. "Additional egg"). Either kind can optionally decrement
  // an inventory item when picked.
  const [optionGroups, setOptionGroups] = useState(
    () => (item?.food_menu_item_option_groups ?? []).map((g) => ({
      key: newOptionGroupKey(),
      name: g.name,
      kind: g.kind,
      options: (g.food_menu_item_options ?? []).map((o) => ({
        key: newOptionRowKey(),
        label: o.label,
        price_delta: o.price_delta,
        inventory_item_id: String(o.inventory_item_id ?? ''),
      })),
    })),
  )
  const addOptionGroup = () => setOptionGroups([
    ...optionGroups, { key: newOptionGroupKey(), name: '', kind: 'choice', options: [] },
  ])
  const removeOptionGroup = (key) => setOptionGroups(optionGroups.filter((g) => g.key !== key))
  const setOptionGroupField = (key, field) => (e) => {
    const value = e.target.value
    setOptionGroups(optionGroups.map((g) => (g.key === key ? { ...g, [field]: value } : g)))
  }
  const addOption = (groupKey) => setOptionGroups(optionGroups.map((g) => (g.key === groupKey
    ? { ...g, options: [...g.options, { key: newOptionRowKey(), label: '', price_delta: 0, inventory_item_id: '' }] }
    : g)))
  const removeOption = (groupKey, optionKey) => setOptionGroups(optionGroups.map((g) => (g.key === groupKey
    ? { ...g, options: g.options.filter((o) => o.key !== optionKey) }
    : g)))
  const setOptionField = (groupKey, optionKey, field) => (e) => {
    const value = e.target.value
    setOptionGroups(optionGroups.map((g) => (g.key === groupKey
      ? { ...g, options: g.options.map((o) => (o.key === optionKey ? { ...o, [field]: value } : o)) }
      : g)))
  }

  const stockOptions = useMemo(
    () => inventory.filter((i) => i.inventory_category?.kind === STOCK_KIND_FOR_TYPE[menuType]),
    [inventory, menuType],
  )

  const linkedItem = inventory.find((i) => String(i.id) === String(form.inventory_item_id))
  const outOfStock = linkedItem && Number(linkedItem.quantity) <= 0

  const { run, busy, err } = useSubmit(async () => {
    const validIngredients = ingredients
      .filter((r) => r.inventory_item_id)
      .map((r) => ({ inventory_item_id: Number(r.inventory_item_id), quantity: Number(r.quantity) || 0 }))
    const validOptionGroups = optionGroups
      .filter((g) => g.name.trim() && g.options.length)
      .map((g) => ({
        name: g.name,
        kind: g.kind,
        options: g.options
          .filter((o) => o.label.trim())
          .map((o) => ({
            label: o.label,
            price_delta: Number(o.price_delta) || 0,
            inventory_item_id: o.inventory_item_id || null,
          })),
      }))
    const payload = {
      ...form,
      type: menuType,
      inventory_item_id: form.inventory_item_id || null,
      ingredients: validIngredients,
      option_groups: validOptionGroups,
    }
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
              <Form.Text className="text-amber-600 dark:text-amber-400">
                This item is out of stock — it will be saved as unavailable.
              </Form.Text>
            )}
          </Form.Group>
          <Form.Group className="mt-4">
            <Form.Label>
              Ingredients (recipe — decrements per serving, on top of the linked stock above)
            </Form.Label>
            {ingredients.map((row) => {
              const rowItem = inventory.find((i) => String(i.id) === row.inventory_item_id)
              const rowOutOfStock = rowItem && Number(rowItem.quantity) <= 0
              return (
                <div key={row.key} className="mb-1 flex items-center gap-1">
                  <Form.Select size="sm" value={row.inventory_item_id} onChange={setIngredientField(row.key, 'inventory_item_id')}>
                    <option value="">Select ingredient…</option>
                    {stockOptions.map((i) => (
                      <option key={i.id} value={i.id}>{i.name} ({Number(i.quantity)} {i.unit})</option>
                    ))}
                  </Form.Select>
                  <Form.Control size="sm" type="number" min={0.01} step="0.01" value={row.quantity}
                    onChange={setIngredientField(row.key, 'quantity')} placeholder="Qty / serving"
                    style={{ width: 110 }} />
                  <button type="button" className="px-1 text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300" title="Remove"
                    onClick={() => removeIngredient(row.key)}>×</button>
                  {rowOutOfStock && <span className="text-xs text-amber-600 whitespace-nowrap dark:text-amber-400">out of stock</span>}
                </div>
              )
            })}
            <Button size="sm" variant="outline-secondary" onClick={addIngredient}>
              + Add ingredient
            </Button>
          </Form.Group>
          <Form.Group className="mt-4">
            <Form.Label>
              Option groups (guest-facing picks — a free Choice the guest must pick one of, or priced Add-ons they can add any number of)
            </Form.Label>
            {optionGroups.map((group) => (
              <Card key={group.key} className="mb-2">
                <Card.Body className="p-3">
                  <div className="mb-2 flex items-center gap-1">
                    <Form.Control size="sm" value={group.name} placeholder="Group name (e.g. Choice of Drink)"
                      onChange={setOptionGroupField(group.key, 'name')} />
                    <Form.Select size="sm" style={{ width: 230 }} value={group.kind}
                      onChange={setOptionGroupField(group.key, 'kind')}>
                      <option value="choice">Choice — guest picks one, free</option>
                      <option value="addon">Add-ons — priced extras</option>
                    </Form.Select>
                    <button type="button" className="px-1 text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300" title="Remove group"
                      onClick={() => removeOptionGroup(group.key)}>×</button>
                  </div>
                  {group.options.map((option) => {
                    const rowItem = inventory.find((i) => String(i.id) === option.inventory_item_id)
                    const rowOutOfStock = rowItem && Number(rowItem.quantity) <= 0
                    return (
                      <div key={option.key} className="mb-1 flex items-center gap-1">
                        <Form.Control size="sm" value={option.label} placeholder="Label (e.g. Coffee)"
                          onChange={setOptionField(group.key, option.key, 'label')} />
                        <Form.Control size="sm" type="number" min={0} step="0.01" value={option.price_delta}
                          onChange={setOptionField(group.key, option.key, 'price_delta')} placeholder="Price"
                          style={{ width: 90 }} />
                        <Form.Select size="sm" value={option.inventory_item_id}
                          onChange={setOptionField(group.key, option.key, 'inventory_item_id')}>
                          <option value="">Not linked</option>
                          {stockOptions.map((i) => (
                            <option key={i.id} value={i.id}>{i.name} ({Number(i.quantity)} {i.unit})</option>
                          ))}
                        </Form.Select>
                        <button type="button" className="px-1 text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300" title="Remove option"
                          onClick={() => removeOption(group.key, option.key)}>×</button>
                        {rowOutOfStock && <span className="text-xs text-amber-600 whitespace-nowrap dark:text-amber-400">out of stock</span>}
                      </div>
                    )
                  })}
                  <Button size="sm" variant="outline-secondary" onClick={() => addOption(group.key)}>
                    + Add option
                  </Button>
                </Card.Body>
              </Card>
            ))}
            <Button size="sm" variant="outline-secondary" onClick={addOptionGroup}>
              + Add option group
            </Button>
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
