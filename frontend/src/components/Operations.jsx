import { useCallback, useEffect, useState } from 'react'
import {
  Alert, Badge, Button, ButtonGroup, Card, ListGroup, Modal, Spinner, Table, Tabs, Tab,
} from './ui'
import { SummaryGroup, SummaryRow } from './StatCard'
import { formatMoney } from '../utils/format'
import { staffActivity } from '../api/reports'
import { SkeletonTable } from './Skeleton'

// The operational half of the Dashboard (admin + receptionist), drawn from one
// GET /reports/operations payload. Every figure is today's, on the hotel's
// clock; trends are the trailing seven days. See backend/API.md.

// Room statuses, in the order the bar and legend read. Solid saturated fills
// read correctly in both themes; the tinted chips carry their dark pairs.
// Colours match Front Desk's Rooms card (occupied red, available green,
// maintenance amber); "reserved" adds sky.
const ROOM_STATUS = [
  { key: 'occupied', label: 'Occupied', dot: 'bg-red-500',
    chip: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' },
  { key: 'reserved', label: 'Reserved', dot: 'bg-sky-500',
    chip: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300' },
  { key: 'available', label: 'Vacant', dot: 'bg-emerald-500',
    chip: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' },
  { key: 'maintenance', label: 'Maintenance', dot: 'bg-amber-500',
    chip: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' },
]

const plural = (n, word, many = `${word}s`) => `${n} ${n === 1 ? word : many}`

// `walk_in` → "Walk-in"; a booking source code → its words.
const sourceLabel = (code) => (code === 'walk_in'
  ? 'Walk-in'
  : (code ?? '').replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()))

const shortDate = (ymd) => (ymd
  ? new Date(`${ymd}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
  : '—')

// A timestamp as the time if it's today, else the short date + time.
function when(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  const time = d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
  return d.toDateString() === new Date().toDateString()
    ? time
    : `${d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}, ${time}`
}

export function SectionTitle({ children, aside }) {
  return (
    <div className="mb-3 flex items-baseline justify-between gap-3">
      <h2 className="text-xs font-semibold uppercase tracking-[0.04em] text-muted">{children}</h2>
      {aside && <div className="text-xs text-muted">{aside}</div>}
    </div>
  )
}

// A quiet line for a list with nothing in it — a card that disappears reads
// as "didn't load", so an empty list says so.
function Empty({ children }) {
  return <p className="px-4 py-6 text-center text-sm text-muted">{children}</p>
}

/* ------------------------------------------------------------ KPI row */

function TodayKpi({ guests, onPick }) {
  const { arrivals, departures } = guests
  const left = (g) => g.total - g.done
  return (
    <SummaryGroup label="Today">
      <SummaryRow value={arrivals.total} label={`check-ins · ${arrivals.done} arrived, ${left(arrivals)} to come`}
        variant="primary" onClick={() => onPick('arrivals')} title="Show today's arrivals" />
      <SummaryRow value={departures.total} label={`check-outs · ${departures.done} done, ${left(departures)} to go`}
        variant="primary" onClick={() => onPick('departures')} title="Show today's departures" />
      <SummaryRow value={guests.in_house.total}
        label={`stays in-house · ${plural(guests.in_house.guests, 'guest')}`}
        variant="secondary" onClick={() => onPick('in_house')} title="Show in-house guests" />
    </SummaryGroup>
  )
}

function PosKpi({ pos }) {
  const { today, yesterday_paid: yesterday } = pos
  const delta = yesterday > 0 ? Math.round(((today.paid - yesterday) / yesterday) * 100) : null
  return (
    <SummaryGroup label="POS sales today">
      <div className="sv-serif text-[1.75rem] font-bold leading-none tabular-nums">{formatMoney(today.paid)}</div>
      <div className="mt-2 flex flex-wrap items-center gap-2 text-sm text-muted">
        <span>{plural(today.orders, 'order')}</span>
        {delta !== null && (
          <Badge bg={delta >= 0 ? 'success' : 'danger'}>
            {delta >= 0 ? '↑' : '↓'} {Math.abs(delta)}% vs yesterday
          </Badge>
        )}
      </div>
      {(today.charged_to_room > 0 || today.unpaid > 0) && (
        <div className="mt-3 space-y-0.5 border-t border-line pt-2 text-xs text-muted">
          {today.charged_to_room > 0 && (
            <div className="flex justify-between gap-2">
              <span>Charged to rooms</span>
              <span className="tabular-nums">{formatMoney(today.charged_to_room)}</span>
            </div>
          )}
          {today.unpaid > 0 && (
            <div className="flex justify-between gap-2">
              <span>Unpaid</span>
              <span className="tabular-nums text-amber-700 dark:text-amber-400">{formatMoney(today.unpaid)}</span>
            </div>
          )}
        </div>
      )}
    </SummaryGroup>
  )
}

// The top of the page: Rooms overview (two thirds) beside Today and POS sales
// stacked — what's visible on login without scrolling. With a dozen-odd rooms
// the two sides come out about the same height.
export function TopRow({ data, onPickGuests }) {
  return (
    <div className="mb-8 grid grid-cols-1 gap-3 lg:grid-cols-3">
      <RoomsOverview rooms={data.rooms} className="lg:col-span-2" />
      <div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-1">
        <TodayKpi guests={data.guests} onPick={onPickGuests} />
        <PosKpi pos={data.pos} />
      </div>
    </div>
  )
}

/* ------------------------------------------------------- Needs attention */

// The computed notification panel: nothing is stored or marked read — each
// line is a live count, shown only while it's non-zero, most urgent first.
const SEVERITY = {
  danger: 'bg-red-500',
  warning: 'bg-amber-500',
  info: 'bg-sky-500',
}

function attentionItems(a) {
  const items = [
    { n: a.late_arrivals, sev: 'danger', text: (n) => `${plural(n, 'late arrival')} not checked in`, guests: 'arrivals' },
    { n: a.overdue_departures, sev: 'danger', text: (n) => `${plural(n, 'overdue check-out')}`, guests: 'departures' },
    { n: a.out_of_stock, sev: 'danger', text: (n) => `${plural(n, 'item')} out of stock` },
    { n: a.arrivals_pending - a.late_arrivals, sev: 'info', text: (n) => `${plural(n, 'arrival')} due today`, guests: 'arrivals' },
    { n: a.departures_pending - a.overdue_departures, sev: 'info', text: (n) => `${plural(n, 'check-out')} due today`, guests: 'departures' },
    { n: a.low_stock, sev: 'warning', text: (n) => `${plural(n, 'item')} at or below reorder level` },
    { n: a.unpaid_reservations, sev: 'warning', text: (n) => `${plural(n, 'reservation')} not yet paid` },
    { n: a.open_invoices.count, sev: 'warning',
      text: (n) => `${plural(n, 'open invoice')} · ${formatMoney(a.open_invoices.total)} to settle` },
    { n: a.open_food_orders, sev: 'info', text: (n) => `${plural(n, 'food order')} still open` },
    { n: a.maintenance_rooms, sev: 'info', text: (n) => `${plural(n, 'room')} under maintenance` },
    { n: a.new_bookings, sev: 'info', text: (n) => `${plural(n, 'new booking')} today` },
  ]
  return items.filter((i) => i.n > 0)
}

export function AttentionPanel({ attention, onPickGuests, className = '' }) {
  const items = attentionItems(attention)
  const urgent = items.some((i) => i.sev === 'danger')
  return (
    <Card className={`${urgent ? 'ring-1 ring-accent' : ''} ${className}`}>
      <Card.Header className="flex items-center justify-between">
        <span>Needs attention</span>
        {items.length > 0 && <Badge bg={urgent ? 'danger' : 'secondary'}>{items.length}</Badge>}
      </Card.Header>
      {items.length === 0 ? (
        <Empty>
          <span className="text-emerald-700 dark:text-emerald-400">✓</span> All clear — nothing waiting on you.
        </Empty>
      ) : (
        <ListGroup>
          {items.map((i) => {
            const body = (
              <>
                <span className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${SEVERITY[i.sev]}`} />
                <span className="min-w-0 flex-1">{i.text(i.n)}</span>
                {i.guests && <span aria-hidden="true" className="text-muted">›</span>}
              </>
            )
            return i.guests ? (
              <button key={i.text(i.n)} type="button" onClick={() => onPickGuests(i.guests)}
                className="flex w-full items-start gap-3 px-4 py-2.5 text-left text-sm transition-colors hover:bg-subtle">
                {body}
              </button>
            ) : (
              <ListGroup.Item key={i.text(i.n)} className="flex items-start gap-3 px-4 py-2.5">{body}</ListGroup.Item>
            )
          })}
        </ListGroup>
      )}
    </Card>
  )
}

