import { useCallback, useEffect, useState } from 'react'
import { Card, Table, Badge, Button, Alert, Modal } from './ui'
import { SkeletonTable, SkeletonTableRows } from './Skeleton'
import { supportSessions, supportSession } from '../api/staff'
import { describeError } from '../utils/apiError'

const STATE = {
  open: { label: 'Open', bg: 'warning' },
  ended: { label: 'Ended', bg: 'secondary' },
  expired: { label: 'Expired', bg: 'secondary' },
}

const when = (at) => (at ? new Date(at).toLocaleString() : '—')

/**
 * Staff → Security (step 10b, Manager): every support session the Platform
 * Owner opened at this property, why, when, how it ended, and each request
 * made during it (A6: read-only, fully audited).
 */
export default function SupportSessions() {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState(null)

  const load = useCallback(async () => {
    setError(null)
    try {
      setData(await supportSessions(page))
    } catch (ex) {
      setError(describeError(ex, 'Could not load support access.'))
    }
  }, [page])

  useEffect(() => {
    // load() only sets state after awaiting the network.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load()
  }, [load])

  if (error) return <Alert variant="danger">{error}</Alert>
  if (!data) return <SkeletonTable rows={3} />

  return (
    <>
      <p className="mb-3 text-sm text-muted">
        The Platform Owner can look at this property&apos;s data only through a support session: read-only,
        for at most 60 minutes, with a reason. Each one is listed here with every page they opened.
      </p>
      <Card>
        <Table hover>
          <thead>
            <tr><th>Started</th><th>By</th><th>Reason</th><th>Status</th><th className="text-right">Pages</th><th /></tr>
          </thead>
          <tbody>
            {data.sessions.length === 0 && (
              <tr><td colSpan={6} className="py-6 text-center text-muted">No support access so far.</td></tr>
            )}
            {data.sessions.map((s) => (
              <tr key={s.id}>
                <td className="whitespace-nowrap">{when(s.started_at)}</td>
                <td>{s.by ?? '—'}</td>
                <td>{s.reason}</td>
                <td>
                  <Badge bg={STATE[s.state]?.bg ?? 'secondary'}>{STATE[s.state]?.label ?? s.state}</Badge>
                  {s.state === 'ended' && s.ended_by && <div className="text-xs text-muted">by {s.ended_by}</div>}
                </td>
                <td className="text-right tabular-nums">{s.requests}</td>
                <td className="text-right">
                  <Button size="sm" variant="outline-secondary" onClick={() => setOpen(s)}>Details</Button>
                </td>
              </tr>
            ))}
          </tbody>
        </Table>
      </Card>
      {(page > 1 || data.has_more) && (
        <div className="mt-3 flex justify-end gap-2">
          <Button size="sm" variant="outline-secondary" disabled={page === 1} onClick={() => setPage((p) => p - 1)}>Newer</Button>
          <Button size="sm" variant="outline-secondary" disabled={!data.has_more} onClick={() => setPage((p) => p + 1)}>Older</Button>
        </div>
      )}
      {open && <SessionRequests session={open} onHide={() => setOpen(null)} />}
    </>
  )
}

function SessionRequests({ session, onHide }) {
  const [requests, setRequests] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    supportSession(session.id)
      .then((d) => setRequests(d.requests))
      .catch((ex) => setError(describeError(ex, 'Could not load this session.')))
  }, [session.id])

  return (
    <Modal show onHide={onHide} size="lg" centered>
      <Modal.Header closeButton><Modal.Title>Support access · {when(session.started_at)}</Modal.Title></Modal.Header>
      <Modal.Body className="max-h-[65vh] overflow-y-auto pt-0">
        <p className="mb-3 text-sm text-muted">
          {session.by ?? 'The Platform Owner'} · &ldquo;{session.reason}&rdquo; · until {when(session.ended_at ?? session.expires_at)}
        </p>
        {error && <Alert variant="danger">{error}</Alert>}
        <Table>
          <thead><tr><th>When</th><th>Request</th></tr></thead>
          <tbody>
            {!requests && !error && <SkeletonTableRows rows={3} cols={2} />}
            {requests?.length === 0 && (
              <tr><td colSpan={2} className="py-6 text-center text-muted">Nothing was opened.</td></tr>
            )}
            {requests?.map((r, i) => (
              <tr key={i}>
                <td className="whitespace-nowrap">{when(r.at)}</td>
                <td><code className="text-xs">{r.method} {r.path}</code></td>
              </tr>
            ))}
          </tbody>
        </Table>
      </Modal.Body>
      <Modal.Footer><Button variant="secondary" onClick={onHide}>Close</Button></Modal.Footer>
    </Modal>
  )
}
