// Names for booking sources and rooms, shared by Front Desk and Settings.

export const WALK_IN = 'walk_in'

/** A source code as people read it: "Walk-in", or the source's name. */
export const sourceLabel = (bookingSources, code) =>
  (code === WALK_IN ? 'Walk-in' : bookingSources.find((s) => s.code === code)?.name ?? code)

export const roomLabel = (r) => `Room ${r.room_number} — ${r.room_type ?? 'Room'}`
