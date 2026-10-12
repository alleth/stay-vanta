// Permission names as screens say them (Phase 3b role editor, Settings → Roles,
// role history). The module part names the screen it opens.
export const MODULES = {
  operations: 'Operations', finance: 'Finance', front_desk: 'Front Desk', guests: 'Guests', pos: 'POS',
  rooms: 'Rooms', inventory: 'Inventory', settings: 'Settings', staff: 'Staff', platform: 'Platform',
}

export const moduleLabel = (module) => MODULES[module] ?? module

// "finance.invoice.refund" → "invoice · refund" (the module is shown as its group).
export const permissionAction = (permission) =>
  permission.split('.').slice(1).join(' · ').replaceAll('_', ' ')

// "finance.invoice.refund" → "Finance: invoice · refund".
export const permissionLabel = (permission) =>
  permission ? `${moduleLabel(permission.split('.')[0])}: ${permissionAction(permission)}` : ''

// Who holds a role: "0 memberships" · "5 memberships at Sunrise Resort" ·
// "23 memberships across 4 properties" (Phase 3b role list, editor, preview).
export function membershipsText(m) {
  if (!m || m.total === 0) return '0 memberships'
  const props = m.properties?.length ?? 0
  const people = `${m.total} membership${m.total === 1 ? '' : 's'}`
  if (props > 1) return `${people} across ${props} properties`
  return props === 1 ? `${people} at ${m.properties[0].name}` : people
}