/* -------------------------------------------------------- Rooms overview */

// What a room needs today (from the API's `flag`). Urgent ones ring the tile
// in red; the rest only change its caption.
const ROOM_FLAG = {
  overdue_checkout: { label: 'Overdue out', urgent: true },
  late_arrival: { label: 'Late arrival', urgent: true },
  departing: { label: 'Leaving today', urgent: false },
  arriving: { label: 'Arriving today', urgent: false },
}

// All room monitoring in one card: the summary (occupancy, total, the four
// status counts) on top, every room as a colour-coded tile below. The status
// counts double as the grid's legend and filter, so nothing is shown twice;
// "Needs action" narrows the grid to flagged rooms.
function RoomsOverview({ rooms, className = '' }) {
  const [filter, setFilter] = useState(null) // null | status key | 'flagged'
  const status = Object.fromEntries(ROOM_STATUS.map((s) => [s.key, s]))
  const flagged = rooms.map.filter((r) => r.flag)
  const shown = filter === 'flagged'
    ? flagged
    : filter ? rooms.map.filter((r) => r.status === filter) : rooms.map
  const toggle = (key) => setFilter((f) => (f === key ? null : key))
  const pct = (n) => (rooms.total > 0 ? `${(n / rooms.total) * 100}%` : '0%')
  const chip = (active) => `flex items-center gap-2 rounded-lg border px-2.5 py-1.5 text-left transition-colors ${
    active ? 'border-ink bg-subtle' : 'border-line hover:bg-subtle'}`

  return (
    <Card className={`h-full ${className}`}>
      <Card.Body className="border-b border-line">
        <div className="mb-2 flex items-baseline justify-between gap-2">
          <span className="text-xs font-medium uppercase tracking-[0.04em] text-muted">Rooms overview</span>
          {flagged.length > 0 && (
            <button type="button" aria-pressed={filter === 'flagged'} onClick={() => toggle('flagged')}
              className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs transition-colors ${
                filter === 'flagged' ? 'border-ink bg-subtle text-body' : 'border-line text-muted hover:bg-subtle hover:text-body'}`}>
              <span className="h-2 w-2 rounded-full ring-2 ring-red-500" />Needs action ({flagged.length})
            </button>
          )}
        </div>
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
          <div className="sm:w-44 sm:shrink-0">
            <div className="flex items-baseline gap-2">
              <span className="sv-serif text-[1.75rem] font-bold leading-none tabular-nums">
                {Math.round(rooms.occupancy_rate)}%
              </span>
              <span className="text-sm text-muted">occupancy</span>
            </div>
            <div className="mt-2 flex h-2 overflow-hidden rounded-full bg-subtle" role="img"
              aria-label={ROOM_STATUS.map((s) => `${rooms[s.key]} ${s.label.toLowerCase()}`).join(', ')}>
              {ROOM_STATUS.map((s) => <div key={s.key} className={s.dot} style={{ width: pct(rooms[s.key]) }} />)}
            </div>
            <div className="mt-1.5 text-xs text-muted tabular-nums">{plural(rooms.total, 'room')} in total</div>
          </div>
          <div className="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-4">
            {ROOM_STATUS.map((s) => (
              <button key={s.key} type="button" aria-pressed={filter === s.key}
                title={`Show only ${s.label.toLowerCase()} rooms`}
                onClick={() => toggle(s.key)} className={chip(filter === s.key)}>
                <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${s.dot}`} />
                <span className="min-w-0 flex-1 truncate text-xs text-muted">{s.label}</span>
                <span className="sv-serif text-lg font-bold leading-none tabular-nums">{rooms[s.key]}</span>
              </button>
            ))}
          </div>
        </div>
      </Card.Body>
      <Card.Body>
        {rooms.total === 0 ? (
          <p className="mb-0 text-sm text-muted">No rooms set up yet — add them on Front Desk.</p>
        ) : shown.length === 0 ? (
          <p className="mb-0 text-sm text-muted">
            No rooms are {filter === 'flagged' ? 'flagged' : status[filter]?.label.toLowerCase()}.
          </p>
        ) : (
          <div className="grid grid-cols-[repeat(auto-fill,minmax(6.5rem,1fr))] gap-2">
            {shown.map((r) => {
              const s = status[r.status]
              const f = ROOM_FLAG[r.flag]
              return (
                <div key={r.id}
                  title={[`Room ${r.number}`, r.type, s?.label, f?.label].filter(Boolean).join(' · ')}
                  className={`rounded-lg px-2.5 py-2 ${s?.chip ?? 'bg-subtle'} ${
                    f?.urgent ? 'ring-2 ring-red-500 ring-offset-1 ring-offset-surface' : ''}`}>
                  <div className="flex items-center gap-1.5">
                    <span className={`h-2 w-2 shrink-0 rounded-full ${s?.dot ?? 'bg-line'}`} />
                    <span className="truncate text-sm font-bold tabular-nums">{r.number}</span>
                  </div>
                  <div className={`mt-0.5 truncate text-[0.7rem] ${f ? 'font-semibold' : 'opacity-80'}`}>
                    {f ? f.label : s?.label}
                  </div>
                </div>
              )
            })}
          </div>
        )}
      </Card.Body>
    </Card>
  )
}

