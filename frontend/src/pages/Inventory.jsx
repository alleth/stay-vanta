import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Card, Table, Button, Badge, Modal, Form, Alert, Spinner, ButtonGroup,
} from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { useProperty } from '../context/PropertyContext'
import { useSubmit } from '../hooks/useSubmit'
import { SkeletonTable } from '../components/Skeleton'
import {
  listCategories, createCategory, deleteCategory,
  listItems, createItem, updateItem, deleteItem, listMovements, recordMovement,
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
  const [view, setView] = useState('consumable') // consumable | reusable | receipts
  const [expanded, setExpanded] = useState(() => new Set()) // parent items with sub-items open
  const [series, setSeries] = useState([]) // receipt booklet series

  const [modal, setModal] = useState(null) // 'category' | 'categories' | 'item' | 'move'
  const [moveTarget, setMoveTarget] = useState(null)
  const [editTarget, setEditTarget] = useState(null) // item being edited (null = new)
  const [pending, setPending] = useState(null) // key of the in-flight inline action

  const refresh = useCallback(async () => {
    if (!propertyId) return
    try {
      const [c, i, m, s] = await Promise.all([
        listCategories(propertyId),
        listItems(propertyId),
        listMovements(propertyId),
        listReceiptSeries(propertyId),
      ])
      setCategories(c)
      setItems(i)
      setMovements(m)
      setSeries(s)
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

  // Consumables can be itemized: sub-items nest under a parent (one level).
  const childrenByParent = useMemo(() => {
    const m = new Map()
    for (const it of items) {
      if (it.parent_id) {
        if (!m.has(it.parent_id)) m.set(it.parent_id, [])
        m.get(it.parent_id).push(it)
      }
    }
    return m
  }, [items])
  const topLevel = useMemo(() => shown.filter((it) => !it.parent_id), [shown])

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
      await refresh()
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

      {loading ? (
        <SkeletonTable rows={6} />
      ) : view === 'receipts' ? (
        <ReceiptBooklets series={series} canManage={canManage} propertyId={propertyId} onChanged={refresh} />
      ) : (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
          <div className="lg:col-span-8">
            <Card className="shadow-sm">
              <Card.Header>
                {reusable ? 'Reusable items (issued & returned)' : 'Consumable items'}
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
                  {shown.length === 0 && (
                    <tr>
                      <td colSpan={reusable ? 8 : 6} className="py-6 text-center text-muted">
                        No {reusable ? 'reusable' : 'consumable'} items yet.
                      </td>
                    </tr>
                  )}
                  {(reusable ? shown : topLevel)
                    .flatMap((p) => [
                      { it: p, isChild: false },
                      ...(!reusable && expanded.has(p.id)
                        ? (childrenByParent.get(p.id) ?? []).map((c) => ({ it: c, isChild: true }))
                        : []),
                    ])
                    .map(({ it, isChild }) => {
                    const kids = reusable ? [] : childrenByParent.get(it.id) ?? []
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
                            it.name
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
                            <Button size="sm" variant="outline-primary" className="mr-1"
                              disabled={pending !== null}
                              onClick={() => { setEditTarget(it); setModal('item') }}>Edit</Button>
                          )}
                          {canManage && (
                            <Button size="sm" variant="outline-danger" className="mr-1"
                              disabled={pending !== null}
                              onClick={() => doDeleteItem(it)}>
                              {pending === `item-${it.id}` ? <Spinner size="sm" /> : 'Delete'}
                            </Button>
                          )}
                          {canManage && (reusable ? (
                            <ButtonGroup className="inline-flex align-middle">
                              {Object.entries(REUSABLE_ACTIONS).map(([key, a]) => (
                                <Button key={key} size="sm" variant={a.variant} onClick={() => openMove(it, key)}>
                                  {a.label}
                                </Button>
                              ))}
                            </ButtonGroup>
                          ) : (
                            <ButtonGroup className="inline-flex align-middle">
                              <Button size="sm" variant="outline-success" onClick={() => openMove(it, 'in')}>In</Button>
                              <Button size="sm" variant="outline-danger" onClick={() => openMove(it, 'out')}>Out</Button>
                            </ButtonGroup>
                          ))}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </Table>
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
          items={items}
          onClose={() => setModal(null)}
          onChanged={refresh}
        />
      )}
      {modal === 'item' && (
        <ItemModal
          propertyId={propertyId}
          categories={categories}
          items={items}
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

function CategoriesModal({ categories, items, onClose, onChanged }) {
  const [busyId, setBusyId] = useState(null)
  const [err, setErr] = useState(null)
  const countFor = (id) => items.filter((i) => i.inventory_category_id === id).length

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
                    {used > 0 && <span className="ml-2 text-sm text-muted">· {used} item(s)</span>}
                  </span>
                  <Button size="sm" variant="outline-danger" disabled={busyId !== null || used > 0}
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

function ItemModal({ propertyId, categories, items = [], item, defaultTracking, onClose, onSaved }) {
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
  // Valid parents: top-level consumables (one level deep), never itself. An
  // item that already has sub-items can't become one — the backend enforces
  // the same.
  const hasChildren = editing && items.some((i) => i.parent_id === item.id)
  const parentOptions = items.filter(
    (i) => trackingOf(i) === 'consumable' && !i.parent_id && i.id !== item?.id,
  )
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

// A number the way it reads on the physical page (prefix + zero-padded digits).
const seriesNumber = (s, n) => `${s.prefix ?? ''}${String(n).padStart(s.pad_length ?? 0, '0')}`

function ReceiptBooklets({ series, canManage, propertyId, onChanged }) {
  const [modal, setModal] = useState(false)
  const [pending, setPending] = useState(null)
  const [err, setErr] = useState(null)

  async function act(key, fn) {
    setPending(key)
    setErr(null)
    try {
      await fn()
      await onChanged()
    } catch (ex) {
      setErr(ex?.response?.data?.message ?? 'Action failed.')
    } finally {
      setPending(null)
    }
  }

  return (
    <div>
      {err && <Alert variant="danger">{err}</Alert>}
      <Card className="shadow-sm">
        <Card.Header className="flex items-center justify-between px-4 py-3">
          <span>Receipt booklets</span>
          {canManage && (
            <Button size="sm" onClick={() => setModal(true)}>Register series</Button>
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
            {series.length === 0 && (
              <tr>
                <td colSpan={canManage ? 6 : 5} className="py-6 text-center text-muted">
                  No booklet series registered yet.
                </td>
              </tr>
            )}
            {series.map((s) => {
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
          onSaved={async () => { setModal(false); await onChanged() }}
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
