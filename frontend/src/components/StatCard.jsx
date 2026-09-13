import { Card } from './ui'

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

export default StatCard
