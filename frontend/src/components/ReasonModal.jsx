import { useState } from 'react'
import { Modal, Form, Button, Alert, Spinner } from './ui'
import { useSubmit } from '../hooks/useSubmit'

// Shortest reason accepted: enough to say *why*, not just "ok".
const MIN_LENGTH = 5

/**
 * Asks why before an elevated action (docs/PERMISSIONS.md "Elevated"): the
 * reason is stored with the action's event and can't be changed afterwards.
 * First used for cancelling a paid, served POS sale; invoice reversals and
 * reservation delete/correct/backdate reuse it.
 *
 * `onConfirm(reason)` performs the action; while it runs the modal shows a
 * spinner, and an error (with its reference) stays in the modal.
 *
 * `children` are extra fields shown above the reason (e.g. a refund's amount
 * and method; their state lives in the caller), and `ready` is false while
 * those fields aren't complete, keeping Confirm disabled. `optional` lets the
 * action go ahead without a reason (returning a room to service, G4), still
 * saving one when given.
 */
export default function ReasonModal({
  show, title, description, confirmLabel = 'Confirm', onConfirm, onHide, children, ready = true, optional = false,
}) {
  const [reason, setReason] = useState('')
  const trimmed = reason.trim()
  const { run, busy, err, setErr } = useSubmit(async () => {
    await onConfirm(trimmed)
    setReason('')
  })

  const close = () => {
    if (busy) return
    setReason('')
    setErr(null)
    onHide()
  }

  return (
    <Modal show={show} onHide={close}>
      <Form onSubmit={run}>
        <Modal.Header closeButton>
          <Modal.Title>{title}</Modal.Title>
        </Modal.Header>
        <Modal.Body>
          {description && <p className="mb-3 text-sm text-muted">{description}</p>}
          {err && <Alert variant="danger">{err}</Alert>}
          {children}
          <Form.Group>
            <Form.Label>{optional ? 'Reason (optional)' : 'Reason'}</Form.Label>
            <Form.Control
              as="textarea"
              rows={3}
              autoFocus
              maxLength={500}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="e.g. Charged to the wrong table"
            />
            <Form.Text>Saved with this action and can’t be edited later.</Form.Text>
          </Form.Group>
        </Modal.Body>
        <Modal.Footer>
          <Button variant="secondary" onClick={close} disabled={busy}>Back</Button>
          <Button type="submit" variant="danger" disabled={busy || !ready || (!optional && trimmed.length < MIN_LENGTH)}>
            {busy ? <Spinner size="sm" /> : confirmLabel}
          </Button>
        </Modal.Footer>
      </Form>
    </Modal>
  )
}