/* --------------------------------------------------------- Guest monitor */

const GUEST_STATE = {
  due: { bg: 'info', label: 'due today' },
  late: { bg: 'danger', label: 'late' },
  arrived: { bg: 'success', label: 'arrived' },
  overdue: { bg: 'danger', label: 'overdue' },
  departed: { bg: 'secondary', label: 'checked out' },
}

function GuestRows({ list, empty, dateCol }) {
  if (list.rows.length === 0) return <Empty>{empty}</Empty>
  return (
    <>
      <Table hover>
        <thead>
          <tr>
            <th>Room</th>
            <th>Guest</th>
            <th className="text-right">Pax</th>
            <th>Source</th>
            <th>{dateCol === 'in' ? 'Check-in' : 'Check-out'}</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          {list.rows.map((r) => (
            <tr key={`${r.id}-${r.state}`}>
              <td className="font-semibold tabular-nums">{r.room ?? '—'}</td>
              <td>{r.guest ?? <span className="text-muted">No guest on file</span>}</td>
              <td className="text-right tabular-nums">{r.guests}</td>
              <td className="text-muted">{sourceLabel(r.source)}</td>
              <td className="tabular-nums">{shortDate(dateCol === 'in' ? r.check_in : r.check_out)}</td>
              <td>
                {GUEST_STATE[r.state]
                  ? <Badge bg={GUEST_STATE[r.state].bg}>{GUEST_STATE[r.state].label}</Badge>
                  : <span className="text-xs text-muted">until {shortDate(r.check_out)}</span>}
              </td>
            </tr>
          ))}
        </tbody>
      </Table>
      {list.total > list.rows.length && (
        <p className="border-t border-line px-4 py-2 text-xs text-muted">
          Showing {list.rows.length} of {list.total} — the full list is on Front Desk.
        </p>
      )}
    </>
  )
}

