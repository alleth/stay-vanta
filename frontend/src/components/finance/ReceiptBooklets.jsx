import { useCallback, useEffect, useState } from 'react'
import {
  Card, Table, Button, Badge, Modal, Form, Alert, Spinner, InputGroup, Pagination,
} from '../ui'
import { useSubmit } from '../../hooks/useSubmit'
import { SkeletonTableRows } from '../Skeleton'
import {
  listReceiptSeries, createReceiptSeries, updateReceiptSeries, deleteReceiptSeries,
} from '../../api/finance'
import { describeError } from '../../utils/apiError'
import ReasonModal from '../ReasonModal'
import ConfigHistory from '../settings/ConfigHistory'

// Finance → Receipt booklets (moved from Inventory in build step 9, per the
// module map: receipt numbers are Finance's). Registering, switching on/off
// and deleting a series are recorded in the change log; deleting asks why.

const SERIES_TYPE_LABEL = { invoice: 'Physical Invoice', official_receipt: 'Official Receipt' }
const SERIES_PER_PAGE = 10

// A number the way it reads on the physical page (prefix + zero-padded digits).
const seriesNumber = (s, n) => `${s.prefix ?? ''}${String(n).padStart(s.pad_length ?? 0, '0')}`

export function ReceiptBooklets({ canManage, canSeeHistory, propertyId }) {
  const [modal, setModal] = useState(false)
  const [deleting, setDeleting] = useState(null) // the series being deleted
  const [history, setHistory] = useState(null) // the series whose history is open
  const [pending, setPending] = useState(null)
  const [err, setErr] = useState(null)

  // Server-paginated and searchable (by prefix).
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
      setErr(describeError(ex, 'Action failed.'))
    } finally {
      setPending(null)
    }
  }

  const totalPages = Math.max(1, Math.ceil(total / SERIES_PER_PAGE))

  return (
    <div>
      {err && <Alert variant="danger">{err}</Alert>}
      <Card>
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
                      {canSeeHistory && (
                        <Button size="sm" variant="outline-secondary" className="mr-1"
                          onClick={() => setHistory(s)}>History</Button>
                      )}
                      <Button size="sm" variant="outline-danger"
                        disabled={pending !== null}
                        onClick={() => setDeleting(s)}>
                        Delete
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
        here. When an invoice is settled (Front Desk or Finance → Invoices), staff mark which document was
        issued and the system stamps the next number from the active series onto the record. A series with
        issued numbers can be deactivated but not deleted.
      </p>

      <ReasonModal show={deleting !== null}
        title="Delete booklet series"
        description={deleting && `${SERIES_TYPE_LABEL[deleting.type] ?? deleting.type} ${seriesNumber(deleting, deleting.start_number)} – `
          + `${seriesNumber(deleting, deleting.end_number)}. Only a series that hasn't issued a number can be deleted; `
          + 'its values stay in the change log.'}
        confirmLabel="Delete"
        onHide={() => setDeleting(null)}
        onConfirm={async (reason) => {
          await deleteReceiptSeries(deleting.id, reason)
          setDeleting(null)
          await load()
        }} />
      {history && (
        <Modal show onHide={() => setHistory(null)} centered>
          <Modal.Header closeButton>
            <Modal.Title>{SERIES_TYPE_LABEL[history.type] ?? history.type} {seriesNumber(history, history.start_number)}</Modal.Title>
          </Modal.Header>
          <Modal.Body className="pt-0">
            <ConfigHistory entityType="receipt_series" entityId={history.id} propertyId={propertyId} />
          </Modal.Body>
          <Modal.Footer>
            <Button variant="secondary" onClick={() => setHistory(null)}>Close</Button>
          </Modal.Footer>
        </Modal>
      )}
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
