// Words for access events (build step 10): your own sign-ins, a staff
// member's history and Operations → Activity describe them the same way.

export const ACCESS_LABELS = {
  account_created: 'Account created',
  account_renamed: 'Renamed',
  account_deactivated: 'Account deactivated',
  account_reactivated: 'Account reactivated',
  password_reset: 'Password reset',
  password_changed: 'Password changed',
  signed_in: 'Signed in',
  sign_in_failed: 'Failed sign-in (wrong password)',
  sign_in_locked: 'Sign-in paused after repeated failures',
  sign_in_refused: 'Sign-in refused (account inactive)',
  signed_out: 'Signed out',
  session_ended: 'Session ended',
}

// Events that call for a second look, shown with a warning tint.
export const ACCESS_WARNINGS = new Set(['sign_in_failed', 'sign_in_locked', 'sign_in_refused'])

export const accessLabel = (event) => ACCESS_LABELS[event] ?? event

// Why a session ended, from the event that ended it.
const ENDED_BY = {
  account_deactivated: 'the account was deactivated',
  password_reset: 'the password was reset',
  password_changed: 'the password was changed',
}

/** The extra line an event carries ("Ended the session on another device"). */
export function accessDetail(e) {
  if (e.event === 'signed_in' && e.changes?.ended_other_session) return 'Ended the session on another device'
  if (e.event === 'session_ended' && e.changes?.because) return `Because ${ENDED_BY[e.changes.because] ?? e.changes.because}`
  if (e.event === 'account_renamed' && e.changes?.name) return `${e.changes.name.before} → ${e.changes.name.after}`
  return null
}

/** Who acted, as a line says it. */
export function accessActor(e) {
  if (e.recorded === false) return 'Not recorded'
  if (!e.proven) return 'Someone (not signed in)'
  if (e.self) return 'You'
  return e.actor ?? (e.source === 'system' ? 'System' : 'Unknown user')
}

// A user agent, roughly as people name it: "Chrome on Windows".
export function deviceName(agent) {
  if (!agent) return null
  const browser = /Edg\//.test(agent) ? 'Edge'
    : /OPR\//.test(agent) ? 'Opera'
      : /Firefox\//.test(agent) ? 'Firefox'
        : /Chrome\//.test(agent) ? 'Chrome'
          : /Safari\//.test(agent) ? 'Safari'
            : null
  const os = /Android/.test(agent) ? 'Android'
    : /iPhone|iPad/.test(agent) ? 'iOS'
      : /Windows/.test(agent) ? 'Windows'
        : /Mac OS X/.test(agent) ? 'macOS'
          : /Linux/.test(agent) ? 'Linux'
            : null
  if (browser && os) return `${browser} on ${os}`
  return browser ?? os ?? 'Unknown device'
}
