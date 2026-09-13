import { Link } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { NAV } from '../nav'

// The post-login landing screen: a role-scoped grid of module tiles instead
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

export default function Hub() {
  const { user, role } = useAuth()
  const items = NAV.filter((n) => n.roles.includes(role))

  return (
    <div>
      <h1 className="sv-serif mb-1 text-[2rem] font-bold">Welcome back, {user?.name}.</h1>
      <p className="mb-8 text-muted">What would you like to check on today?</p>
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        {items.map((n) => <Tile key={n.to} {...n} />)}
      </div>
    </div>
  )
}