export function GuestMonitor({ guests, tab, onTab, className = '' }) {
  const title = (label, list) => `${label} (${list.total})`
  return (
    <Card className={className}>
      <Tabs activeKey={tab} onSelect={onTab} className="px-2 pt-1">
        <Tab eventKey="arrivals" title={title('Arrivals', guests.arrivals)}>
          <GuestRows list={guests.arrivals} dateCol="in" empty="No arrivals due today." />
        </Tab>
        <Tab eventKey="departures" title={title('Departures', guests.departures)}>
          <GuestRows list={guests.departures} dateCol="out" empty="No check-outs due today." />
        </Tab>
        <Tab eventKey="in_house" title={title('In-house', guests.in_house)}>
          <GuestRows list={guests.in_house} dateCol="in" empty="No guests are checked in." />
        </Tab>
      </Tabs>
    </Card>
  )
}

/* ------------------------------------------------------------------- POS */

// A seven-day sparkline: the shape of the week, not a chart to read values
// off — the exact figures are in its title and the KPI card. Drawn in the
// accent via currentColor, so it themes itself.
function Sparkline({ trend }) {
  const W = 160
  const H = 40
  const max = Math.max(...trend.map((d) => d.total), 0)
  const x = (i) => (trend.length > 1 ? (i / (trend.length - 1)) * W : W / 2)
  const y = (v) => (max > 0 ? H - 2 - (v / max) * (H - 6) : H - 2)
  const points = trend.map((d, i) => `${x(i).toFixed(1)},${y(d.total).toFixed(1)}`).join(' ')
  const last = trend[trend.length - 1]
  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="h-10 w-40 text-accent" role="img"
      aria-label={`Paid sales, last ${trend.length} days: ${trend.map((d) => formatMoney(d.total)).join(', ')}`}>
      <polygon points={`0,${H} ${points} ${W},${H}`} fill="currentColor" opacity="0.15" />
      <polyline points={points} fill="none" stroke="currentColor" strokeWidth="2"
        strokeLinejoin="round" strokeLinecap="round" />
      {last && <circle cx={x(trend.length - 1)} cy={y(last.total)} r="3" fill="currentColor" />}
    </svg>
  )
}

