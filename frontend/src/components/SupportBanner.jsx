import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Button, Spinner } from './ui'
import { useAuth } from '../context/AuthContext'
import { endOwnSupport } from '../api/platform'
import { endSupportSession } from '../api/staff'
import { describeError } from '../utils/apiError'

const time = (at) => new Date(at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })

/**
 * Support access (step 10b, A6), on every page under the header:
 * - the Platform Owner, during their session: which property, read-only,
 *   until when, and a way out;
 * - the property's Manager, while one is open there: who, why, until when,
 *   and a way to end it.
 * When a session runs out the server simply stops honouring it; the banner
 * re-reads who they are at that moment so the screens follow.
 */
export default function SupportBanner() {
  const { user, refresh } = useAuth()
  const navigate = useNavigate()
  const [busy, setBusy] = useState(null)
  const [error, setError] = useState(null)
  const own = user?.support ?? null
  const open = user?.support_active ?? []

  const nextExpiry = own?.expires_at ?? open[0]?.expires_at ?? null
  useEffect(() => {
    if (!nextExpiry) return undefined
    const ms = new Date(nextExpiry).getTime() - Date.now() + 1000
    const timer = setTimeout(() => {
      refresh().then((me) => { if (own && !me.support) navigate('/hub') }).catch(() => {})
    }, Math.max(ms, 1000))
    return () => clearTimeout(timer)
  }, [nextExpiry, own, refresh, navigate])

  if (!own && open.length === 0) return null

  async function end(id, action) {
    setBusy(id)
    setError(null)
    try {
      await action(id)
    } catch (ex) {
      // Already ended or expired: the refresh below shows where things stand.
      setError(describeError(ex, 'Could not end the support session.'))
    }
    try {
      const me = await refresh()
      if (own && !me.support) navigate('/hub')
    } finally {
      setBusy(null)
    }
  }

  const bar = 'border-b border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900 lg:px-8 '
    + 'dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200'

  return (
    <div className={bar} role="status">
      {own && (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
          <span>
            <strong>Support access</strong> to {own.property_name ?? 'this property'}, read-only until{' '}
            {time(own.expires_at)}. Every page you open is recorded and shown to the property&apos;s Manager.
          </span>
          <Button size="sm" variant="outline-secondary" className="ml-auto" disabled={busy !== null}
            onClick={() => end(own.id, endOwnSupport)}>
            {busy === own.id ? <Spinner size="sm" /> : 'End support access'}
          </Button>
        </div>
      )}
      {open.map((s) => (
        <div key={s.id} className="flex flex-wrap items-center gap-x-3 gap-y-1">
          <span>
            <strong>Support access</strong> by {s.by ?? 'the Platform Owner'} until {time(s.expires_at)}
            {s.reason ? <> · &ldquo;{s.reason}&rdquo;</> : null}. They can view this property&apos;s data
            but not change it; every page they open is listed under Staff → Security.
          </span>
          <Button size="sm" variant="outline-secondary" className="ml-auto" disabled={busy !== null}
            onClick={() => end(s.id, endSupportSession)}>
            {busy === s.id ? <Spinner size="sm" /> : 'End it now'}
          </Button>
        </div>
      ))}
      {error && <div className="mt-1">{error.message}</div>}
    </div>
  )
}
