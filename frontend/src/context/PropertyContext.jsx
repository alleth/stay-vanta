import { createContext, useContext, useEffect, useState } from 'react'
import { useAuth } from './AuthContext'
import { listProperties } from '../api/inventory'

const PropertyContext = createContext(null)
const STORAGE_KEY = 'stayvanta_property'

/**
 * Tracks the "active property" used by property-scoped modules.
 * - Staff (admin/receptionist) are bound to their own property_id.
 * - The owner picks one; the choice is remembered in localStorage.
 */
export function PropertyProvider({ children }) {
  const { user } = useAuth()
  const bound = user?.property_id ?? null
  const [properties, setProperties] = useState([])
  // Owners pick a property; staff are bound, so the effective id below
  // prefers `bound` and falls back to the owner's chosen/stored one.
  const [chosen, setChosen] = useState(() => Number(localStorage.getItem(STORAGE_KEY)) || null)
  const propertyId = bound ?? chosen

  useEffect(() => {
    // Only owners (not bound to a property) need the picker list.
    if (!user || bound) return
    listProperties()
      .then((list) => {
        setProperties(list)
        setChosen((cur) => cur ?? list[0]?.id ?? null)
      })
      .catch(() => setProperties([]))
  }, [user, bound])

  function choose(id) {
    setChosen(id)
    if (id) localStorage.setItem(STORAGE_KEY, String(id))
  }

  const value = { propertyId, properties, choose, isOwner: bound === null }
  return <PropertyContext.Provider value={value}>{children}</PropertyContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useProperty() {
  const ctx = useContext(PropertyContext)
  if (!ctx) throw new Error('useProperty must be used within PropertyProvider')
  return ctx
}
