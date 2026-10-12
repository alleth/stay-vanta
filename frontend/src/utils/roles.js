// Role display names. The stored values (owner / admin / receptionist) never
// change; every screen shows these names instead (see CLAUDE.md, terminology).
export const ROLE_LABELS = {
  owner: 'Platform Owner',
  admin: 'Manager',
  receptionist: 'Front Desk Staff',
}

// A role as the API gives it: a code (older events, the Platform Owner's
// `owner`), or since Phase 3c-2 `{id, code, name}`. A name from the server
// always wins, so custom roles show their own name, never a code.
export const roleLabel = (role) => {
  if (role && typeof role === 'object') return role.name ?? ROLE_LABELS[role.code] ?? role.code
  return ROLE_LABELS[role] ?? role
}

// An actor's role on a history line: the name the API resolved (custom roles
// included), else the built-in label.
export const actorRoleLabel = (event) => event?.actor_role_name ?? roleLabel(event?.actor_role)

// Room status display names; the stored values stay available / occupied / maintenance.
export const ROOM_STATUS_LABELS = {
  available: 'Vacant',
  occupied: 'Occupied',
  maintenance: 'Maintenance',
  out_of_service: 'Out of service',
}

export const roomStatusLabel = (status) => ROOM_STATUS_LABELS[status] ?? status

// Room service availability (G4); stored values stay in_service / maintenance / out_of_service.
export const ROOM_SERVICE_LABELS = {
  in_service: 'In service',
  maintenance: 'Maintenance',
  out_of_service: 'Out of service',
}

export const roomServiceLabel = (status) => ROOM_SERVICE_LABELS[status] ?? status ?? '—'

// Reservation billing states, read from the invoice (billing_state from the API).
export const BILLING_STATE = {
  not_billed: { label: 'Not billed', bg: 'secondary' },
  billed: { label: 'Billed', bg: 'warning' },
  settled: { label: 'Settled', bg: 'success' },
}
