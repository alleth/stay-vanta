import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Tab, Tabs, Card, Table, Button, Badge, Alert,
} from '../components/ui'
import { useProperty } from '../context/PropertyContext'
import { useAuth } from '../context/AuthContext'
import { P } from '../auth/permissions'
import { formatMoney } from '../utils/format'
import { SkeletonTable } from '../components/Skeleton'
import { roomStatusLabel } from '../utils/roles'
import { roomLabel, sourceLabel } from '../utils/bookingLabels'
import {
  listRooms, deleteRoom, listRoomRates, listBookingSources, listPromoRates, deletePromoRate,
  listExtraCharges, deleteExtraCharge,
} from '../api/frontdesk'
import { configChanges } from '../api/settings'
import { describeError } from '../utils/apiError'
import ReasonModal from '../components/ReasonModal'
import {
  RoomModal, RateModal, PromoRateModal, ChargeModal,
} from '../components/settings/ConfigModals'
import ChangeLog from '../components/settings/ChangeLog'

// Settings (build step 9): the property's configuration — rooms, room rates,
// promo rates, extra charges and booking sources — and the change log that
// records every change to it. A Manager's module: daily work stays in the
// other modules (Front Desk sets a room's status; POS keeps the menu; Finance
// the receipt booklets), and every price shows where it came from.

const ROOM_VARIANT = { available: 'success', occupied: 'danger', maintenance: 'warning' }
const fmtDate = (s) => (s ? new Date(s).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '')

// Where a row's current price came from: its latest price change (or its
// creation, or what was on record when changes started being recorded).
function PriceSource({ change }) {
  if (!change) return <span className="text-muted">—</span>
  if (change.event === 'baseline_recorded') {
    return <span className="text-muted">On record since {fmtDate(change.at)} · earlier changes weren&apos;t recorded</span>
  }
  const what = change.event === 'created' ? 'Set when added' : 'Changed'
  return (
    <span>
      {what} {fmtDate(change.at)}
      {change.actor && <span className="text-muted"> · {change.actor}</span>}
      {change.reason && <span className="block text-muted">“{change.reason}”</span>}
    </span>
  )
}

const DELETE_TEXT = {
  room: (r) => ({
    title: `Delete room ${r.room_number}`,
    description: 'The room leaves every list. Its reservations and history are kept, and the change log keeps its values.',
    run: (reason) => deleteRoom(r.id, reason),
  }),
  promo: (r, sources) => ({
    title: `Delete the ${sourceLabel(sources, r.source)} promo rate`,
    description: 'New bookings from this source use the room rate. Bookings already made keep their rate.',
    run: (reason) => deletePromoRate(r.id, reason),
  }),
  charge: (r) => ({
    title: `Delete “${r.name}”`,
    description: 'It can no longer be added to a booking. Bookings that already have it keep it.',
    run: (reason) => deleteExtraCharge(r.id, reason),
  }),
}

