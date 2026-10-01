import { useCallback, useEffect, useRef, useState } from 'react'
import { Alert, Button, Spinner } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { ownerDashboard, operationsDashboard } from '../api/reports'
import { SkeletonCards, SkeletonTable } from '../components/Skeleton'
import { StatTiles } from '../components/StatCard'
import {
  SectionTitle, TopRow, AttentionPanel, GuestMonitor, PosOverview, InventoryMonitor, StaffMonitor,
} from '../components/Operations'

// The Dashboard is operational monitoring: today at the property. Money
// beyond today's one collected figure — collections, what's still owed,
// invoices, revenue by period and season — is the Revenue page's
// (src/pages/Revenue.jsx).

function Loading() {
  return (
    <>
      <SkeletonCards count={4} />
      <SkeletonTable rows={4} />
    </>
  )
}

// Surface the real failure instead of a blanket message: prefer the API's JSON
// error, fall back to the HTTP status, then to a network-level hint.
function dashboardError(err) {
  const res = err?.response
  if (res) return res.data?.message || `Request failed (${res.status}).`
  return 'Could not reach the server. Please try again.'
}

// Platform owner: subscription revenue + active users.
function OwnerDashboard({ user }) {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    ownerDashboard().then(setData).catch((err) => setError(dashboardError(err)))
  }, [])

  if (error) return <Alert variant="danger">{error}</Alert>
  if (!data) return <Loading />

  return (
    <div>
      <h1 className="sv-serif mb-1 text-[2rem] font-bold">Dashboard</h1>
      <p className="mb-6 text-muted">
        Welcome back, {user?.name}. Platform revenue and active subscribers.
      </p>

      <SectionTitle>Subscription revenue</SectionTitle>
      <StatTiles
        money
        tiles={[
          { label: 'This week', value: data.revenue.week },
          { label: 'This month', value: data.revenue.month },
          { label: 'Year to date', value: data.revenue.ytd },
        ]}
      />

      <SectionTitle>Active users</SectionTitle>
      <StatTiles
        tiles={[
          { label: 'Hotels & Resorts', value: data.counts.hotels },
          { label: 'Active subscriptions', value: data.counts.active_subscriptions },
          { label: 'Registered admins', value: data.counts.admins },
        ]}
      />
    </div>
  )
}

// How often the operations figures refresh on their own; Refresh does it now.
const REFRESH_MS = 5 * 60 * 1000

// Hotel/resort staff (admin + receptionist): today at the property —
// rooms, arrivals/departures, revenue today, what needs attention — then the
// module summaries. A receptionist gets the same page minus Staff.
function OperationsDashboard({ user, isAdmin }) {
  const [state, setState] = useState({ data: null, error: null, at: null })
  const [loadingNow, setLoadingNow] = useState(false)
  const [guestTab, setGuestTab] = useState('arrivals')
  const guestsRef = useRef(null)
  const alive = useRef(true)

  const load = useCallback(() => {
    setLoadingNow(true)
    return operationsDashboard()
      .then((data) => { if (alive.current) setState({ data, error: null, at: new Date() }) })
      .catch((err) => { if (alive.current) setState((s) => ({ ...s, error: dashboardError(err) })) })
      .finally(() => { if (alive.current) setLoadingNow(false) })
  }, [])

  useEffect(() => {
    alive.current = true
    // Deferred a tick so the first fetch isn't a synchronous setState in
    // the effect body.
    const first = setTimeout(load, 0)
    const timer = setInterval(load, REFRESH_MS)
    return () => { alive.current = false; clearTimeout(first); clearInterval(timer) }
  }, [load])

  // A KPI figure or attention line jumps to the guest list behind it.
  const pickGuests = (key) => {
    setGuestTab(key)
    guestsRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  const { data, error, at } = state
  const today = new Date().toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="sv-serif mb-1 text-[2rem] font-bold">Dashboard</h1>
          <p className="mb-0 text-muted">Welcome back, {user?.name}. Here’s your property today — {today}.</p>
        </div>
        <div className="flex items-center gap-2 text-xs text-muted">
          {at && <span>Updated {at.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}</span>}
          <Button size="sm" variant="outline-secondary" onClick={load} disabled={loadingNow}>
            {loadingNow ? <Spinner size="sm" /> : 'Refresh'}
          </Button>
        </div>
      </div>

      {error && !data && <Alert variant="danger">{error}</Alert>}
      {!error && !data && <Loading />}

      {data && (
        <>
          {error && (
            <Alert variant="warning">Couldn’t refresh — showing figures from {at?.toLocaleTimeString()}. {error}</Alert>
          )}

          {/* Rooms overview beside Today + POS sales. */}
          <TopRow data={data} onPickGuests={pickGuests} />

          {/* Guests beside "Needs attention", stretched to one height so the
              shorter card doesn't leave a hole. On a phone the alerts come
              first: they're the action list. */}
          <div className="mb-8 grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div className="lg:col-start-3 lg:row-start-1">
              <AttentionPanel attention={data.attention} onPickGuests={pickGuests} className="h-full" />
            </div>
            <section ref={guestsRef} className="scroll-mt-4 lg:col-span-2 lg:col-start-1 lg:row-start-1">
              <GuestMonitor guests={data.guests} tab={guestTab} onTab={setGuestTab} className="h-full" />
            </section>
          </div>

          {/* POS, Inventory and (admin) Staff side by side. Each section is
              title + card, the card filling the row — so all three end level.
              The Staff card never sizes the row: on desktop it's taken out of
              flow (absolute) and fills what POS/Inventory set, elsewhere it
              gets a fixed height; either way its feed scrolls inside. */}
          <div className={`mb-8 grid grid-cols-1 gap-6 ${isAdmin && data.staff ? 'lg:grid-cols-3' : 'lg:grid-cols-2'}`}>
            <section className="grid grid-rows-[auto_1fr]">
              <SectionTitle aside="Food & Orders">POS overview</SectionTitle>
              <PosOverview pos={data.pos} />
            </section>
            <section className="grid grid-rows-[auto_1fr]">
              <SectionTitle>Inventory</SectionTitle>
              <InventoryMonitor inventory={data.inventory} />
            </section>
            {isAdmin && data.staff && (
              <section className="grid grid-rows-[auto_1fr]">
                <SectionTitle>Staff</SectionTitle>
                <div className="relative h-[28rem] lg:h-auto lg:min-h-[20rem]">
                  <StaffMonitor staff={data.staff} activity={data.activity ?? []}
                    className="h-full lg:absolute lg:inset-0" />
                </div>
              </section>
            )}
          </div>
        </>
      )}
    </div>
  )
}

export default function Dashboard() {
  const { user, role } = useAuth()
  if (role === 'owner') return <OwnerDashboard user={user} />
  return <OperationsDashboard user={user} isAdmin={role === 'admin'} />
}
