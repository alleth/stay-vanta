import { roleLabel } from './roles'

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
  sign_in_refused: 'Sign-in refused',
  signed_out: 'Signed out',
  session_ended: 'Session ended',
  membership_granted: 'Given a role at the property',
  membership_imported: 'Role at the property on record (imported)',
  membership_role_changed: 'Role changed',
  platform_access_granted: 'Platform access on record',
  support_access_started: 'Support access started',
  support_access_used: 'Opened during support access',
  support_access_ended: 'Support access ended',
}

// Events that call for a second look, shown with a warning tint.
export const ACCESS_WARNINGS = new Set(['sign_in_failed', 'sign_in_locked', 'sign_in_refused'])

export const accessLabel = (event) => ACCESS_LABELS[event] ?? event

// Why a session ended, from the event that ended it.
const ENDED_BY = {
  account_deactivated: 'the account was deactivated',
  password_reset: 'the password was reset',
  password_changed: 'the password was changed',
  membership_role_changed: 'the role changed',
}

/** The extra line an event carries ("Ended the session on another device"). */
export function accessDetail(e) {
  if (e.event === 'signed_in' && e.changes?.ended_other_session) return 'Ended the session on another device'
  if (e.event === 'session_ended' && e.changes?.because) return `Because ${ENDED_BY[e.changes.because] ?? e.changes.because}`
  if (e.event === 'account_renamed' && e.changes?.name) return `${e.changes.name.before} → ${e.changes.name.after}`
  if (e.event === 'sign_in_refused') return e.changes?.because === 'no_membership' ? 'No role at any property' : 'Account inactive'
  if (e.event === 'membership_role_changed' && e.changes?.role) return `${roleLabel(e.changes.role.before)} → ${roleLabel(e.changes.role.after)}`
  if (e.event === 'support_access_used' && e.changes?.path) return `${e.changes.method} ${e.changes.path}`
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
