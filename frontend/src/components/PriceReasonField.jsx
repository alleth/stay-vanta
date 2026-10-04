import { Form } from './ui'

// Shortest reason accepted, as in ReasonModal.
const MIN_REASON = 5

/**
 * The reason a price change needs (build step 9, C2), asked inside the form
 * as soon as a price field differs from what's saved. The reason is stored
 * with the change and can't be edited afterwards. `note` says who the new
 * price applies to (e.g. bookings already made keep theirs).
 */
export default function PriceReasonField({ show, value, onChange, note }) {
  if (!show) return null
  return (
    <Form.Group className="mt-4 rounded-lg border border-accent/40 bg-accent-soft p-3">
      <Form.Label>Reason for the price change</Form.Label>
      <Form.Control as="textarea" rows={2} maxLength={500} value={value}
        onChange={(e) => onChange(e.target.value)} placeholder="e.g. Peak season from December" required
        minLength={MIN_REASON} />
      <Form.Text>
        {note ? `${note} ` : ''}Saved with the change and can’t be edited later.
      </Form.Text>
    </Form.Group>
  )
}
