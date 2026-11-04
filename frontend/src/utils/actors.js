// Who did it, worded the same on every history screen (UI-004).
//
// A name the data recorded always wins: imported POS sales keep the person who
// placed them, and refunds from before 7c can carry who wrote them. With no
// name, `recorded === false` means the row was imported from before its ledger
// existed (source 'import'), so nobody is named and none is invented; a
// recorded row whose person can't be found is "Unknown user".
import { actorRoleLabel } from './roles'

export const BEFORE_RECORDING = 'Before recording began'
export const UNKNOWN_USER = 'Unknown user'

/** Standalone: "Ana Reyes" · "Before recording began" · "Unknown user". */
export function actorLabel(name, recorded) {
  if (name) return name
  return recorded === false ? BEFORE_RECORDING : UNKNOWN_USER
}

/** After a verb: "by Ana Reyes" · "before recording began" · "by an unknown user". */
export function byActor(name, recorded) {
  if (name) return `by ${name}`
  return recorded === false ? 'before recording began' : 'by an unknown user'
}

/** An event's person with their role at the time: "Ana Reyes (Manager)". */
export function actorWithRole(event) {
  const label = actorLabel(event.actor, event.recorded)
  return event.actor_role && event.recorded !== false ? `${label} (${actorRoleLabel(event)})` : label
}
