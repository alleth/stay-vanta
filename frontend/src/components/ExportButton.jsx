import { useState } from 'react'
import { Button, Modal, Form, Alert, Spinner } from './ui'
import { useSubmit } from '../hooks/useSubmit'
import { downloadExport } from '../api/exports'

// Hotel days, as the screens show them (never toISOString: that's UTC).
const ymd = (d) => d.toLocaleDateString('en-CA')
const firstOfMonth = () => { const d = new Date(); return ymd(new Date(d.getFullYear(), d.getMonth(), 1)) }

/**
 * Export one list as a CSV file (step 10c, Managers only; the caller checks
 * the permission). Asks for a date range of at most 12 months; the server
 * records every download (who, which list, which dates, how many rows), and
 * it shows in Operations → Activity.
 *
 * `what` names the list in the dialog ("reservations"), `dateLabel` what the
 * range filters on ("check-in date"), `path` the API path, `dataset` the
 * file name's first word.
 */
export default function ExportButton({ what, dateLabel, path, dataset, size = 'sm', className = '' }) {
  const [show, setShow] = useState(false)
  const [from, setFrom] = useState(firstOfMonth)
  const [to, setTo] = useState(() => ymd(new Date()))
  const [saved, setSaved] = useState(null)
  const { run, busy, err, setErr } = useSubmit(async () => {
    setSaved(await downloadExport(path, dataset, from, to))
  })

  const close = () => {
    if (busy) return
    setShow(false)
    setSaved(null)
    setErr(null)
  }

  return (
    <>
      <Button size={size} variant="outline-secondary" className={className} onClick={() => setShow(true)}>
        Export {what}
      </Button>
      <Modal show={show} onHide={close} centered>
        <Form onSubmit={run}>
          <Modal.Header closeButton><Modal.Title>Export {what}</Modal.Title></Modal.Header>
          <Modal.Body>
            <p className="mb-3 text-sm text-muted">
              A CSV file of the {what} whose {dateLabel} falls in the range (at most 12 months). Your download is
              recorded and shows in Operations → Activity.
            </p>
            {err && <Alert variant="danger">{err}</Alert>}
            {saved && <Alert variant="success">Saved as {saved}.</Alert>}
            <div className="grid grid-cols-2 gap-3">
              <Form.Group>
                <Form.Label>From</Form.Label>
                <Form.Control type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} required />
              </Form.Group>
              <Form.Group>
                <Form.Label>To</Form.Label>
                <Form.Control type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} required />
              </Form.Group>
            </div>
          </Modal.Body>
          <Modal.Footer>
            <Button variant="secondary" onClick={close} disabled={busy}>Close</Button>
            <Button type="submit" disabled={busy || !from || !to}>
              {busy ? <Spinner size="sm" /> : 'Download CSV'}
            </Button>
          </Modal.Footer>
        </Form>
      </Modal>
    </>
  )
}
