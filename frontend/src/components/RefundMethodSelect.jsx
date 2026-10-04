import { Form } from './ui'
import { REFUND_METHODS } from '../utils/refunds'

// How the money went back to the guest: required on every refund (build
// step 7c), so the drawer can be reconciled later.
export default function RefundMethodSelect({ id, value, onChange, label = 'Returned by' }) {
  return (
    <Form.Group className="mb-3">
      <Form.Label>{label}</Form.Label>
      <Form.Select id={id} value={value} onChange={(e) => onChange(e.target.value)}>
        <option value="">Choose a method…</option>
        {REFUND_METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
      </Form.Select>
    </Form.Group>
  )
}
