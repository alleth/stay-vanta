import { useEffect, useState } from 'react'
import { Alert, Badge } from './ui'
import { Skeleton } from './Skeleton'
import { reservationPrice } from '../api/frontdesk'
import { describeError } from '../utils/apiError'
import { formatMoney } from '../utils/format'
import { actorText, changeLines, eventLabel, subjectText } from '../utils/configChanges'

// A reservation's price, explained (build step 9): what it is, where its
// nightly rate came from, what discounts and charges make it up, how it
// changed, which rate changes came after it and whether they moved it, and
// what was billed. Front Desk Staff see all of it except who changed the
// configuration (decided 2026-10-05: guest-facing explanation for the desk,
// accountability for Managers); the API leaves that name out for them.

const PRICE_EVENTS = {
  booked: 'Booked',
  walked_in: 'Walk-in checked in',
  backdated: 'Past stay entered',
  edited: 'Edited',
  corrected: 'Corrected',
  discount_changed: 'Discount changed',
}

const fmtDate = (s) => (s ? new Date(s).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '—')
const fmtWhen = (s) => (s ? new Date(s).toLocaleString() : '—')

function rateName(source, rates, roomName) {
  const rate = rates.find((r) => r.id === source?.room_rate_id)
  if (source?.scope === 'room') return `the Room ${rate?.room?.room_number ?? roomName(rate?.room_id)} rate`
  return 'the all-rooms rate'
}

// One sentence: where the nightly rate comes from.
function rateOrigin(data, rates, roomName) {
  const src = data.rate_source
  const nightly = data.current.nightly_rate
  if (data.basis === 'live' || !src) {
    return data.basis === 'promo'
      ? `${formatMoney(nightly)} a night: the channel's promo rate, set before rates were recorded with each booking.`
      : `${formatMoney(nightly)} a night from today's room rate: this booking was made before rates were fixed at booking, `
        + 'so it follows the current rate until its room charge is posted.'
  }
  const base = `${formatMoney(src.base_rate)} (${rateName(src, rates, roomName)})`
  if (data.basis === 'promo') {
    return `Fixed on ${fmtDate(src.resolved_at)}: ${base} × ${Number(src.multiplier)} channel promo `
      + `= ${formatMoney(nightly)} a night. Later rate changes don't affect it.`
  }
  return `Fixed on ${fmtDate(src.resolved_at)} at ${base} a night. Later rate changes don't affect it.`
}

function Row({ label, value, minus, strong }) {
  return (
    <div className={`flex justify-between gap-3 ${strong ? 'border-t border-line pt-1 font-semibold' : ''}`}>
      <span className={strong ? '' : 'text-muted'}>{label}</span>
      <span className="tabular-nums">{minus ? `− ${formatMoney(value)}` : formatMoney(value)}</span>
    </div>
  )
}

export default function ReservationPrice({ reservationId, rooms = [], rates = [] }) {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    reservationPrice(reservationId)
      .then(setData)
      .catch((ex) => setError(describeError(ex, 'Could not load the price.')))
  }, [reservationId])

  const roomName = (id) => rooms.find((r) => Number(r.id) === Number(id))?.room_number ?? `#${id}`
  const q = data?.current

  return (
    <section className="mt-6 border-t border-line pt-4">
      <h3 className="mb-3 text-sm font-semibold uppercase tracking-[0.04em] text-muted">Price</h3>
      {error && <Alert variant="danger">{error}</Alert>}
      {!data && !error && (
        <div className="space-y-2">
          <Skeleton className="h-4 w-1/2" />
          <Skeleton className="h-4 w-2/3" />
        </div>
      )}
      {data && (
        <div className="grid grid-cols-1 gap-6 text-sm md:grid-cols-2">
          <div>
            <div className="space-y-1">
              <Row label={`${q.nights} night(s) × ${formatMoney(q.nightly_rate)}`} value={q.subtotal} />
              {q.channel_discount > 0 && <Row label="Channel discount" value={q.channel_discount} minus />}
              {q.statutory_discount > 0 && <Row label="Senior/PWD discount" value={q.statutory_discount} minus />}
              {q.referral_discount > 0 && <Row label="Referral discount" value={q.referral_discount} minus />}
              {q.extras > 0 && <Row label="Extra charges" value={q.extras} />}
              <Row label="Total" value={q.total} strong />
            </div>
            <p className="mt-3 mb-0 text-xs">
              <Badge bg={data.basis === 'live' ? 'warning' : 'secondary'} className="mr-1">
                {data.basis === 'live' ? 'Live rate' : data.basis === 'promo' ? 'Promo, fixed' : 'Fixed at booking'}
              </Badge>
              {rateOrigin(data, rates, roomName)}
            </p>
            {data.posted.length > 0 && (
              <div className="mt-3">
                <div className="text-xs font-semibold text-muted">Billed</div>
                {data.posted.map((l) => (
                  <div key={l.id} className="flex justify-between gap-3 text-xs">
                    <span className="min-w-0 truncate">{l.description}</span>
                    <span className="tabular-nums">{formatMoney(l.amount)}</span>
                  </div>
                ))}
              </div>
            )}
          </div>

          <div className="space-y-4">
            {data.history.length > 0 && (
              <div>
                <div className="mb-1 text-xs font-semibold text-muted">How the price was set</div>
                <ol className="m-0 list-none space-y-1 p-0">
                  {data.history.map((h, i) => {
                    const before = data.history[i - 1]?.price
                    const moved = before && Number(before.total) !== Number(h.price.total)
                    return (
                      <li key={`${h.event}-${h.at}`} className="border-l-2 border-line pl-3 text-xs">
                        <span className="font-semibold">{PRICE_EVENTS[h.event] ?? h.event}</span>
                        {' · '}{formatMoney(h.price.total)}
                        <span className="text-muted"> ({formatMoney(h.price.nightly_rate)} a night)</span>
                        {moved && <span className="text-muted"> · was {formatMoney(before.total)}</span>}
                        <div className="text-muted">{actorText(h) ?? '—'} · {fmtWhen(h.at)}</div>
                        {h.reason && <div>Reason: {h.reason}</div>}
                      </li>
                    )
                  })}
                </ol>
              </div>
            )}

            <div>
              <div className="mb-1 text-xs font-semibold text-muted">Rate changes since it was booked</div>
              {data.config_changes.length === 0 ? (
                <p className="mb-0 text-xs text-muted">None.</p>
              ) : (
                <ol className="m-0 list-none space-y-1 p-0">
                  {data.config_changes.map((c) => {
                    const who = actorText(c, data.shows_actors)
                    return (
                      <li key={c.id}
                        className={`border-l-2 pl-3 text-xs ${c.moved_this_price ? 'border-accent' : 'border-line'}`}>
                        <span className="font-semibold">{eventLabel(c.event)} · {subjectText(c)}</span>
                        {changeLines(c, roomName).map((l) => <div key={l} className="text-muted">{l}</div>)}
                        {c.reason && <div>Reason: {c.reason}</div>}
                        <div className="text-muted">{[who, fmtWhen(c.at)].filter(Boolean).join(' · ')}</div>
                        <div className={c.moved_this_price ? 'font-semibold' : 'text-muted'}>
                          {c.moved_this_price ? 'Changed this booking’s price. ' : ''}{c.why}
                        </div>
                      </li>
                    )
                  })}
                </ol>
              )}
            </div>

            {data.notes.map((n) => <p key={n} className="mb-0 text-xs text-muted">{n}</p>)}
          </div>
        </div>
      )}
    </section>
  )
}