const ORDER_PAY = {
  paid: { bg: 'success', label: 'paid' },
  charge_to_room: { bg: 'info', label: 'to room' },
  unpaid: { bg: 'warning', label: 'unpaid' },
}

export function PosOverview({ pos }) {
  const weekTotal = pos.trend.reduce((s, d) => s + d.total, 0)
  const topQty = Math.max(...pos.top_sellers.map((s) => s.quantity), 1)
  return (
    <Card className="h-full">
      <Card.Body className="flex flex-wrap items-end justify-between gap-3 border-b border-line">
        <div>
          <div className="text-xs font-medium uppercase tracking-[0.04em] text-muted">Paid sales · 7 days</div>
          <div className="sv-serif mt-1 text-xl font-bold tabular-nums">{formatMoney(weekTotal)}</div>
        </div>
        <Sparkline trend={pos.trend} />
      </Card.Body>
      <Card.Body className="border-b border-line">
        <div className="mb-2 text-xs font-medium uppercase tracking-[0.04em] text-muted">Top sellers · 7 days</div>
        {pos.top_sellers.length === 0 ? (
          <p className="text-sm text-muted">No menu items sold this week.</p>
        ) : (
          <ol className="space-y-2">
            {pos.top_sellers.map((s) => (
              <li key={s.name} className="text-sm">
                <div className="flex items-baseline justify-between gap-2">
                  <span className="min-w-0 truncate">{s.name}</span>
                  <span className="shrink-0 tabular-nums text-muted">{s.quantity} sold</span>
                </div>
                <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-subtle">
                  <div className="h-full rounded-full bg-accent" style={{ width: `${(s.quantity / topQty) * 100}%` }} />
                </div>
              </li>
            ))}
          </ol>
        )}
      </Card.Body>
      <div className="px-4 pt-3 text-xs font-medium uppercase tracking-[0.04em] text-muted">Recent orders</div>
      {pos.recent.length === 0 ? (
        <Empty>No orders yet.</Empty>
      ) : (
        <ListGroup>
          {pos.recent.map((o) => (
            <ListGroup.Item key={o.id} className="flex items-center gap-3 px-4 py-2">
              <span className="w-14 shrink-0 font-semibold tabular-nums">#{o.id}</span>
              <span className="min-w-0 flex-1 truncate text-muted">
                {o.room ? `Room ${o.room}` : o.guest ?? 'Walk-up'} · {when(o.created)}
              </span>
              {o.status === 'cancelled'
                ? <Badge bg="secondary">cancelled</Badge>
                : ORDER_PAY[o.payment_status] && (
                  <Badge bg={ORDER_PAY[o.payment_status].bg}>{ORDER_PAY[o.payment_status].label}</Badge>
                )}
              <span className="w-24 shrink-0 text-right tabular-nums">{formatMoney(o.total)}</span>
            </ListGroup.Item>
          ))}
        </ListGroup>
      )}
    </Card>
  )
}

