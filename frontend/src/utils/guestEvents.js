// Words for guest events (final review G3): the guest's history and
// Operations → Activity describe them the same way.

export const GUEST_EVENT_LABELS = {
  registered: 'Registered',
  registered_despite_matches: 'Registered despite look-alike guests',
  details_updated: 'Details changed',
  renamed: 'Renamed',
  details_completed: 'Details filled in from a booking',
  imported: 'On record when guest history began',
}

const VIA = { guests: 'from Guests', reservation: 'from a reservation', walk_in: 'from a walk-in booking' }

const FIELD_LABELS = {
  full_name: 'Name',
  nationality: 'Nationality',
  address: 'Address',
  contact_number: 'Phone',
  email: 'Email',
  guest_type: 'Type',
}

export const guestEventLabel = (event) => GUEST_EVENT_LABELS[event] ?? event

const show = (v) => (v === null || v === undefined || v === '' ? '—' : v)

/** The lines an event carries: where it came from, each field's before → after, the matches it overrode. */
export function guestEventDetails(e) {
  const c = e.changes ?? {}
  const lines = []
  if (c.via) lines.push(VIA[c.via] ?? c.via)
  if (Array.isArray(c.matches) && c.matches.length) {
    lines.push(`Look-alikes: ${c.matches.map((m) => `${m.name} #${m.guest_id}`).join(', ')}`)
  }
  for (const [field, label] of Object.entries(FIELD_LABELS)) {
    const v = c[field]
    if (v && typeof v === 'object' && ('before' in v || 'after' in v)) {
      lines.push(e.event === 'details_completed' ? `${label}: ${show(v.after)}` : `${label}: ${show(v.before)} → ${show(v.after)}`)
    }
  }
  if (e.event === 'imported' && e.snapshot?.changed_since_registration) {
    lines.push('Changed after registration by someone not recorded')
  }
  return lines
}
