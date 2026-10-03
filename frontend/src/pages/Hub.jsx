import { Link } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { GROUPS, NAV, canOpen } from '../nav'

// The post-login landing screen: the modules this person may open (scope + permission, nav.js), grouped for
// property users (Overview, Guest services, Property, Team), instead
// of a persistent tab bar (see Layout.jsx — the header keeps only a Home
// link back here, so every module-to-module switch returns through this
// screen by design).
function Tile({ to, label, blurb, Icon }) {
  return (
    <Link
      to={to}
      className="group flex flex-col items-center gap-3 rounded-2xl border border-line bg-surface p-6 text-center no-underline shadow-sm transition-all hover:-translate-y-0.5 hover:border-accent/40 hover:shadow-md sm:p-8"
    >
      <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-accent-soft text-accent transition-colors group-hover:bg-accent group-hover:text-on-ink">
        <Icon className="h-7 w-7" />
      </span>
      <div>
        <div className="sv-serif text-base font-bold text-body">{label}</div>
        <div className="mt-1 text-xs text-muted">{blurb}</div>
      </div>
    </Link>
  )
}

const TILE_GRID = 'grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4'

export default function Hub() {
  const auth = useAuth()
  const { user } = auth
  const items = NAV.filter((n) => canOpen(n, auth))
  // Property users see labeled groups; the Platform Owner's tiles have no
  // group and render as one plain grid. Empty groups are skipped.
  const grouped = GROUPS
    .map((g) => ({ ...g, items: items.filter((n) => n.group === g.key) }))
    .filter((g) => g.items.length > 0)
  const ungrouped = items.filter((n) => !n.group)

  return (
    <div>
      <h1 className="sv-serif mb-1 text-[2rem] font-bold">Welcome back, {user?.name}.</h1>
      <p className="mb-8 text-muted">What would you like to check on today?</p>
      {ungrouped.length > 0 && (
        <div className={`mb-8 ${TILE_GRID}`}>
          {ungrouped.map((n) => <Tile key={n.to} {...n} />)}
        </div>
      )}
      <div className="space-y-8">
        {grouped.map((g) => (
          <section key={g.key} aria-labelledby={`hub-${g.key}`}>
            <h2 id={`hub-${g.key}`}
              className="mb-3 text-xs font-semibold uppercase tracking-[0.04em] text-muted">
              {g.label}
            </h2>
            <div className={TILE_GRID}>
              {g.items.map((n) => <Tile key={n.to} {...n} />)}
            </div>
          </section>
        ))}
      </div>
    </div>
  )
}