/* ------------------------------------------------------------- Inventory */

const qty = (n) => Number(n).toLocaleString(undefined, { maximumFractionDigits: 2 })

function StockList({ title, rows, total, tone, emptyText }) {
  return (
    <div>
      <div className="mb-2 flex items-baseline justify-between">
        <span className="text-xs font-medium uppercase tracking-[0.04em] text-muted">{title}</span>
        <span className={`text-sm font-semibold tabular-nums ${total > 0 ? tone : 'text-muted'}`}>{total}</span>
      </div>
      {rows.length === 0 ? (
        <p className="text-sm text-muted">{emptyText}</p>
      ) : (
        <ul className="space-y-1.5">
          {rows.map((it) => (
            <li key={it.id} className="flex items-baseline justify-between gap-2 text-sm">
              <span className="min-w-0 truncate">{it.name}</span>
              <span className="shrink-0 tabular-nums text-muted">
                <span className={tone}>{qty(it.quantity)}</span> / {qty(it.reorder_level)} {it.unit}
              </span>
            </li>
          ))}
          {total > rows.length && <li className="text-xs text-muted">+{total - rows.length} more on Inventory</li>}
        </ul>
      )}
    </div>
  )
}

export function InventoryMonitor({ inventory }) {
  const topUsed = Math.max(...inventory.most_used.map((u) => u.quantity), 1)
  return (
    <Card className="@container h-full">
      <Card.Body className="flex flex-wrap items-end justify-between gap-3 border-b border-line">
        <div>
          <div className="text-xs font-medium uppercase tracking-[0.04em] text-muted">Items tracked</div>
          <div className="sv-serif mt-1 text-xl font-bold tabular-nums">{inventory.items}</div>
        </div>
        <div className="flex gap-2">
          {inventory.out_of_stock_count > 0 && <Badge bg="danger">{inventory.out_of_stock_count} out</Badge>}
          {inventory.low_stock_count > 0 && <Badge bg="warning">{inventory.low_stock_count} low</Badge>}
          {inventory.out_of_stock_count + inventory.low_stock_count === 0 && <Badge bg="success">All stocked</Badge>}
        </div>
      </Card.Body>
      <Card.Body className="grid gap-5 border-b border-line @md:grid-cols-2">
        <StockList title="Out of stock" rows={inventory.out_of_stock} total={inventory.out_of_stock_count}
          tone="text-red-600 dark:text-red-400" emptyText="Nothing is out of stock." />
        <StockList title="Low stock" rows={inventory.low_stock} total={inventory.low_stock_count}
          tone="text-amber-600 dark:text-amber-400" emptyText="Nothing at or below its reorder level." />
      </Card.Body>
      <Card.Body>
        <div className="mb-2 text-xs font-medium uppercase tracking-[0.04em] text-muted">Most used · 7 days</div>
        {inventory.most_used.length === 0 ? (
          <p className="text-sm text-muted">No stock went out this week.</p>
        ) : (
          <ul className="space-y-2">
            {inventory.most_used.map((u) => (
              <li key={u.name} className="text-sm">
                <div className="flex items-baseline justify-between gap-2">
                  <span className="min-w-0 truncate">{u.name}</span>
                  <span className="shrink-0 tabular-nums text-muted">{qty(u.quantity)} {u.unit}</span>
                </div>
                <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-subtle">
                  <div className="h-full rounded-full bg-ink/60" style={{ width: `${(u.quantity / topUsed) * 100}%` }} />
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card.Body>
    </Card>
  )
}

/* ----------------------------------------------------------------- Staff */

function activityText(e) {
  if (e.type === 'stock') {
    const sign = e.direction === 'in' ? '+' : '−'
    return `${sign}${qty(e.quantity)} ${e.unit ?? ''} ${e.item ?? 'item'}${e.reason ? ` · ${e.reason}` : ''}`
  }
  const where = e.room ? ` · Room ${e.room}` : ''
  const state = e.status === 'cancelled' ? 'cancelled' : (ORDER_PAY[e.payment_status]?.label ?? e.payment_status)
  return `Order #${e.order_id}${where} · ${formatMoney(e.total)} · ${state}`
}

// One feed entry: who, what, when. Shared by the card and the full list.
function ActivityItem({ e }) {
  return (
    <ListGroup.Item className="px-4 py-2">
      <div className="flex items-baseline justify-between gap-2">
        <span className="min-w-0 truncate font-medium">{e.actor ?? 'Unknown'}</span>
        <span className="shrink-0 text-xs tabular-nums text-muted">{when(e.at)}</span>
      </div>
      <div className="truncate text-muted" title={activityText(e)}>{activityText(e)}</div>
    </ListGroup.Item>
  )
}

const dayLabel = (iso) => {
  const d = new Date(iso)
  const y = new Date()
  y.setDate(y.getDate() - 1)
  if (d.toDateString() === new Date().toDateString()) return 'Today'
  if (d.toDateString() === y.toDateString()) return 'Yesterday'
  return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
}

// "View all activity": the whole feed, 25 at a time, grouped by day. The list
// scrolls inside the dialog, so the dialog stays one screen tall however far
// back someone reads.
function ActivityModal({ onHide }) {
  const [pages, setPages] = useState([])
  const [hasMore, setHasMore] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const fetchPage = useCallback((page) => {
    setBusy(true)
    return staffActivity(page)
      .then((r) => {
        setPages((p) => [...p.slice(0, page - 1), r.activity])
        setHasMore(r.has_more)
        setError(null)
      })
      .catch((err) => setError(err?.response?.data?.message ?? 'Could not load the activity.'))
      .finally(() => setBusy(false))
  }, [])

  useEffect(() => {
    const t = setTimeout(() => fetchPage(1), 0)
    return () => clearTimeout(t)
  }, [fetchPage])

  const events = pages.flat()
  const days = []
  for (const e of events) {
    const label = dayLabel(e.at)
    if (days.length === 0 || days[days.length - 1].label !== label) days.push({ label, items: [] })
    days[days.length - 1].items.push(e)
  }

  return (
    <Modal show onHide={onHide} size="lg">
      <Modal.Header closeButton>
        <Modal.Title>Staff activity</Modal.Title>
        <p className="mb-0 text-xs text-muted">Stock movements and food orders, with who recorded each.</p>
      </Modal.Header>
      <div className="max-h-[65vh] overflow-y-auto">
        {error && <div className="p-4"><Alert variant="danger" className="mb-0">{error}</Alert></div>}
        {!error && events.length === 0 && (busy ? <SkeletonTable rows={6} /> : <Empty>No activity recorded yet.</Empty>)}
        {days.map((d) => (
          <section key={d.label}>
            <h3 className="sticky top-0 z-10 border-y border-line bg-subtle px-4 py-1.5 text-xs font-semibold uppercase tracking-[0.04em] text-muted">
              {d.label}
            </h3>
            <ListGroup>
              {d.items.map((e) => (
                <ListGroup.Item key={e.id} className="flex items-baseline gap-3 px-4 py-2">
                  <span className="w-16 shrink-0 text-xs tabular-nums text-muted">
                    {new Date(e.at).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}
                  </span>
                  <span className="w-32 shrink-0 truncate font-medium">{e.actor ?? 'Unknown'}</span>
                  <span className="min-w-0 flex-1 truncate text-muted" title={activityText(e)}>{activityText(e)}</span>
                </ListGroup.Item>
              ))}
            </ListGroup>
          </section>
        ))}
      </div>
      <Modal.Footer className="justify-between">
        <span className="text-xs text-muted">{events.length > 0 && `Showing ${events.length}`}</span>
        <div className="flex gap-2">
          {hasMore && (
            <Button size="sm" variant="outline-secondary" disabled={busy} onClick={() => fetchPage(pages.length + 1)}>
              {busy ? <Spinner size="sm" /> : 'Load older'}
            </Button>
          )}
          <Button size="sm" variant="secondary" onClick={onHide}>Close</Button>
        </div>
      </Modal.Footer>
    </Modal>
  )
}

// Admin only. Activity comes from the two ledgers that record who did each
// thing (stock movements, food orders) — see API.md for why reservations
// aren't in it.
//
// Fixed-height by design: the card fills whatever height its grid slot gives
// it (`h-full`, from the Dashboard's side column) and scrolls inside, so a busy
// day's feed or a long staff list never stretches the row or pushes the
// sections below down. Activity and the staff list share one scroll area
// behind a toggle rather than stacking.
export function StaffMonitor({ staff, activity, className = '' }) {
  const [view, setView] = useState('activity')
  const [showAll, setShowAll] = useState(false)
  return (
    <Card className={`flex flex-col overflow-hidden ${className}`}>
      <Card.Header className="flex shrink-0 items-center justify-between gap-2">
        <div className="min-w-0 text-xs font-normal text-muted">
          {staff.active} active · {staff.active_today} busy today
        </div>
        <ButtonGroup>
          <Button size="sm" variant={view === 'activity' ? 'secondary' : 'outline-secondary'}
            onClick={() => setView('activity')}>
            Activity
          </Button>
          <Button size="sm" variant={view === 'staff' ? 'secondary' : 'outline-secondary'}
            onClick={() => setView('staff')}>
            Team ({staff.members.length})
          </Button>
        </ButtonGroup>
      </Card.Header>

      <div className="min-h-0 flex-1 overflow-y-auto">
        {view === 'activity' ? (
          activity.length === 0 ? (
            <Empty>No stock movements or orders recorded yet.</Empty>
          ) : (
            <ListGroup>
              {activity.map((e) => <ActivityItem key={e.id} e={e} />)}
            </ListGroup>
          )
        ) : staff.members.length === 0 ? (
          <Empty>No active staff accounts.</Empty>
        ) : (
          <ListGroup>
            {staff.members.map((m) => (
              <ListGroup.Item key={m.id} className="flex items-center gap-3 px-4 py-2">
                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-subtle text-xs font-semibold">
                  {m.name.trim().charAt(0).toUpperCase()}
                </span>
                <span className="min-w-0 flex-1 truncate">{m.name}</span>
                <Badge bg={m.role === 'admin' ? 'primary' : 'secondary'}>{m.role}</Badge>
                <span className="w-16 shrink-0 text-right text-xs tabular-nums text-muted"
                  title="Stock movements and food orders recorded today">
                  {plural(m.actions_today, 'action')}
                </span>
              </ListGroup.Item>
            ))}
          </ListGroup>
        )}
      </div>

      {view === 'activity' && activity.length > 0 && (
        <Card.Footer className="shrink-0 py-2 text-center">
          <Button size="sm" variant="link" onClick={() => setShowAll(true)}>View all activity →</Button>
        </Card.Footer>
      )}
      {showAll && <ActivityModal onHide={() => setShowAll(false)} />}
    </Card>
  )
}


