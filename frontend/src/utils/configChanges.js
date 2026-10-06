import { formatMoney } from './format'

// Words for configuration changes (build step 9): the Settings change log,
// each row's history, a booking's price view and Operations → Activity all
// describe a change the same way.

export const ENTITY_LABELS = {
  room_rate: 'Room rate',
  promo_rate: 'Promo rate',
  extra_charge: 'Extra charge',
  booking_source: 'Booking source',
  room: 'Room',
  receipt_series: 'Receipt booklet',
  menu_item: 'Menu item',
  inventory_category: 'Inventory category',
  inventory_item: 'Inventory item',
  property: 'Property',
}

export const EVENT_LABELS = {
  created: 'Added',
  updated: 'Changed',
  deleted: 'Deleted',
  baseline_recorded: 'On record when the audit began',
}

// Impact: why a change matters, highest first (a change takes its most
// important field's).
export const IMPACTS = {
  price: { label: 'Price', variant: 'warning' },
  booking: { label: 'Booking', variant: 'info' },
  operational: { label: 'Operational', variant: 'secondary' },
  administrative: { label: 'Administrative', variant: 'secondary' },
}

const FIELD_LABELS = {
  base_rate: 'Nightly rate',
  room_id: 'Applies to',
  description: 'Amenities & bed',
  multiplier: 'Multiplier',
  source: 'Source',
  amount: 'Amount',
  is_active: 'Active',
  name: 'Name',
  code: 'Code',
  room_number: 'Room number',
  room_type: 'Room type',
  price: 'Price',
  is_available: 'Available',
  type: 'Type',
  inventory_item_id: 'Linked stock',
  prefix: 'Prefix',
  start_number: 'First number',
  end_number: 'Last number',
  pad_length: 'Digits',
  kind: 'Kind',
  parent_id: 'Parent',
  unit: 'Unit',
  reorder_level: 'Low-stock threshold',
  inventory_category_id: 'Category',
  tracking_type: 'Stock type',
  deleted_at: 'Deleted on',
  subscription_fee: 'Subscription fee',
  subscription_status: 'Subscription',
  subscription_expires_at: 'Subscription ends',
  recipe: 'Recipe',
  options: 'Options',
  option_prices: 'Option prices',
}

const MONEY = new Set(['base_rate', 'amount', 'price', 'subscription_fee'])
// What a creation or baseline shows of the row: the fields that say what it is.
const SUMMARY_FIELDS = ['base_rate', 'multiplier', 'amount', 'price', 'subscription_fee', 'room_type', 'is_active']

export const entityLabel = (type) => ENTITY_LABELS[type] ?? type
export const eventLabel = (event) => EVENT_LABELS[event] ?? event
export const fieldLabel = (field) => FIELD_LABELS[field] ?? field.replaceAll('_', ' ')

/** One value as people read it. `roomName(id)` names a room. */
export function formatValue(field, value, roomName = (id) => `#${id}`) {
  if (value === null || value === undefined || value === '') {
    return field === 'room_id' ? 'All rooms' : '—'
  }
  if (MONEY.has(field)) return formatMoney(value)
  if (field === 'multiplier') return `×${Number(value)}`
  if (field === 'room_id') return `Room ${roomName(value)}`
  if (typeof value === 'boolean') return value ? 'yes' : 'no'
  if (Array.isArray(value)) return value.length === 0 ? 'none' : `${value.length} item(s)`
  if (typeof value === 'object') return 'changed'
  return String(value)
}

/**
 * Lines describing a change: "Nightly rate: ₱1,000.00 → ₱1,200.00" for an
 * update; the row's main values for a creation, deletion or baseline.
 */
export function changeLines(change, roomName) {
  const changes = change.changes ?? {}
  const whole = changes.after ?? changes.before
  if (whole && (change.event !== 'updated')) {
    return SUMMARY_FIELDS.filter((f) => f in whole)
      .map((f) => `${fieldLabel(f)}: ${formatValue(f, whole[f], roomName)}`)
  }
  return Object.entries(changes)
    .filter(([, c]) => c && typeof c === 'object' && 'before' in c)
    .map(([field, c]) => {
      const nested = typeof c.before === 'object' || typeof c.after === 'object'
      return nested && !Array.isArray(c.before) && !Array.isArray(c.after)
        ? `${fieldLabel(field)} changed`
        : `${fieldLabel(field)}: ${formatValue(field, c.before, roomName)} → ${formatValue(field, c.after, roomName)}`
    })
}

/** The row a change is about: "Room rate “Standard”". */
export const subjectText = (change) =>
  `${entityLabel(change.entity_type)}${change.label ? ` “${change.label}”` : ''}`

/** Who did it, as a line can say it: unknown before the audit began. */
export function actorText(change, showsActors = true) {
  if (change.recorded === false) return 'Not recorded'
  if (!showsActors) return null
  return change.actor ?? (change.source === 'system' ? 'System' : 'Unknown user')
}

/** True when the API refused a save because a reason was missing (a form then asks for one). */
export const asksForReason = (err) => /reason/i.test(err?.message ?? '')
