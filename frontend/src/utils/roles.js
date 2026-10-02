// Role display names. The stored values (owner / admin / receptionist) never
// change; every screen shows these names instead (see CLAUDE.md, terminology).
export const ROLE_LABELS = {
  owner: 'Platform Owner',
  admin: 'Manager',
  receptionist: 'Front Desk Staff',
}

export const roleLabel = (role) => ROLE_LABELS[role] ?? role

// Room status display names; the stored values stay available / occupied / maintenance.
export const ROOM_STATUS_LABELS = {
  available: 'Vacant',
  occupied: 'Occupied',
  maintenance: 'Maintenance',
}

export const roomStatusLabel = (status) => ROOM_STATUS_LABELS[status] ?? status

// Reservation billing states, read from the invoice (billing_state from the API).
export const BILLING_STATE = {
  not_billed: { label: 'Not billed', bg: 'secondary' },
  billed: { label: 'Billed', bg: 'warning' },
  settled: { label: 'Settled', bg: 'success' },
}
