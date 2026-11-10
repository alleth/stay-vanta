import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Alert, Button, Spinner } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { P } from '../auth/permissions'
import { canOpen, navItem } from '../nav'
import { operationsToday } from '../api/operations'
import { SkeletonCards, SkeletonTable } from '../components/Skeleton'
import {
  SectionTitle, TopRow, AttentionPanel, GuestMonitor, PosOverview, InventoryMonitor, StaffMonitor,
} from '../components/operations/Operations'
import { describeError } from '../utils/apiError'

// Operations: what's happening at the property right now (display only; it
// owns no process). Money beyond "Collected today" — collections, what's
// owed, invoices, analytics — is Finance (pages/Finance.jsx).

function Loading() {
  return (
    <>
      <SkeletonCards count={4} />
      <SkeletonTable rows={4} />
    </>
  )
}

// How often the operations figures refresh on their own; Refresh does it now.
const REFRESH_MS = 5 * 60 * 1000

// Manager + Front Desk Staff: today at the property —
// rooms, arrivals/departures, collected today, what needs attention — then the
// module summaries. Front Desk Staff get the same page minus Staff.
export default function Operations() {
  const { user, can, scope } = useAuth()
  const navigate = useNavigate()
  const seesStaff = can(P.OPERATIONS_STAFF_VIEW)
  const [state, setState] = useState({ data: null, error: null, at: null })
  const [loadingNow, setLoadingNow] = useState(false)
  const [guestTab, setGuestTab] = useState('arrivals')
  const [roomFilter, setRoomFilter] = useState(null) // null | status key | 'now' | 'today'
  const guestsRef = useRef(null)
  const roomsRef = useRef(null)
  const inventoryRef = useRef(null)
  const alive = useRef(true)

  const load = useCallback(() => {
    setLoadingNow(true)
    return operationsToday()
      .then((data) => { if (alive.current) setState({ data, error: null, at: new Date() }) })
      .catch((err) => { if (alive.current) setState((s) => ({ ...s, error: describeError(err) })) })
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

  // A jump lands keyboard and screen reader users on the section too.
  const jumpTo = (ref) => {
    ref.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    ref.current?.focus({ preventScroll: true })
  }

  // A KPI figure or attention line jumps to the list behind it.
  const pickGuests = (key) => {
    setGuestTab(key)
    jumpTo(guestsRef)
  }
  const pickRooms = (key) => {
    setRoomFilter(key)
    jumpTo(roomsRef)
  }

  const { data, error, at } = state
  const today = new Date().toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="sv-serif mb-1 text-[2rem] font-bold">Operations</h1>
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
            <Alert variant="warning">Couldn’t refresh — showing figures from {at?.toLocaleTimeString()}. {error.message}</Alert>
          )}

          {/* The action list comes first on every screen size. */}
          <AttentionPanel attention={data.attention} onPickGuests={pickGuests} onPickRooms={pickRooms}
            onPickInventory={() => jumpTo(inventoryRef)}
            onOpenPos={canOpen(navItem('/pos'), { scope, can }) ? () => navigate('/pos') : null} />

          {/* Rooms overview beside Today + POS sales. */}
          <TopRow data={data} onPickGuests={pickGuests} roomFilter={roomFilter} onRoomFilter={setRoomFilter}
            roomsRef={roomsRef} />

          <section ref={guestsRef} tabIndex={-1} className="mb-8 scroll-mt-4 outline-none">
            <GuestMonitor guests={data.guests} tab={guestTab} onTab={setGuestTab} />
          </section>

          {/* POS, Inventory and (admin) Staff side by side. Each section is
              title + card, the card filling the row — so all three end level.
              The Staff card never sizes the row: on desktop it's taken out of
              flow (absolute) and fills what POS/Inventory set, elsewhere it
              gets a fixed height; either way its feed scrolls inside. */}
          <div className={`mb-8 grid grid-cols-1 gap-6 ${seesStaff && data.staff ? 'lg:grid-cols-3' : 'lg:grid-cols-2'}`}>
            <section className="grid grid-rows-[auto_1fr]">
              <SectionTitle>POS overview</SectionTitle>
              <PosOverview pos={data.pos} />
            </section>
            <section ref={inventoryRef} tabIndex={-1} className="grid scroll-mt-4 grid-rows-[auto_1fr] outline-none">
              <SectionTitle>Inventory</SectionTitle>
              <InventoryMonitor inventory={data.inventory} />
            </section>
            {seesStaff && data.staff && (
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
