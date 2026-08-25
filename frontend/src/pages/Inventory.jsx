import { useCallback, useEffect, useState } from 'react'
import {
  Card, Table, Button, Badge, Modal, Form, Alert, Spinner, ButtonGroup, InputGroup, Pagination, Dropdown,
} from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { useProperty } from '../context/PropertyContext'
import { useSubmit } from '../hooks/useSubmit'
import { SkeletonTable, SkeletonTableRows } from '../components/Skeleton'
import {
  listCategories, createCategory, deleteCategory,
  listItems, listItemsPage, createItem, updateItem, deleteItem, listMovements, recordMovement,
  listReceiptSeries, createReceiptSeries, updateReceiptSeries, deleteReceiptSeries,
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

const fmtDate = (s) =>
  s ? new Date(s).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '—'
const fmtDateTime = (s) =>
  s ? new Date(s).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—'

const ITEMS_PER_PAGE = 20

export default function Inventory() {
  const { role } = useAuth()
  const { propertyId } = useProperty()
  // Receptionists operate the catalogue read-only: no category creation, no
  // manual stock moves, no edits. Stock leaves via Food & Orders instead.
  const canManage = role === 'owner' || role === 'admin'
  const [categories, setCategories] = useState([])
  const [movements, setMovements] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [view, setView] = useState('consumable') // consumable | reusable | receipts
  const [expanded, setExpanded] = useState(() => new Set()) // parent items with sub-items open

  // The Consumables/Reusables table is server-paginated and searchable (the
  // catalogue can grow large). `children` maps a top-level consumable's id to
  // its own sub-items, riding along with its page — see listItemsPage.
  const [items, setItems] = useState([])
  const [itemsChildren, setItemsChildren] = useState({})
  const [itemsTotal, setItemsTotal] = useState(0)
  const [itemsLoading, setItemsLoading] = useState(false)
  const [itemsPage, setItemsPage] = useState(1)
  const [itemsSearch, setItemsSearch] = useState('') // raw input, debounced below
  const [itemsQ, setItemsQ] = useState('') // debounced value actually sent to the server

  const [modal, setModal] = useState(null) // 'category' | 'categories' | 'item' | 'move'
  const [moveTarget, setMoveTarget] = useState(null)
  const [editTarget, setEditTarget] = useState(null) // item being edited (null = new)
  const [pending, setPending] = useState(null) // key of the in-flight inline action

  const loadBase = useCallback(async () => {
    if (!propertyId) return
    try {
      const [c, m] = await Promise.all([
        listCategories(propertyId),
        listMovements(propertyId),
      ])
      setCategories(c)
      setMovements(m)
      setError(null)
    } catch {
      setError('Could not load inventory.')
    } finally {
      setLoading(false)
    }
  }, [propertyId])

  const loadItems = useCallback(async () => {
    if (!propertyId || view === 'receipts') return
    setItemsLoading(true)
    try {
      const params = { tracking_type: view, page: itemsPage, limit: ITEMS_PER_PAGE }
      if (view === 'consumable') params.top_level = 1
      if (itemsQ) params.q = itemsQ
      const data = await listItemsPage(propertyId, params)
      setItems(data.items ?? [])
      setItemsChildren(data.children ?? {})
      setItemsTotal(data.total ?? 0)
      setError(null)
    } catch {
      setError('Could not load inventory items.')
    } finally {
      setItemsLoading(false)
    }
  }, [propertyId, view, itemsPage, itemsQ])

  useEffect(() => {
    // loadBase()/loadItems() only update state after awaiting the network,
    // but the lint rule can't see through the async boundary. Safe
    // data-loading effects.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadBase()
  }, [loadBase])
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadItems()
  }, [loadItems])

  // Debounce the search box; jump back to page 1 whenever the effective
  // search text changes.
  useEffect(() => {
    const t = setTimeout(() => { setItemsQ(itemsSearch.trim()); setItemsPage(1) }, 300)
    return () => clearTimeout(t)
  }, [itemsSearch])

  // Switching tabs resets paging/search/expansion immediately. Not a
  // network-triggering effect (no async boundary), so the setState-in-effect
  // rule applies for real here — these mirror derived state off `view`
  // rather than syncing with an external system, which is what the rule
  // actually guards against; disabling is the documented escape hatch.
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setItemsPage(1)
    setItemsSearch('')
    setItemsQ('')
    setExpanded(new Set())
  }, [view])

  // Deleting the last item on a page (other than the first) would otherwise
  // strand the view on an empty page.
  useEffect(() => {
    if (!itemsLoading && items.length === 0 && itemsPage > 1) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setItemsPage((p) => p - 1)
    }
  }, [itemsLoading, items, itemsPage])

  function refresh() {
    loadBase()
    loadItems()
  }

  const reusable = view === 'reusable'
  const itemsTotalPages = Math.max(1, Math.ceil(itemsTotal / ITEMS_PER_PAGE))

  const toggleExpanded = (id) =>
    setExpanded((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })

  const openMove = (item, action) => { setMoveTarget({ item, action }); setModal('move') }

  async function doDeleteItem(item) {
    if (!window.confirm(`Delete "${item.name}"? It will be removed from inventory; its stock history is kept.`)) return
    setPending(`item-${item.id}`)
    setError(null)
    try {
      await deleteItem(item.id)
      refresh()
    } catch (ex) {
      setError(ex?.response?.data?.message ?? 'Could not delete the item.')
    } finally {
      setPending(null)
    }
  }

  if (!propertyId)
    return <Alert variant="info">Select or create a property to manage inventory.</Alert>

  return (
    <div>
      <div className="mb-4 flex items-center justify-between">
        <div>
          <h1 className="mb-0 text-2xl font-bold">Inventory</h1>
          <small className="text-muted">
            Every in/out movement records the acting receptionist.
          </small>
        </div>
        {canManage && (
          <div className="flex gap-2">
            <Button variant="outline-secondary" onClick={() => setModal('categories')}>
              Manage categories
            </Button>
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

      <ButtonGroup className="mb-4">
        {[['consumable', 'Consumables'], ['reusable', 'Reusables'], ['receipts', 'Receipt Booklets']].map(([val, label]) => (
          <Button
            key={val}
            variant={view === val ? 'primary' : 'outline-secondary'}
            onClick={() => setView(val)}
          >
            {label}
          </Button>
        ))}
      </ButtonGroup>

      {view === 'receipts' ? (
        <ReceiptBooklets canManage={canManage} propertyId={propertyId} />
      ) : loading ? (
        <SkeletonTable rows={6} />
      ) : (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
          <div className="lg:col-span-8">
            <Card className="shadow-sm">
              <Card.Header className="flex flex-wrap items-center gap-2">
                <span>{reusable ? 'Reusable items (issued & returned)' : 'Consumable items'}</span>
                <InputGroup className="ml-auto" style={{ maxWidth: 260 }}>
                  <InputGroup.Text>Search</InputGroup.Text>
                  <Form.Control
                    value={itemsSearch}
                    onChange={(e) => setItemsSearch(e.target.value)}
                    placeholder="Item name"
                  />
                </InputGroup>
                <span className="text-sm font-normal text-muted">{itemsTotal} item(s)</span>
              </Card.Header>
              <Table hover>
                <thead>
                  <tr>
                    <th>Item</th>
                    <th>Category</th>
                    {reusable ? (
                      <>
                        <th className="text-right">Available</th>
                        <th className="text-right">In use</th>
                        <th className="text-right">Total</th>
                      </>
                    ) : (
                      <th className="text-right">On hand</th>
                    )}
                    <th>Date added</th>
                    <th>Last receptionist</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  {itemsLoading && <SkeletonTableRows rows={5} cols={reusable ? 8 : 6} />}
                  {!itemsLoading && items.length === 0 && (
                    <tr>
                      <td colSpan={reusable ? 8 : 6} className="py-6 text-center text-muted">
                        {itemsQ
                          ? 'No items match your search.'
                          : `No ${reusable ? 'reusable' : 'consumable'} items yet.`}
                      </td>
                    </tr>
                  )}
                  {!itemsLoading && items
                    .flatMap((p) => [
                      { it: p, isChild: false },
                      ...(!reusable && !itemsQ && expanded.has(p.id)
                        ? (itemsChildren[p.id] ?? []).map((c) => ({ it: c, isChild: true }))
                        : []),
                    ])
                    .map(({ it, isChild }) => {
                    const kids = (!reusable && !itemsQ) ? (itemsChildren[it.id] ?? []) : []
                    const isOpen = expanded.has(it.id)
                    const available = Number(it.quantity)
                    const total = Number(it.total_quantity ?? 0)
                    const inUse = Math.max(0, total - available)
                    const low = available <= Number(it.reorder_level)
                    return (
                      <tr key={it.id}>
                        <td className="font-semibold">
                          {isChild ? (
                            <span className="inline-flex items-center">
                              <span className="mr-2 ml-1 text-muted">↳</span>
                              {it.name}
                            </span>
                          ) : kids.length > 0 ? (
                            <button
                              type="button"
                              className="inline-flex items-center gap-1.5"
                              title={isOpen ? 'Hide sub-items' : 'Show sub-items'}
                              onClick={() => toggleExpanded(it.id)}
                            >
                              <span className="text-[10px] text-muted">{isOpen ? '▼' : '▶'}</span>
                              {it.name}
                              <Badge bg="secondary">{kids.length}</Badge>
                            </button>
                          ) : (
                            <>
                              {it.parent_id && itemsQ && (
                                <span className="mr-1.5 text-[10px] text-muted" title="Sub-item">↳</span>
                              )}
                              {it.name}
                            </>
                          )}
                        </td>
                        <td>{it.inventory_category?.name ?? '—'}</td>
                        {reusable ? (
                          <>
                            <td className="text-right">
                              {available} {low && <Badge bg="warning">low</Badge>}
                            </td>
                            <td className="text-right">{inUse}</td>
                            <td className="text-right">{total}</td>
                          </>
                        ) : (
                          <td className="text-right">
                            {available} {it.unit}{' '}
                            {low && <Badge bg="warning">low</Badge>}
                          </td>
                        )}
                        <td className="whitespace-nowrap text-xs text-muted">
                          {fmtDate(it.created)}
                        </td>
                        <td className="text-xs text-muted">
                          {it.last_receptionist?.name ?? '—'}
                        </td>
                        <td className="whitespace-nowrap text-right">
                          {canManage && (
                            <Dropdown
                              align="end"
                              disabled={pending === `item-${it.id}`}
                              toggle={pending === `item-${it.id}` ? <Spinner size="sm" /> : undefined}
                            >
                              {Object.entries(reusable ? REUSABLE_ACTIONS : CONSUMABLE_ACTIONS).map(([key, a]) => (
                                <Dropdown.Item key={key} onClick={() => openMove(it, key)}>
                                  <span className={a.direction === 'in' ? 'text-emerald-600' : 'text-red-600'}>
                                    {a.direction === 'in' ? '↓' : '↑'}
                                  </span>
                                  {a.label}
                                </Dropdown.Item>
                              ))}
                              <Dropdown.Divider />
                              <Dropdown.Item onClick={() => { setEditTarget(it); setModal('item') }}>
                                Edit
                              </Dropdown.Item>
                              <Dropdown.Item danger onClick={() => doDeleteItem(it)}>
                                Delete
                              </Dropdown.Item>
                            </Dropdown>
                          )}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </Table>
              {itemsTotalPages > 1 && (
                <Card.Footer className="flex items-center justify-between px-4 py-3">
                  <span className="text-sm text-muted">
                    Page {itemsPage} of {itemsTotalPages} · {itemsTotal} item(s)
                  </span>
                  <Pagination>
                    <Pagination.Prev disabled={itemsPage <= 1 || itemsLoading}
                      onClick={() => setItemsPage((p) => Math.max(1, p - 1))} />
                    <Pagination.Next disabled={itemsPage >= itemsTotalPages || itemsLoading}
                      onClick={() => setItemsPage((p) => Math.min(itemsTotalPages, p + 1))} />
                  </Pagination>
                </Card.Footer>
              )}
            </Card>
          </div>

          <div className="lg:col-span-4">
            <Card className="shadow-sm">
              <Card.Header>Recent movements</Card.Header>
              <Card.Body className="max-h-[460px] overflow-y-auto p-0">
                {movements.length === 0 ? (
                  <p className="mb-0 p-4 text-sm text-muted">No movements yet.</p>
                ) : (
                  <ul className="divide-y divide-line">
                    {movements.map((m) => (
                      <li key={m.id} className="px-4 py-2.5">
                        <div className="flex items-start justify-between gap-2">
                          <div className="min-w-0 flex-1">
                            <div className="truncate text-sm font-semibold">{m.inventory_item?.name}</div>
                            {m.note && (
                              <div className="mt-0.5 inline-block rounded-md bg-accent-soft px-2 py-0.5 text-xs text-accent">
                                {m.note}
                              </div>
                            )}
                            <div className="mt-0.5 text-xs text-muted">
                              {m.receptionist?.name} · {m.reason ?? 'movement'}
                            </div>
                          </div>
                          <div className="shrink-0 text-right">
                            <Badge bg={m.direction === 'in' ? 'success' : 'danger'}>
                              {m.direction === 'in' ? '+' : '−'}{Number(m.quantity)}
                            </Badge>
                            <div className="mt-1 whitespace-nowrap text-[11px] leading-tight text-muted">
                              {fmtDateTime(m.created)}
                            </div>
                          </div>
                        </div>
                      </li>
                    ))}
                  </ul>
                )}
              </Card.Body>
            </Card>
          </div>
        </div>
      )}

      {modal === 'category' && (
        <CategoryModal
          propertyId={propertyId}
          onClose={() => setModal(null)}
          onSaved={() => { setModal(null); refresh() }}
        />
      )}
      {modal === 'categories' && (
        <CategoriesModal
          categories={categories}
          propertyId={propertyId}
          onClose={() => setModal(null)}
          onChanged={refresh}
        />
      )}
      {modal === 'item' && (
        <ItemModal
          propertyId={propertyId}
          categories={categories}
          item={editTarget}
          defaultTracking={view === 'reusable' ? 'reusable' : 'consumable'}
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
          <Form.Group className="mb-4">
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

function CategoriesModal({ categories, propertyId, onClose, onChanged }) {
  const [busyId, setBusyId] = useState(null)
  const [err, setErr] = useState(null)
  // Per-category item counts: fetched once when the modal opens (rather than
  // kept loaded on every Inventory page view) since the catalogue can grow
  // large. Capped at the same generous, effectively-unpaginated window
  // listItems() always returns.
  const [itemCounts, setItemCounts] = useState({})
  const [countsLoading, setCountsLoading] = useState(true)

  useEffect(() => {
    let cancelled = false
    listItems(propertyId)
      .then((rows) => {
        if (cancelled) return
        const counts = {}
        for (const r of rows) counts[r.inventory_category_id] = (counts[r.inventory_category_id] ?? 0) + 1
        setItemCounts(counts)
      })
      .catch(() => {})
      .finally(() => { if (!cancelled) setCountsLoading(false) })
    return () => { cancelled = true }
  }, [propertyId])

  const countFor = (id) => itemCounts[id] ?? 0

  async function remove(c) {
    if (!window.confirm(`Delete category "${c.name}"?`)) return
    setBusyId(c.id)
    setErr(null)
    try {
      await deleteCategory(c.id)
      await onChanged()
    } catch (ex) {
      setErr(ex?.response?.data?.message ?? 'Could not delete the category.')
    } finally {
      setBusyId(null)
    }
  }

  return (
    <Modal show onHide={onClose} centered>
      <Modal.Header closeButton><Modal.Title>Manage categories</Modal.Title></Modal.Header>
      <Modal.Body>
        {err && <Alert variant="danger">{err}</Alert>}
        {categories.length === 0 ? (
          <p className="mb-0 text-muted">No categories yet.</p>
        ) : (
          <ul className="divide-y divide-line">
            {categories.map((c) => {
              const used = countFor(c.id)
              return (
                <li key={c.id} className="flex items-center justify-between py-3">
                  <span>
                    <span className="font-semibold">{c.name}</span>
                    <span className="ml-2 text-sm text-muted">{c.kind?.replace('_', ' ')}</span>
                    {!countsLoading && used > 0 && <span className="ml-2 text-sm text-muted">· {used} item(s)</span>}
                  </span>
                  <Button size="sm" variant="outline-danger" disabled={busyId !== null || countsLoading || used > 0}
                    title={used > 0 ? 'Move or delete its items first' : 'Delete category'}
                    onClick={() => remove(c)}>
                    {busyId === c.id ? <Spinner size="sm" /> : 'Delete'}
                  </Button>
                </li>
              )
            })}
          </ul>
        )}
      </Modal.Body>
      <Modal.Footer>
        <Button variant="secondary" onClick={onClose}>Close</Button>
      </Modal.Footer>
    </Modal>
  )
}

function ItemModal({ propertyId, categories, item, defaultTracking, onClose, onSaved }) {
  const editing = Boolean(item)
  const [form, setForm] = useState({
    inventory_category_id: item?.inventory_category_id ?? categories[0]?.id ?? '',
    parent_id: item?.parent_id ?? '',
    name: item?.name ?? '',
    tracking_type: item?.tracking_type ?? defaultTracking ?? 'consumable',
    unit: item?.unit ?? 'pcs',
    reorder_level: item?.reorder_level ?? 0,
    quantity: 0,
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const reusable = form.tracking_type === 'reusable'
  const typeChanged = editing && form.tracking_type !== item.tracking_type
  const hasChildren = editing && Boolean(item.has_children)

  // Valid parents: top-level consumables (one level deep), never itself. The
  // catalogue can grow large, so this is fetched once when the modal opens
  // rather than kept loaded on every Inventory page view.
  const [parentOptions, setParentOptions] = useState([])
  useEffect(() => {
    let cancelled = false
    listItems(propertyId, { tracking_type: 'consumable', top_level: 1 })
      .then((rows) => {
        if (!cancelled) setParentOptions(rows.filter((i) => i.id !== item?.id))
      })
      .catch(() => {})
    return () => { cancelled = true }
  }, [propertyId, item?.id])

  const { run, busy, err } = useSubmit(async () => {
    const payload = { ...form, parent_id: reusable ? null : form.parent_id || null }
    if (editing) {
      delete payload.quantity // never edit quantity directly
      await updateItem(item.id, payload)
    } else {
      await createItem(payload, propertyId)
    }
    onSaved()
  })
  return (
    <Modal show onHide={onClose} centered>
      <Form onSubmit={run}>
        <Modal.Header closeButton><Modal.Title>{editing ? 'Edit item' : 'New item'}</Modal.Title></Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          <Form.Group className="mb-4">
            <Form.Label>Type</Form.Label>
            <Form.Select value={form.tracking_type} onChange={set('tracking_type')}>
              <option value="consumable">Consumable (depletes when used)</option>
              <option value="reusable">Reusable (issued out & returned)</option>
            </Form.Select>
            {typeChanged && (
              <Form.Text className="text-amber-600">
                {reusable
                  ? 'Switching to reusable: current on-hand becomes the owned total.'
                  : 'Switching to consumable: the owned-total tracking is dropped.'}
              </Form.Text>
            )}
          </Form.Group>
          <Form.Group className="mb-4">
            <Form.Label>Category</Form.Label>
            <Form.Select value={form.inventory_category_id} onChange={set('inventory_category_id')} required>
              {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Form.Select>
          </Form.Group>
          {!reusable && !hasChildren && (
            <Form.Group className="mb-4">
              <Form.Label>
                Under item <span className="font-normal text-muted">(optional — makes this a sub-item)</span>
              </Form.Label>
              <Form.Select value={form.parent_id} onChange={set('parent_id')}>
                <option value="">None — top-level item</option>
                {parentOptions.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </Form.Select>
            </Form.Group>
          )}
          <Form.Group className="mb-4">
            <Form.Label>Name</Form.Label>
            <Form.Control value={form.name} onChange={set('name')} required autoFocus />
          </Form.Group>
          <div className={`grid gap-x-6 ${editing ? 'grid-cols-2' : 'grid-cols-3'}`}>
            <Form.Group className="mb-4">
              <Form.Label>Unit</Form.Label>
              <Form.Control value={form.unit} onChange={set('unit')} />
            </Form.Group>
            <Form.Group className="mb-4">
              <Form.Label>Reorder level</Form.Label>
              <Form.Control type="number" min={0} value={form.reorder_level} onChange={set('reorder_level')} />
            </Form.Group>
            {!editing && (
              <Form.Group className="mb-4">
                <Form.Label>{reusable ? 'Units owned' : 'Opening qty'}</Form.Label>
                <Form.Control type="number" min={0} value={form.quantity} onChange={set('quantity')} />
              </Form.Group>
            )}
          </div>
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
  const [note, setNote] = useState('')
  const available = Number(item.quantity)
  const total = Number(item.total_quantity ?? 0)
  const inUse = Math.max(0, total - available)
  // Adding stock (restock / acquire) can carry details of what exactly came in.
  const isAddingStock = action === 'in' || action === 'acquire'
  const { run, busy, err } = useSubmit(async () => {
    await recordMovement(
      {
        inventory_item_id: item.id,
        direction: a.direction,
        quantity: Number(quantity),
        affects_total: a.affects_total,
        reason: reason || a.reason,
        note: note.trim() || undefined,
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
          <p className="text-sm text-muted">
            {reusable
              ? `Available: ${available} · In use: ${inUse} · Total owned: ${total}.`
              : `On hand: ${available} ${item.unit}.`}{' '}
            This movement is recorded against you.
          </p>
          <Form.Group className="mb-4">
            <Form.Label>Quantity</Form.Label>
            <Form.Control
              type="number" min={1} value={quantity}
              onChange={(e) => setQuantity(e.target.value)} required autoFocus
            />
          </Form.Group>
          {isAddingStock && (
            <Form.Group className="mb-4">
              <Form.Label>Details of what was added <span className="font-normal text-muted">(optional)</span></Form.Label>
              <Form.Control
                value={note} onChange={(e) => setNote(e.target.value)}
                placeholder="e.g. hotdog, chorizo — 2 packs each"
              />
              <Form.Text muted>Shown in Recent movements so anyone can see what came in.</Form.Text>
            </Form.Group>
          )}
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

/* ---- Receipt booklets: registered physical invoice / OR number series ---- */

const SERIES_TYPE_LABEL = { invoice: 'Physical Invoice', official_receipt: 'Official Receipt' }
const SERIES_PER_PAGE = 10

// A number the way it reads on the physical page (prefix + zero-padded digits).
const seriesNumber = (s, n) => `${s.prefix ?? ''}${String(n).padStart(s.pad_length ?? 0, '0')}`

function ReceiptBooklets({ canManage, propertyId }) {
  const [modal, setModal] = useState(false)
  const [pending, setPending] = useState(null)
  const [err, setErr] = useState(null)

  // Server-paginated and searchable (by prefix), like the other Inventory tables.
  const [series, setSeries] = useState([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [q, setQ] = useState('')

  useEffect(() => {
    const t = setTimeout(() => { setQ(search.trim()); setPage(1) }, 300)
    return () => clearTimeout(t)
  }, [search])

  const load = useCallback(async () => {
    if (!propertyId) return
    setLoading(true)
    try {
      const params = { page, limit: SERIES_PER_PAGE }
      if (q) params.q = q
      const data = await listReceiptSeries(propertyId, params)
      setSeries(data.series ?? [])
      setTotal(data.total ?? 0)
      setErr(null)
    } catch {
      setErr('Could not load receipt booklets.')
    } finally {
      setLoading(false)
    }
  }, [propertyId, page, q])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load()
  }, [load])

  async function act(key, fn) {
    setPending(key)
    setErr(null)
    try {
      await fn()
      await load()
    } catch (ex) {
      setErr(ex?.response?.data?.message ?? 'Action failed.')
    } finally {
      setPending(null)
    }
  }

  const totalPages = Math.max(1, Math.ceil(total / SERIES_PER_PAGE))

  return (
    <div>
      {err && <Alert variant="danger">{err}</Alert>}
      <Card className="shadow-sm">
        <Card.Header className="flex flex-wrap items-center gap-2 px-4 py-3">
          <span>Receipt booklets</span>
          <InputGroup style={{ maxWidth: 220 }}>
            <InputGroup.Text>Search</InputGroup.Text>
            <Form.Control value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Prefix" />
          </InputGroup>
          <span className="text-sm font-normal text-muted">{total} series</span>
          {canManage && (
            <Button size="sm" className="ml-auto" onClick={() => setModal(true)}>Register series</Button>
          )}
        </Card.Header>
        <Table hover>
          <thead>
            <tr>
              <th>Type</th><th>Series</th><th>Next number</th>
              <th className="text-right">Remaining</th><th>Status</th>
              {canManage && <th className="text-right">Actions</th>}
            </tr>
          </thead>
          <tbody>
            {loading && <SkeletonTableRows rows={4} cols={canManage ? 6 : 5} />}
            {!loading && series.length === 0 && (
              <tr>
                <td colSpan={canManage ? 6 : 5} className="py-6 text-center text-muted">
                  {q ? 'No booklet series match your search.' : 'No booklet series registered yet.'}
                </td>
              </tr>
            )}
            {!loading && series.map((s) => {
              const remaining = Math.max(0, s.end_number - s.next_number + 1)
              const exhausted = remaining === 0
              return (
                <tr key={s.id}>
                  <td className="font-semibold">{SERIES_TYPE_LABEL[s.type] ?? s.type}</td>
                  <td className="whitespace-nowrap">
                    {seriesNumber(s, s.start_number)} – {seriesNumber(s, s.end_number)}
                  </td>
                  <td className="whitespace-nowrap">
                    {exhausted
                      ? <span className="text-muted">— exhausted —</span>
                      : seriesNumber(s, s.next_number)}
                  </td>
                  <td className="text-right">{remaining}</td>
                  <td>
                    <Badge bg={s.is_active && !exhausted ? 'success' : 'secondary'}>
                      {exhausted ? 'used up' : s.is_active ? 'active' : 'inactive'}
                    </Badge>
                  </td>
                  {canManage && (
                    <td className="whitespace-nowrap text-right">
                      <Button size="sm" variant="outline-secondary" className="mr-1"
                        disabled={pending !== null}
                        onClick={() => act(`toggle-${s.id}`, () => updateReceiptSeries(s.id, { is_active: !s.is_active }))}>
                        {pending === `toggle-${s.id}` ? <Spinner size="sm" /> : s.is_active ? 'Deactivate' : 'Activate'}
                      </Button>
                      <Button size="sm" variant="outline-danger"
                        disabled={pending !== null}
                        onClick={() => {
                          if (window.confirm('Delete this unused series?')) {
                            act(`del-${s.id}`, () => deleteReceiptSeries(s.id))
                          }
                        }}>
                        {pending === `del-${s.id}` ? <Spinner size="sm" /> : 'Delete'}
                      </Button>
                    </td>
                  )}
                </tr>
              )
            })}
          </tbody>
        </Table>
        {totalPages > 1 && (
          <Card.Footer className="flex items-center justify-between px-4 py-3">
            <span className="text-sm text-muted">Page {page} of {totalPages} · {total} series</span>
            <Pagination>
              <Pagination.Prev disabled={page <= 1 || loading}
                onClick={() => setPage((p) => Math.max(1, p - 1))} />
              <Pagination.Next disabled={page >= totalPages || loading}
                onClick={() => setPage((p) => Math.min(totalPages, p + 1))} />
            </Pagination>
          </Card.Footer>
        )}
      </Card>
      <p className="mt-2 mb-0 text-sm text-muted">
        Register your pre-printed <strong>Sales Invoice</strong> and <strong>Official Receipt</strong> booklets
        here. When an invoice is settled in Food &amp; Orders, the receptionist can mark which document was
        issued and the system stamps the next number from the active series onto the record. A series with
        issued numbers can be deactivated but not deleted.
      </p>

      {modal && (
        <SeriesModal
          propertyId={propertyId}
          onClose={() => setModal(false)}
          onSaved={async () => { setModal(false); await load() }}
        />
      )}
    </div>
  )
}

function SeriesModal({ propertyId, onClose, onSaved }) {
  const [form, setForm] = useState({ type: 'invoice', prefix: '', start_number: '', end_number: '' })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const { run, busy, err } = useSubmit(async () => {
    await createReceiptSeries(form, propertyId)
    onSaved()
  })

  const preview = form.start_number
    ? `${form.prefix}${form.start_number}`
    : null

  return (
    <Modal show onHide={onClose} centered>
      <Form onSubmit={run}>
        <Modal.Header closeButton><Modal.Title>Register booklet series</Modal.Title></Modal.Header>
        <Modal.Body>
          {err && <Alert variant="danger">{err}</Alert>}
          <Form.Group className="mb-4">
            <Form.Label>Type</Form.Label>
            <Form.Select value={form.type} onChange={set('type')} autoFocus>
              <option value="invoice">Physical Invoice (Sales Invoice)</option>
              <option value="official_receipt">Official Receipt</option>
            </Form.Select>
          </Form.Group>
          <Form.Group className="mb-4">
            <Form.Label>Prefix <span className="font-normal text-muted">(optional, e.g. &quot;OR-&quot;)</span></Form.Label>
            <Form.Control value={form.prefix} onChange={set('prefix')} placeholder="e.g. OR-" />
          </Form.Group>
          <div className="grid grid-cols-2 gap-x-6">
            <Form.Group className="mb-4">
              <Form.Label>Start number</Form.Label>
              <Form.Control value={form.start_number} onChange={set('start_number')}
                required inputMode="numeric" pattern="\d+" placeholder="e.g. 0001" />
              <Form.Text muted>Type it with leading zeros to keep the padding.</Form.Text>
            </Form.Group>
            <Form.Group className="mb-4">
              <Form.Label>End number</Form.Label>
              <Form.Control type="number" min={0} value={form.end_number} onChange={set('end_number')}
                required placeholder="e.g. 500" />
            </Form.Group>
          </div>
          {preview && (
            <p className="mb-0 text-sm text-muted">
              First number to be issued: <strong className="text-body">{preview}</strong>
            </p>
          )}
        </Modal.Body>
        <Modal.Footer>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={busy}>{busy ? <Spinner size="sm" /> : 'Register'}</Button>
        </Modal.Footer>
      </Form>
    </Modal>
  )
}
