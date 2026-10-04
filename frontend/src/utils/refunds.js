// Refunds (build step 7c): money returned to a guest is cash out on the day
// it happens, recorded with how it went back. The methods match the POS
// payment methods (backend InvoicesTable::REFUND_METHODS).
export const REFUND_METHODS = [
  { value: 'cash', label: 'Cash' },
  { value: 'gcash', label: 'GCash' },
  { value: 'maya', label: 'Maya' },
  { value: 'gotyme', label: 'GoTyme' },
]

const LABELS = Object.fromEntries(REFUND_METHODS.map((m) => [m.value, m.label]))

// Refunds from before step 7c never recorded a method: say so, don't guess.
export const refundMethodLabel = (method) => (method ? LABELS[method] ?? method : 'Not recorded')

// One key per refund dialog, sent with the request: a repeat of the same
// refund (double click, retry) is refused by the server instead of recorded twice.
export function newRefundKey() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID()
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`
}