export default function Settings() {
  const { propertyId } = useProperty()
  const { can } = useAuth()
  const canManageRooms = can(P.SETTINGS_ROOM_MANAGE)
  const canManageRates = can(P.SETTINGS_ROOM_RATE_MANAGE)
  const canManagePromos = can(P.SETTINGS_PROMO_RATE_MANAGE)
  const canManageCharges = can(P.SETTINGS_EXTRA_CHARGE_MANAGE)
  const canSeeLog = can(P.SETTINGS_CHANGE_LOG_VIEW)

  const [tab, setTab] = useState('rooms')
  const [rooms, setRooms] = useState([])
  const [rates, setRates] = useState([])
  const [bookingSources, setBookingSources] = useState([])
  const [promoRates, setPromoRates] = useState([])
  const [extraCharges, setExtraCharges] = useState([])
  // Latest price change per row, by entity type then id.
  const [priceSources, setPriceSources] = useState({})
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [modal, setModal] = useState(null) // { type: 'room'|'rate'|'promo'|'charge', row? }
  const [deleting, setDeleting] = useState(null) // { kind, row }

  const refresh = useCallback(async () => {
    if (!propertyId) return
    try {
      const latest = (type) => (canSeeLog
        ? configChanges(propertyId, { entity_type: type, impact: 'price', latest: 1 }).then((r) => r.changes)
        : Promise.resolve([]))
      const [rm, rt, bs, pr, ec, rateLog, promoLog, chargeLog] = await Promise.all([
        listRooms(propertyId), listRoomRates(propertyId), listBookingSources(propertyId),
        listPromoRates(propertyId), listExtraCharges(propertyId),
        latest('room_rate'), latest('promo_rate'), latest('extra_charge'),
      ])
      const byId = (list) => Object.fromEntries(list.map((c) => [c.entity_id, c]))
      setRooms(rm)
      setRates(rt)
      setBookingSources(bs)
      setPromoRates(pr)
      setExtraCharges(ec)
      setPriceSources({ room_rate: byId(rateLog), promo_rate: byId(promoLog), extra_charge: byId(chargeLog) })
      setError(null)
    } catch (ex) {
      setError(describeError(ex, 'Could not load the settings.'))
    } finally {
      setLoading(false)
    }
  }, [propertyId, canSeeLog])

  useEffect(() => {
    // State updates occur after the awaited fetch; safe data effect.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    refresh()
  }, [refresh])

  const roomName = useCallback(
    (id) => rooms.find((r) => r.id === Number(id))?.room_number ?? `#${id}`,
    [rooms],
  )
  const deleteText = useMemo(
    () => (deleting ? DELETE_TEXT[deleting.kind](deleting.row, bookingSources) : null),
    [deleting, bookingSources],
  )

  if (!propertyId) return <Alert variant="info">Select a property to see its settings.</Alert>

  const saved = () => { setModal(null); refresh() }
  const source = (type, id) => <PriceSource change={priceSources[type]?.[id]} />

  return (
    <div>
      <h1 className="mb-4 text-2xl font-bold">Settings</h1>
      {error && <Alert variant="danger" onClose={() => setError(null)} dismissible>{error}</Alert>}

      {loading ? <SkeletonTable rows={5} /> : (
        <Tabs activeKey={tab} onSelect={setTab} className="mb-4">
          {/* ---- Rooms: what they are; whether they're occupied is Front Desk's ---- */}
          <Tab eventKey="rooms" title={`Rooms (${rooms.length})`}>
            {canManageRooms && (
              <div className="mb-2 flex justify-end">
                <Button onClick={() => setModal({ type: 'room' })}>Add room</Button>
              </div>
            )}
            <Card>
              <Table hover>
                <thead>
                  <tr>
                    <th>Room</th><th>Type</th><th>Status now</th>
                    {canManageRooms && <th className="text-right">Actions</th>}
                  </tr>
                </thead>
                <tbody>
                  {rooms.length === 0 && (
                    <tr><td colSpan={4} className="py-6 text-center text-muted">No rooms yet.</td></tr>
                  )}
                  {rooms.map((room) => (
                    <tr key={room.id}>
                      <td className="font-semibold">{room.room_number}</td>
                      <td>{room.room_type ?? '—'}</td>
                      <td><Badge bg={ROOM_VARIANT[room.status]}>{roomStatusLabel(room.status)}</Badge></td>
                      {canManageRooms && (
                        <td className="whitespace-nowrap text-right">
                          <Button size="sm" variant="outline-primary" className="mr-1"
                            onClick={() => setModal({ type: 'room', row: room })}>Edit</Button>
                          <Button size="sm" variant="outline-danger"
                            onClick={() => setDeleting({ kind: 'room', row: room })}>Delete</Button>
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </Table>
            </Card>
            <p className="mt-2 mb-0 text-sm text-muted">
              A room&apos;s status (vacant, occupied, maintenance) is set at the Front Desk and isn&apos;t a setting.
            </p>
          </Tab>

          {/* ---- Room rates, with where each current price came from ---- */}
          <Tab eventKey="rates" title={`Rates (${rates.length})`}>
            {canManageRates && (
              <div className="mb-2 flex justify-end">
                <Button onClick={() => setModal({ type: 'rate' })}>Add rate</Button>
              </div>
            )}
            <Card>
              <Table hover>
                <thead>
                  <tr>
                    <th>Applies to</th><th>Amenities &amp; bed</th>
                    <th className="text-right">Nightly rate</th>
                    {canSeeLog && <th>Price set</th>}
                    {canManageRates && <th className="text-right">Actions</th>}
                  </tr>
                </thead>
                <tbody>
                  {rates.length === 0 && (
                    <tr><td colSpan={5} className="py-6 text-center text-muted">No rates yet.</td></tr>
                  )}
                  {rates.map((rt) => (
                    <tr key={rt.id}>
                      <td className="font-semibold">{rt.room ? `Room ${rt.room.room_number}` : 'All rooms'}</td>
                      <td className="max-w-[280px] text-xs text-muted">{rt.description || '—'}</td>
                      <td className="text-right tabular-nums">{formatMoney(rt.base_rate)}</td>
                      {canSeeLog && <td className="text-xs">{source('room_rate', rt.id)}</td>}
                      {canManageRates && (
                        <td className="text-right">
                          <Button size="sm" variant="outline-primary"
                            onClick={() => setModal({ type: 'rate', row: rt })}>Edit</Button>
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </Table>
            </Card>
            <p className="mt-2 mb-0 text-sm text-muted">
              A booking&apos;s nightly rate is fixed when it&apos;s made. Changing a rate here applies to new
              bookings, and to a booking whose room or source is changed; it asks why.
            </p>
          </Tab>

          {/* ---- Promo rates (OTA multipliers) ---- */}
          <Tab eventKey="promo-rates" title={`Promo rates (${promoRates.length})`}>
            {canManagePromos && (
              <div className="mb-2 flex justify-end">
                <Button onClick={() => setModal({ type: 'promo' })}>Add promo rate</Button>
              </div>
            )}
            <Card>
              <Table hover>
                <thead>
                  <tr>
                    <th>Source</th><th>Applies to</th><th className="text-right">Rate multiplier</th>
                    {canSeeLog && <th>Price set</th>}
                    {canManagePromos && <th className="text-right">Actions</th>}
                  </tr>
                </thead>
                <tbody>
                  {promoRates.length === 0 && (
                    <tr><td colSpan={5} className="py-6 text-center text-muted">No promo rates yet.</td></tr>
                  )}
                  {promoRates.map((pr) => (
                    <tr key={pr.id}>
                      <td className="font-semibold">{sourceLabel(bookingSources, pr.source)}</td>
                      <td>{pr.room ? roomLabel(pr.room) : 'All rooms'}</td>
                      <td className="text-right tabular-nums">×{Number(pr.multiplier)}</td>
                      {canSeeLog && <td className="text-xs">{source('promo_rate', pr.id)}</td>}
                      {canManagePromos && (
                        <td className="whitespace-nowrap text-right">
                          <Button size="sm" variant="outline-primary" className="mr-1"
                            onClick={() => setModal({ type: 'promo', row: pr })}>Edit</Button>
                          <Button size="sm" variant="outline-danger"
                            onClick={() => setDeleting({ kind: 'promo', row: pr })}>Delete</Button>
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </Table>
            </Card>
            <p className="mt-2 mb-0 text-sm text-muted">
              A promo rate is a <strong>multiple of the room&apos;s original rate</strong> for one booking source
              (×2 doubles it); a room-specific one wins over &quot;All rooms&quot;. Typing a new source&apos;s name
              adds it to the booking form&apos;s Source list.
            </p>
          </Tab>

          {/* ---- Extra charges ---- */}
          <Tab eventKey="charges" title={`Extra charges (${extraCharges.length})`}>
            <div className="mb-2 flex items-center justify-between gap-3">
              <p className="mb-0 text-sm text-muted">
                Active charges (e.g. Extra bed) can be added to a reservation under Pricing.
                Early check-in is billed when the guest checks in before noon; set it to 0 to turn it off.
              </p>
              {canManageCharges && (
                <Button className="shrink-0" onClick={() => setModal({ type: 'charge' })}>Add charge</Button>
              )}
            </div>
            <Card>
              <Table hover>
                <thead>
                  <tr>
                    <th>Charge</th><th className="text-right">Amount</th><th>Status</th>
                    {canSeeLog && <th>Price set</th>}
                    {canManageCharges && <th className="text-right">Actions</th>}
                  </tr>
                </thead>
                <tbody>
                  {extraCharges.length === 0 && (
                    <tr><td colSpan={5} className="py-6 text-center text-muted">No extra charges yet.</td></tr>
                  )}
                  {extraCharges.map((c) => (
                    <tr key={c.id}>
                      <td className="font-semibold">
                        {c.name}
                        {c.code && <Badge bg="info" className="ml-2 font-normal">built-in</Badge>}
                      </td>
                      <td className="text-right tabular-nums">{formatMoney(c.amount)}</td>
                      <td><Badge bg={c.is_active ? 'success' : 'secondary'}>{c.is_active ? 'active' : 'inactive'}</Badge></td>
                      {canSeeLog && <td className="text-xs">{source('extra_charge', c.id)}</td>}
                      {canManageCharges && (
                        <td className="whitespace-nowrap text-right">
                          <Button size="sm" variant="outline-primary" className="mr-1"
                            onClick={() => setModal({ type: 'charge', row: c })}>Edit</Button>
                          {!c.code && (
                            <Button size="sm" variant="outline-danger"
                              onClick={() => setDeleting({ kind: 'charge', row: c })}>Delete</Button>
                          )}
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </Table>
            </Card>
          </Tab>

          {/* ---- Booking sources: read-only, created by promo-rate saves ---- */}
          <Tab eventKey="sources" title={`Booking sources (${bookingSources.length})`}>
            <Card>
              <Table hover>
                <thead>
                  <tr><th>Source</th><th>Code</th><th>Promo rates</th></tr>
                </thead>
                <tbody>
                  <tr>
                    <td className="font-semibold">Walk-in</td>
                    <td className="font-mono text-xs">walk_in</td>
                    <td className="text-muted">Built in: guests who arrive without a booking.</td>
                  </tr>
                  {bookingSources.map((bs) => {
                    const promos = promoRates.filter((p) => p.source === bs.code)
                    return (
                      <tr key={bs.id}>
                        <td className="font-semibold">{bs.name}</td>
                        <td className="font-mono text-xs">{bs.code}</td>
                        <td>
                          {promos.length === 0
                            ? <span className="text-muted">none (books at the room rate)</span>
                            : promos.map((p) => `×${Number(p.multiplier)} ${p.room ? `Room ${p.room.room_number}` : 'all rooms'}`).join(' · ')}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </Table>
            </Card>
            <p className="mt-2 mb-0 text-sm text-muted">
              A source is added the first time a promo rate names it, and its code never changes (bookings keep it).
            </p>
          </Tab>

          {canSeeLog && (
            <Tab eventKey="log" title="Change log">
              <ChangeLog propertyId={propertyId} roomName={roomName} />
            </Tab>
          )}
        </Tabs>
      )}

      {modal?.type === 'room' && (
        <RoomModal propertyId={propertyId} room={modal.row} canSeeHistory={canSeeLog}
          onClose={() => setModal(null)} onSaved={saved} />
      )}
      {modal?.type === 'rate' && (
        <RateModal rooms={rooms} propertyId={propertyId} rate={modal.row} canSeeHistory={canSeeLog}
          onClose={() => setModal(null)} onSaved={saved} />
      )}
      {modal?.type === 'promo' && (
        <PromoRateModal rooms={rooms} bookingSources={bookingSources} propertyId={propertyId}
          promoRate={modal.row} canSeeHistory={canSeeLog}
          onClose={() => setModal(null)} onSaved={saved} />
      )}
      {modal?.type === 'charge' && (
        <ChargeModal charge={modal.row} propertyId={propertyId} canSeeHistory={canSeeLog}
          onClose={() => setModal(null)} onSaved={saved} />
      )}
      <ReasonModal show={deleting !== null}
        title={deleteText?.title}
        description={deleteText?.description}
        confirmLabel="Delete"
        onHide={() => setDeleting(null)}
        onConfirm={async (reason) => {
          await deleteText.run(reason)
          setDeleting(null)
          await refresh()
        }} />
    </div>
  )
}
