import { useAuth } from '../context/AuthContext'

const day = (ymd) => (ymd
  ? new Date(`${ymd}T00:00:00`).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' })
  : '')

const RENEW = ' Contact the platform to renew it.'

/**
 * The property's subscription stage (step 10b, A7), for everyone working
 * there, on every page under the header. Once it lapses: 7 days of grace,
 * then 30 days read-only (viewing, check-out, settling invoices, refunds and
 * account security still work), then suspension. The text follows the dates;
 * when the platform's rollout phase (B5) enforces less, a note says so.
 */
export default function SubscriptionBanner() {
  const { user } = useAuth()
  const s = user?.subscription
  if (!s || s.stage === 'active') return null

  const ended = `This property's subscription ended (unpaid since ${day(s.lapsed_on)}).`
  let text
  if (s.stage === 'suspended') {
    text = `${ended} Suspended since ${day(s.suspended_from)}.${RENEW}`
  } else if (s.stage === 'read_only') {
    text = `${ended} Read-only since ${day(s.read_only_from)}: viewing, check-out, settling invoices, refunds`
      + ' and account security still work; booking, selling, stock and settings don’t.'
      + ` Suspended from ${day(s.suspended_from)}.${RENEW}`
  } else {
    text = `${ended} Read-only from ${day(s.read_only_from)}, suspended from ${day(s.suspended_from)}.${RENEW}`
  }

  let note = null
  if (s.enforced !== s.stage) {
    note = s.enforced === 'read_only'
      ? 'Suspension isn’t switched on yet: the property stays read-only.'
      : 'Not enforced yet: nothing is blocked today.'
  }

  const severe = s.enforced === 'read_only' || s.enforced === 'suspended'
  const tone = severe
    ? 'border-red-300 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-200'
    : 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200'

  return (
    <div className={`border-b px-4 py-2 text-sm lg:px-8 ${tone}`} role="status">
      <strong>Subscription</strong> · {text}
      {note && <span className="ml-1 opacity-80">({note})</span>}
    </div>
  )
}
