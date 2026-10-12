import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import client, { getToken, onPermissionsVersion, setToken } from '../api/client'

const ACCESS_CHANGED = 'Your access changed. Some screens are no longer available.'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  // Only "loading" when there's a token to resolve on boot.
  const [loading, setLoading] = useState(() => !!getToken())

  // On boot, if a token exists, resolve the current user.
  useEffect(() => {
    if (!getToken()) return
    client
      .get('/auth/me')
      .then((res) => setUser(res.data.user))
      .catch(() => setToken(null))
      .finally(() => setLoading(false))
  }, [])

  async function login(email, password) {
    const res = await client.post('/auth/login', { email, password })
    setToken(res.data.token)
    setUser(res.data.user)
    return res.data.user
  }

  // Re-read who they are: after a support session starts or ends (step 10b)
  // their property and permissions change without a new sign-in.
  const refresh = useCallback(async () => {
    const res = await client.get('/auth/me')
    setUser(res.data.user)
    return res.data.user
  }, [])

  // Phase 3c-2 (C-D3): when a response says their permissions changed (a role
  // edit, a subscription stage, a support session), or a request is refused,
  // re-read /auth/me once. Nobody is signed out; if something was taken away
  // the Hub says so, and ProtectedRoute leaves a screen they can't open now.
  const [accessNotice, setAccessNotice] = useState(null)
  const userRef = useRef(null)
  const refreshing = useRef(false)
  const lastRefusalRefresh = useRef(0)
  useEffect(() => {
    userRef.current = user
  }, [user])
  useEffect(() => onPermissionsVersion((version, refused) => {
    const current = userRef.current
    if (!current || refreshing.current) return
    const changed = version !== current.permissions_version
    // A refusal with nothing changed re-checks at most every 10 seconds.
    if (!changed && !(refused && Date.now() - lastRefusalRefresh.current > 10000)) return
    if (refused) lastRefusalRefresh.current = Date.now()
    refreshing.current = true
    client.get('/auth/me')
      .then((res) => {
        const next = res.data.user
        const lost = (current.permissions ?? []).some((p) => !(next.permissions ?? []).includes(p))
        if (lost) setAccessNotice(ACCESS_CHANGED)
        setUser(next)
      })
      .catch(() => {})
      .finally(() => { refreshing.current = false })
  }), [])

  function logout() {
    setToken(null)
    setUser(null)
    setAccessNotice(null)
  }

  const role = user?.role ?? null
  // What this person may do (docs/PERMISSIONS.md), as the server sent it.
  // Nothing is guessed from the role (G8 removed that fallback).
  const granted = useMemo(
    () => new Set(user?.permissions ?? []),
    [user],
  )
  const value = {
    user, loading, login, logout, refresh, role,
    // Set when a refresh took something away (Phase 3c-2); the Hub shows it.
    accessNotice, clearAccessNotice: () => setAccessNotice(null),
    can: (permission) => granted.has(permission),
    // Where they work, which a permission never implies: the platform (the
    // Platform Owner, bound to no property) or one property. Screens check
    // both: scope picks platform vs hotel screens, can() what's on them.
    scope: user ? (user.property_id == null ? 'platform' : 'property') : null,
  }
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within AuthProvider')
  return ctx
}
