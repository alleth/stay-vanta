import { Card } from './ui'
import { formatMoney } from '../utils/format'

// The app's one stat/summary tile — the compact card that sits in a row above
// a module's table (Front Desk, Guests) or under a Dashboard section heading.
// Front Desk and Guests each used to carry their own near-identical copy plus
// its own colour map; keeping a single component is what stops the three
// screens' cards from drifting apart again.
//
// `variant` tints the number only (the label stays muted); omit it for the
// plain body colour. `size="sm"` is for values that run long — a formatted
// peso figure — which would otherwise overflow a narrow card.
const VALUE_COLOR = {
  success: 'text-emerald-600 dark:text-emerald-400',
  danger: 'text-red-600 dark:text-red-400',
  warning: 'text-amber-600 dark:text-amber-400',
  info: 'text-sky-600 dark:text-sky-400',
  primary: 'text-ink',
  secondary: 'text-muted',
  dark: 'text-body',
}

export function StatCard({ label, value, variant, size }) {
  return (
    <Card className="h-full">
      <Card.Body className="p-4">
        <div className="text-xs font-medium uppercase tracking-[0.04em] text-muted">{label}</div>
        <div
          className={`sv-serif tabular-nums mt-1 font-bold ${size === 'sm' ? 'text-xl' : 'text-2xl'} ${VALUE_COLOR[variant] ?? ''}`}
        >
          {value}
        </div>
      </Card.Body>
    </Card>
  )
}

// A row of StatCards (Dashboard's owner figures, the Revenue page). Counts
// are short enough to sit two-up even on a phone; a peso figure (up to
// "₱1,234,567.00") needs the full width there, so `money` tiles only split
// into columns from `sm` up, and are formatted here.
export function StatTiles({ tiles, money = false, className = 'mb-8' }) {
  const cols = money ? 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-4' : 'grid-cols-2 lg:grid-cols-4'
  return (
    <div className={`grid gap-3 ${cols} ${className}`}>
      {tiles.map((t) => (
        <StatCard
          key={t.label}
          label={t.label}
          value={money ? formatMoney(t.value) : t.value}
          variant={t.variant}
          size={money ? 'sm' : undefined}
        />
      ))}
    </div>
  )
}

// A group of related figures in one card — for when a row of single-number
// tiles gets crowded and the numbers answer the same question (Front Desk's
// "Rooms", "Today", "To collect"). Same label and number treatment as
// StatCard, so the two read as one system. `highlight` rings the card in the
// accent — reserve it for a group that needs someone to act. (A ring, not a
// border colour: Card always sets border-line, and concatenated classes can't
// reliably override it.)
export function SummaryGroup({ label, highlight = false, className = '', children }) {
  return (
    <Card className={`h-full ${highlight ? 'ring-1 ring-accent' : ''} ${className}`}>
      <Card.Body className="p-4">
        <div className="mb-2 text-xs font-medium uppercase tracking-[0.04em] text-muted">{label}</div>
        {children}
      </Card.Body>
    </Card>
  )
}

// One figure inside a SummaryGroup: number + what it counts. With `onClick`
// the whole row is a button that takes you to the list behind the number.
export function SummaryRow({ value, label, variant, onClick, title }) {
  const inner = (
    <>
      <span className={`sv-serif w-10 shrink-0 text-xl font-bold tabular-nums ${VALUE_COLOR[variant] ?? ''}`}>
        {value}
      </span>
      <span className="min-w-0 flex-1 text-sm text-muted">{label}</span>
      {onClick && <span aria-hidden="true" className="text-muted">›</span>}
    </>
  )
  if (!onClick) return <div className="flex items-baseline gap-2 py-0.5">{inner}</div>
  return (
    <button type="button" title={title} onClick={onClick}
      className="-mx-2 flex w-[calc(100%+1rem)] items-baseline gap-2 rounded-md px-2 py-0.5 text-left transition-colors hover:bg-subtle">
      {inner}
    </button>
  )
}

export default StatCard
